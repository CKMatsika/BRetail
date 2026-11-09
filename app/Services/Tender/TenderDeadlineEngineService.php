<?php

namespace App\Services\Tender;

use App\Models\Employee;
use App\Models\Tender;
use App\Models\TenderAlert;
use App\Models\TenderClarification;
use App\Models\TenderMilestone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class TenderDeadlineEngineService
{
    /**
     * Generate standard Zimbabwean procurement milestone schedule for a tender.
     */
    public function generateDefaultMilestones(Tender $tender): Collection
    {
        $tenantId = $tender->tenant_id;
        $closing = Carbon::parse($tender->closing_at);
        $leadTimes = ['7d', '3d', '24h', '2h'];

        $defaultDefs = [
            [
                'type' => 'submission_closing',
                'title' => 'Tender Submission Deadline & Bid Opening',
                'description' => 'Final statutory submission deadline on ' . ($tender->submission_method === 'electronic_portal' ? 'PRAZ eGP portal' : 'physical tender box') . '.',
                'due_at' => $closing->copy(),
                'venue_or_location' => $tender->submission_address ?: $tender->procuring_entity,
            ],
            [
                'type' => 'pricing_signoff',
                'title' => 'Executive Commercial & Pricing Sign-Off',
                'description' => 'Final review and CFO / Managing Director authorization of financial schedule, discounts, and currency breakdown.',
                'due_at' => $closing->copy()->subDays(2)->setTime(16, 0),
                'venue_or_location' => 'Head Office / Finance Department',
            ],
            [
                'type' => 'technical_review',
                'title' => 'Internal Technical Review & Compliance Verification',
                'description' => 'Lead engineer and compliance officer review of clause-by-clause technical schedule and deviations.',
                'due_at' => $closing->copy()->subDays(4)->setTime(14, 0),
                'venue_or_location' => 'Technical Operations Department',
            ],
            [
                'type' => 'internal_draft',
                'title' => 'First Draft Proposal & Document Compilation',
                'description' => 'Compilation of all standard returnable documents, schedules, forms, and technical specifications.',
                'due_at' => $tender->internal_deadline
                    ? Carbon::parse($tender->internal_deadline)
                    : $closing->copy()->subDays(7)->setTime(17, 0),
                'venue_or_location' => 'Bid Preparation Studio',
            ],
        ];

        // Clarification Deadline
        $clarificationDate = $tender->clarification_deadline
            ? Carbon::parse($tender->clarification_deadline)
            : $closing->copy()->subDays(10)->setTime(16, 0);

        $defaultDefs[] = [
            'type' => 'clarification_deadline',
            'title' => 'Procuring Entity Clarification Deadline',
            'description' => 'Statutory cut-off date to submit formal requests for clarification or addenda under PPDPA Section 38.',
            'due_at' => $clarificationDate,
            'venue_or_location' => $tender->contact_email ?: $tender->procuring_entity,
        ];

        // Mandatory Site Visit if applicable
        if ($tender->site_visit_required) {
            $siteVisitDate = $tender->site_visit_at
                ? Carbon::parse($tender->site_visit_at)
                : $closing->copy()->subDays(12)->setTime(10, 0);

            $defaultDefs[] = [
                'type' => 'site_visit',
                'title' => 'Mandatory Pre-Bid Conference / Site Inspection',
                'description' => 'Attendance by qualified technical personnel. Ensure signed site inspection certificate is obtained.',
                'due_at' => $siteVisitDate,
                'venue_or_location' => $tender->site_visit_location ?: $tender->procuring_entity . ' Site',
            ];
        }

        // Bid Security Expiry (typically 90 days after closing)
        if ($tender->bid_security_required) {
            $defaultDefs[] = [
                'type' => 'bid_security_expiry',
                'title' => 'Bid Security Bond Validity Expiry',
                'description' => 'Required bank guarantee / security bond validity period (90 days post closing).',
                'due_at' => $closing->copy()->addDays(90)->setTime(16, 0),
                'venue_or_location' => 'Issuing Financial Institution',
            ];
        }

        // Tender Validity Expiry (typically 90 days after closing)
        $defaultDefs[] = [
            'type' => 'tender_validity_expiry',
            'title' => 'Tender Offer Validity Expiry',
            'description' => 'Firm bid price and offer validity period (90 days from closing under standard PRAZ conditions).',
            'due_at' => $closing->copy()->addDays(90)->setTime(16, 0),
            'venue_or_location' => $tender->procuring_entity,
        ];

        // Insert or update milestones without duplicating existing types
        foreach ($defaultDefs as $def) {
            TenderMilestone::updateOrCreate(
                [
                    'tender_id' => $tender->id,
                    'type' => $def['type'],
                ],
                [
                    'tenant_id' => $tenantId,
                    'title' => $def['title'],
                    'description' => $def['description'],
                    'venue_or_location' => $def['venue_or_location'],
                    'due_at' => $def['due_at'],
                    'status' => $def['due_at']->isPast() ? 'missed' : 'pending',
                    'responsible_employee_id' => $tender->assigned_employee_id,
                    'reminder_lead_times' => $leadTimes,
                    'reminders_sent' => [],
                ]
            );
        }

        return TenderMilestone::where('tender_id', $tender->id)->orderBy('due_at')->get();
    }

    /**
     * Get structured milestones schedule with live dynamic status indicators.
     */
    public function getMilestonesSchedule(Tender $tender): array
    {
        $milestones = TenderMilestone::where('tender_id', $tender->id)
            ->with(['responsibleEmployee', 'completedBy'])
            ->orderBy('due_at')
            ->get();

        if ($milestones->isEmpty()) {
            $milestones = $this->generateDefaultMilestones($tender);
        }

        $now = now();
        $items = [];
        $completedCount = 0;
        $atRiskCount = 0;
        $missedCount = 0;
        $pendingCount = 0;
        $nextMilestone = null;

        foreach ($milestones as $m) {
            $computedStatus = $m->status;
            $hoursLeft = (int) $now->diffInHours($m->due_at, false);

            if ($m->status === 'completed') {
                $computedStatus = 'completed';
                $completedCount++;
            } elseif ($m->due_at->isPast()) {
                $computedStatus = 'missed';
                $missedCount++;
            } elseif ($hoursLeft <= 48) {
                $computedStatus = 'at_risk';
                $atRiskCount++;
                if (! $nextMilestone) {
                    $nextMilestone = $m;
                }
            } else {
                $computedStatus = 'pending';
                $pendingCount++;
                if (! $nextMilestone) {
                    $nextMilestone = $m;
                }
            }

            $items[] = [
                'id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'description' => $m->description,
                'venue_or_location' => $m->venue_or_location,
                'due_at' => $m->due_at->toIso8601String(),
                'status' => $computedStatus,
                'is_completed' => ($computedStatus === 'completed'),
                'is_at_risk' => ($computedStatus === 'at_risk'),
                'is_missed' => ($computedStatus === 'missed'),
                'completed_at' => $m->completed_at?->toIso8601String(),
                'completed_by' => $m->completedBy ? ['id' => $m->completedBy->id, 'name' => $m->completedBy->name] : null,
                'responsible_employee' => $m->responsibleEmployee ? [
                    'id' => $m->responsibleEmployee->id,
                    'name' => $m->responsibleEmployee->preferred_name ?: $m->responsibleEmployee->legal_name,
                    'designation' => $m->responsibleEmployee->job_title,
                ] : null,
                'hours_remaining' => $hoursLeft,
                'reminder_lead_times' => $m->reminder_lead_times ?: ['7d', '3d', '24h', '2h'],
                'reminders_sent' => $m->reminders_sent ?: [],
            ];
        }

        return [
            'total' => count($items),
            'completed_count' => $completedCount,
            'at_risk_count' => $atRiskCount,
            'missed_count' => $missedCount,
            'pending_count' => $pendingCount,
            'next_milestone' => $nextMilestone ? [
                'id' => $nextMilestone->id,
                'title' => $nextMilestone->title,
                'due_at' => $nextMilestone->due_at->toIso8601String(),
                'hours_remaining' => (int) $now->diffInHours($nextMilestone->due_at, false),
            ] : null,
            'milestones' => $items,
        ];
    }

    /**
     * Record a formal clarification request or addendum from procuring entity.
     */
    public function recordClarificationOrAddendum(Tender $tender, array $data, int $userId): TenderClarification
    {
        $tenantId = $tender->tenant_id;

        $record = TenderClarification::create([
            'tenant_id' => $tenantId,
            'tender_id' => $tender->id,
            'type' => $data['type'] ?? 'clarification_request',
            'reference_or_number' => $data['reference_or_number'],
            'subject' => $data['subject'],
            'question_or_content' => $data['question_or_content'],
            'response_or_amendment' => $data['response_or_amendment'] ?? null,
            'submitted_or_received_at' => $data['submitted_or_received_at'] ?? now(),
            'response_due_at' => $data['response_due_at'] ?? null,
            'response_received_at' => $data['response_received_at'] ?? null,
            'status' => $data['status'] ?? 'submitted',
            'affects_closing_date' => (bool) ($data['affects_closing_date'] ?? false),
            'new_closing_date' => $data['new_closing_date'] ?? null,
            'affects_pricing' => (bool) ($data['affects_pricing'] ?? false),
            'affects_technical_specs' => (bool) ($data['affects_technical_specs'] ?? false),
            'attachment_path' => $data['attachment_path'] ?? null,
            'attachment_name' => $data['attachment_name'] ?? null,
            'created_by_user_id' => $userId,
        ]);

        // If addendum changes the tender closing date, recalibrate tender and milestone schedules!
        if ($record->affects_closing_date && $record->new_closing_date) {
            $newClosing = Carbon::parse($record->new_closing_date);
            $tender->closing_at = $newClosing;
            $tender->save();

            // Shift submission_closing milestone
            $closingMilestone = TenderMilestone::where('tender_id', $tender->id)
                ->where('type', 'submission_closing')
                ->first();

            if ($closingMilestone) {
                $closingMilestone->update([
                    'due_at' => $newClosing,
                    'status' => $newClosing->isPast() ? 'missed' : 'pending',
                ]);
            } else {
                TenderMilestone::create([
                    'tenant_id' => $tenantId,
                    'tender_id' => $tender->id,
                    'type' => 'submission_closing',
                    'title' => 'Tender Submission Deadline & Bid Opening',
                    'description' => 'Final statutory submission deadline (updated via addendum).',
                    'venue_or_location' => $tender->submission_address ?: $tender->procuring_entity,
                    'due_at' => $newClosing,
                    'status' => $newClosing->isPast() ? 'missed' : 'pending',
                    'responsible_employee_id' => $tender->assigned_employee_id,
                    'reminder_lead_times' => ['7d', '3d', '24h', '2h'],
                    'reminders_sent' => [],
                ]);
            }

            // Record high-priority alert
            TenderAlert::create([
                'tenant_id' => $tenantId,
                'tender_id' => $tender->id,
                'level' => 'warning',
                'title' => 'Tender Closing Date Extended',
                'message' => 'Procuring entity issued ' . $record->reference_or_number . ' extending tender closing date to ' . $newClosing->format('d M Y H:i') . '.',
                'lead_time' => 'addendum',
                'channel' => 'in_app',
                'sent_at' => now(),
            ]);
        }

        return $record;
    }

    /**
     * Scan all pending milestones across active tenders and trigger reminders / in-app alerts.
     */
    public function checkAndDispatchAlerts(?int $tenantId = null): int
    {
        $query = TenderMilestone::query()
            ->where('status', '!=', 'completed')
            ->whereHas('tender', function ($q) {
                $q->whereIn('status', ['draft', 'evaluating_go_no_go', 'active_bidding']);
            });

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $milestones = $query->with('tender')->get();
        $alertsGenerated = 0;
        $now = now();

        foreach ($milestones as $m) {
            $tender = $m->tender;
            if (! $tender) {
                continue;
            }

            $hoursUntilDue = $now->diffInHours($m->due_at, false);
            $remindersSent = $m->reminders_sent ?: [];
            $leadTimes = $m->reminder_lead_times ?: ['7d', '3d', '24h', '2h'];

            // 1. Check 7 days (168 hours)
            if ($hoursUntilDue <= 168 && $hoursUntilDue > 72 && in_array('7d', $leadTimes) && ! in_array('7d', $remindersSent)) {
                $this->createAlert($m, 'info', '7d', 'Milestone Due in 7 Days: ' . $m->title, 'Tender ' . $tender->reference_number . ' milestone "' . $m->title . '" is due on ' . $m->due_at->format('d M Y H:i') . '.');
                $remindersSent[] = '7d';
                $alertsGenerated++;
            }

            // 2. Check 3 days (72 hours)
            if ($hoursUntilDue <= 72 && $hoursUntilDue > 24 && in_array('3d', $leadTimes) && ! in_array('3d', $remindersSent)) {
                $this->createAlert($m, 'warning', '3d', 'Milestone Due in 3 Days: ' . $m->title, 'Tender ' . $tender->reference_number . ' milestone "' . $m->title . '" requires immediate action before ' . $m->due_at->format('d M Y H:i') . '.');
                $remindersSent[] = '3d';
                $alertsGenerated++;
            }

            // 3. Check 24 hours
            if ($hoursUntilDue <= 24 && $hoursUntilDue > 2 && in_array('24h', $leadTimes) && ! in_array('24h', $remindersSent)) {
                $this->createAlert($m, 'critical', '24h', 'URGENT: Milestone Due in 24 Hours: ' . $m->title, 'Tender ' . $tender->reference_number . ' milestone "' . $m->title . '" closes tomorrow at ' . $m->due_at->format('H:i') . '!');
                $remindersSent[] = '24h';
                $alertsGenerated++;
            }

            // 4. Check 2 hours
            if ($hoursUntilDue <= 2 && $hoursUntilDue > 0 && in_array('2h', $leadTimes) && ! in_array('2h', $remindersSent)) {
                $this->createAlert($m, 'critical', '2h', 'FINAL CALL: Milestone Due in 2 Hours!', 'Tender ' . $tender->reference_number . ' milestone "' . $m->title . '" closes at ' . $m->due_at->format('H:i') . '. Complete submission immediately.');
                $remindersSent[] = '2h';
                $alertsGenerated++;
            }

            // 5. Overdue / Missed
            if ($hoursUntilDue < 0 && ! in_array('overdue', $remindersSent)) {
                $this->createAlert($m, 'critical', 'overdue', 'OVERDUE: Milestone Deadline Passed: ' . $m->title, 'Tender ' . $tender->reference_number . ' milestone "' . $m->title . '" deadline of ' . $m->due_at->format('d M Y H:i') . ' was not marked completed.');
                $remindersSent[] = 'overdue';
                $m->status = 'missed';
                $alertsGenerated++;
            }

            $m->reminders_sent = $remindersSent;
            $m->save();
        }

        return $alertsGenerated;
    }

    protected function createAlert(TenderMilestone $milestone, string $level, string $leadTime, string $title, string $message): TenderAlert
    {
        return TenderAlert::create([
            'tenant_id' => $milestone->tenant_id,
            'tender_id' => $milestone->tender_id,
            'tender_milestone_id' => $milestone->id,
            'level' => $level,
            'lead_time' => $leadTime,
            'title' => $title,
            'message' => $message,
            'channel' => 'in_app',
            'sent_at' => now(),
        ]);
    }
}
