<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderAlert;
use App\Models\TenderClarification;
use App\Models\TenderMilestone;
use App\Services\Tender\TenderDeadlineEngineService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenderMilestoneController extends Controller
{
    public function __construct(
        protected TenderDeadlineEngineService $deadlineService
    ) {}

    /**
     * Get milestones schedule and tender milestone stats.
     */
    public function index(int $tenderId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $schedule = $this->deadlineService->getMilestonesSchedule($tender);
        $clarifications = TenderClarification::where('tender_id', $tender->id)
            ->with('createdBy:id,name')
            ->orderByDesc('submitted_or_received_at')
            ->get();

        return response()->json([
            'tender' => [
                'id' => $tender->id,
                'reference_number' => $tender->reference_number,
                'title' => $tender->title,
                'closing_at' => $tender->closing_at,
                'clarification_deadline' => $tender->clarification_deadline,
                'site_visit_required' => $tender->site_visit_required,
                'site_visit_at' => $tender->site_visit_at,
                'site_visit_location' => $tender->site_visit_location,
                'internal_deadline' => $tender->internal_deadline,
                'status' => $tender->status,
            ],
            'schedule' => $schedule,
            'clarifications' => $clarifications,
        ]);
    }

    /**
     * Create a custom or specific milestone.
     */
    public function store(int $tenderId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to add milestones.'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:180',
            'type' => 'required|string|max:50',
            'due_at' => 'required|date',
            'description' => 'nullable|string|max:1000',
            'venue_or_location' => 'nullable|string|max:255',
            'responsible_employee_id' => 'nullable|exists:employees,id',
            'reminder_lead_times' => 'nullable|array',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $milestone = TenderMilestone::create([
            'tenant_id' => $tenantId,
            'tender_id' => $tender->id,
            'type' => $request->type,
            'title' => $request->title,
            'description' => $request->description,
            'venue_or_location' => $request->venue_or_location,
            'due_at' => Carbon::parse($request->due_at),
            'status' => Carbon::parse($request->due_at)->isPast() ? 'missed' : 'pending',
            'responsible_employee_id' => $request->responsible_employee_id ?: $tender->assigned_employee_id,
            'reminder_lead_times' => $request->reminder_lead_times ?: ['7d', '3d', '24h', '2h'],
            'reminders_sent' => [],
        ]);

        return response()->json([
            'message' => 'Milestone created successfully.',
            'milestone' => $milestone,
        ], 201);
    }

    /**
     * Update milestone or mark completed.
     */
    public function update(int $milestoneId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to modify milestones.'], 403);
        }

        $request->validate([
            'title' => 'sometimes|required|string|max:180',
            'description' => 'nullable|string|max:1000',
            'venue_or_location' => 'nullable|string|max:255',
            'due_at' => 'sometimes|required|date',
            'status' => 'sometimes|required|string|in:pending,completed,at_risk,missed',
            'responsible_employee_id' => 'nullable|exists:employees,id',
            'reminder_lead_times' => 'nullable|array',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $milestone = TenderMilestone::where('tenant_id', $tenantId)->findOrFail($milestoneId);

        $attributes = $request->only([
            'title', 'description', 'venue_or_location', 'status', 'responsible_employee_id', 'reminder_lead_times',
        ]);

        if ($request->has('due_at')) {
            $attributes['due_at'] = Carbon::parse($request->due_at);
        }

        if ($request->status === 'completed' && $milestone->status !== 'completed') {
            $attributes['completed_at'] = now();
            $attributes['completed_by_user_id'] = auth()->id();
        } elseif ($request->has('status') && $request->status !== 'completed') {
            $attributes['completed_at'] = null;
            $attributes['completed_by_user_id'] = null;
        }

        $milestone->update($attributes);

        return response()->json([
            'message' => 'Milestone updated successfully.',
            'milestone' => $milestone->fresh(['responsibleEmployee', 'completedBy']),
        ]);
    }

    /**
     * Delete milestone.
     */
    public function destroy(int $milestoneId): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to delete milestones.'], 403);
        }

        $tenantId = auth()->user()->tenant_id;
        $milestone = TenderMilestone::where('tenant_id', $tenantId)->findOrFail($milestoneId);
        $milestone->delete();

        return response()->json(['message' => 'Milestone deleted successfully.']);
    }

    /**
     * Generate default standard milestones.
     */
    public function generateDefaults(int $tenderId): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to generate default milestones.'], 403);
        }

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $milestones = $this->deadlineService->generateDefaultMilestones($tender);

        return response()->json([
            'message' => 'Standard milestone schedule initialized.',
            'milestones' => $milestones,
        ]);
    }

    /**
     * Record a clarification request or received addendum.
     */
    public function storeClarification(int $tenderId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to log clarifications.'], 403);
        }

        $request->validate([
            'type' => 'required|string|in:clarification_request,addendum_received',
            'reference_or_number' => 'required|string|max:80',
            'subject' => 'required|string|max:255',
            'question_or_content' => 'required|string|max:4000',
            'response_or_amendment' => 'nullable|string|max:4000',
            'submitted_or_received_at' => 'required|date',
            'response_due_at' => 'nullable|date',
            'status' => 'required|string|in:draft,submitted,answered,acknowledged',
            'affects_closing_date' => 'nullable|boolean',
            'new_closing_date' => 'nullable|date',
            'affects_pricing' => 'nullable|boolean',
            'affects_technical_specs' => 'nullable|boolean',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $tender = Tender::where('tenant_id', $tenantId)->findOrFail($tenderId);

        $clarification = $this->deadlineService->recordClarificationOrAddendum(
            $tender,
            $request->all(),
            auth()->id()
        );

        return response()->json([
            'message' => 'Clarification/Addendum recorded successfully.',
            'clarification' => $clarification,
            'tender' => $tender->fresh(),
        ], 201);
    }

    /**
     * Update clarification or addendum.
     */
    public function updateClarification(int $clarificationId, Request $request): JsonResponse
    {
        if (! auth()->user()->canDo('tenders.manage')) {
            return response()->json(['message' => 'Unauthorized to update clarifications.'], 403);
        }

        $request->validate([
            'response_or_amendment' => 'nullable|string|max:4000',
            'response_received_at' => 'nullable|date',
            'status' => 'sometimes|required|string|in:draft,submitted,answered,acknowledged',
            'affects_closing_date' => 'nullable|boolean',
            'new_closing_date' => 'nullable|date',
            'affects_pricing' => 'nullable|boolean',
            'affects_technical_specs' => 'nullable|boolean',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $clarification = TenderClarification::where('tenant_id', $tenantId)->findOrFail($clarificationId);

        $clarification->update($request->all());

        return response()->json([
            'message' => 'Clarification updated successfully.',
            'clarification' => $clarification,
        ]);
    }

    /**
     * List in-app alerts for the current tenant.
     */
    public function getAlerts(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $alerts = TenderAlert::where('tenant_id', $tenantId)
            ->with(['tender:id,reference_number,title', 'milestone:id,title,due_at'])
            ->latest('id')
            ->limit(50)
            ->get();

        $unreadCount = TenderAlert::where('tenant_id', $tenantId)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'unread_count' => $unreadCount,
            'alerts' => $alerts,
        ]);
    }

    /**
     * Mark single alert as read.
     */
    public function markAlertRead(int $alertId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $alert = TenderAlert::where('tenant_id', $tenantId)->findOrFail($alertId);

        $alert->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json(['message' => 'Alert marked as read.']);
    }

    /**
     * Mark all alerts as read.
     */
    public function markAllAlertsRead(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        TenderAlert::where('tenant_id', $tenantId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['message' => 'All alerts marked as read.']);
    }

    /**
     * Manually trigger scan and dispatch alerts (also used by background scheduler).
     */
    public function dispatchAlerts(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $count = $this->deadlineService->checkAndDispatchAlerts($tenantId);

        return response()->json([
            'message' => "Deadline scan completed. {$count} new alert(s) dispatched.",
            'alerts_dispatched' => $count,
        ]);
    }
}

