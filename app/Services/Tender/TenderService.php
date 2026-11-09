<?php

namespace App\Services\Tender;

use App\Models\Employee;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Models\User;
use App\Services\Audit;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TenderService
{
    private function permit(User $u, string $permission): void
    {
        abort_unless($u->canDo($permission), 403, 'Unauthorized tender action.');
    }

    public function list(User $u, array $filters = []): array
    {
        $this->permit($u, 'tenders.view');

        $query = Tender::with(['assignedEmployee:id,first_name,last_name,designation'])
            ->withCount('documents');

        // Search filter
        if (! empty($filters['search'])) {
            $s = strtolower(trim($filters['search']));
            $query->where(function ($q) use ($s) {
                $q->whereRaw('LOWER(reference_number) LIKE ?', ["%$s%"])
                    ->orWhereRaw('LOWER(title) LIKE ?', ["%$s%"])
                    ->orWhereRaw('LOWER(procuring_entity) LIKE ?', ["%$s%"])
                    ->orWhereRaw('LOWER(unspsc_code) LIKE ?', ["%$s%"]);
            });
        }

        // Category filter
        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('procurement_category', $filters['category']);
        }

        // Status filter
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        // Entity type filter
        if (! empty($filters['entity_type']) && $filters['entity_type'] !== 'all') {
            $query->where('entity_type', $filters['entity_type']);
        }

        // Opportunity type filter
        if (! empty($filters['opportunity_type']) && $filters['opportunity_type'] !== 'all') {
            $query->where('opportunity_type', $filters['opportunity_type']);
        }

        $allTenders = Tender::get(['id', 'status', 'estimated_value', 'currency', 'closing_at']);
        $now = Carbon::now();

        $totalTenders = $allTenders->count();
        $activeBidding = $allTenders->whereIn('status', ['draft', 'evaluating_go_no_go', 'active_bidding'])->count();
        $submitted = $allTenders->where('status', 'submitted')->count();
        $awarded = $allTenders->where('status', 'awarded')->count();
        $closingSoon = $allTenders->filter(function ($t) use ($now) {
            if (! $t->closing_at) return false;
            $closing = Carbon::parse($t->closing_at);
            return $closing->isFuture() && $closing->diffInDays($now) <= 7;
        })->count();

        $pipelineValue = $allTenders->whereIn('status', ['draft', 'evaluating_go_no_go', 'active_bidding'])->sum('estimated_value');
        $awardedValue = $allTenders->where('status', 'awarded')->sum('estimated_value');

        $tenders = $query->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Tender $t) => [
                'id' => $t->id,
                'reference_number' => $t->reference_number,
                'title' => $t->title,
                'procuring_entity' => $t->procuring_entity,
                'entity_type' => $t->entity_type,
                'procurement_category' => $t->procurement_category,
                'procurement_method' => $t->procurement_method,
                'opportunity_type' => $t->opportunity_type,
                'praz_categories' => $t->praz_categories ?? [],
                'unspsc_code' => $t->unspsc_code,
                'currency' => $t->currency,
                'estimated_value' => $t->estimated_value,
                'tender_fee' => $t->tender_fee,
                'bid_security_required' => $t->bid_security_required,
                'bid_security_amount' => $t->bid_security_amount,
                'bid_security_details' => $t->bid_security_details,
                'closing_at' => $t->closing_at?->toIso8601String(),
                'clarification_deadline' => $t->clarification_deadline?->toIso8601String(),
                'site_visit_required' => $t->site_visit_required,
                'site_visit_at' => $t->site_visit_at?->toIso8601String(),
                'site_visit_location' => $t->site_visit_location,
                'internal_deadline' => $t->internal_deadline?->toIso8601String(),
                'submission_method' => $t->submission_method,
                'submission_address' => $t->submission_address,
                'source_url' => $t->source_url,
                'contact_person' => $t->contact_person,
                'contact_email' => $t->contact_email,
                'contact_phone' => $t->contact_phone,
                'status' => $t->status,
                'status_reason' => $t->status_reason,
                'notes' => $t->notes,
                'assigned_employee' => $t->assignedEmployee ? [
                    'id' => $t->assignedEmployee->id,
                    'name' => trim($t->assignedEmployee->first_name.' '.$t->assignedEmployee->last_name),
                    'designation' => $t->assignedEmployee->designation,
                ] : null,
                'documents_count' => $t->documents_count,
                'days_remaining' => $t->daysRemaining(),
                'hours_remaining' => $t->hoursRemaining(),
                'is_closed' => $t->isClosed(),
                'created_at' => $t->created_at->toIso8601String(),
            ])
            ->all();

        return [
            'tenders' => $tenders,
            'summary' => [
                'total_tenders' => $totalTenders,
                'active_bidding' => $activeBidding,
                'closing_soon' => $closingSoon,
                'submitted' => $submitted,
                'awarded' => $awarded,
                'pipeline_value' => number_format((float) $pipelineValue, 2, '.', ''),
                'awarded_value' => number_format((float) $awardedValue, 2, '.', ''),
            ],
            'catalog' => [
                'categories' => TenderCatalog::CATEGORIES,
                'methods' => TenderCatalog::METHODS,
                'opportunity_types' => TenderCatalog::OPPORTUNITY_TYPES,
                'entity_types' => TenderCatalog::ENTITY_TYPES,
                'praz_categories' => TenderCatalog::PRAZ_CATEGORY_CODES,
                'procuring_entity_presets' => TenderCatalog::PROCURING_ENTITY_PRESETS,
            ],
            'employees' => Employee::where('active', true)
                ->get(['id', 'first_name', 'last_name', 'designation'])
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => trim($e->first_name.' '.$e->last_name),
                    'designation' => $e->designation,
                ]),
        ];
    }

    public function find(User $u, int $id): array
    {
        $this->permit($u, 'tenders.view');

        $t = Tender::with(['assignedEmployee:id,first_name,last_name,designation', 'documents'])
            ->findOrFail($id);

        return [
            'tender' => [
                'id' => $t->id,
                'reference_number' => $t->reference_number,
                'title' => $t->title,
                'procuring_entity' => $t->procuring_entity,
                'entity_type' => $t->entity_type,
                'procurement_category' => $t->procurement_category,
                'procurement_method' => $t->procurement_method,
                'opportunity_type' => $t->opportunity_type,
                'praz_categories' => $t->praz_categories ?? [],
                'unspsc_code' => $t->unspsc_code,
                'currency' => $t->currency,
                'estimated_value' => $t->estimated_value,
                'tender_fee' => $t->tender_fee,
                'bid_security_required' => $t->bid_security_required,
                'bid_security_amount' => $t->bid_security_amount,
                'bid_security_details' => $t->bid_security_details,
                'closing_at' => $t->closing_at?->toIso8601String(),
                'clarification_deadline' => $t->clarification_deadline?->toIso8601String(),
                'site_visit_required' => $t->site_visit_required,
                'site_visit_at' => $t->site_visit_at?->toIso8601String(),
                'site_visit_location' => $t->site_visit_location,
                'internal_deadline' => $t->internal_deadline?->toIso8601String(),
                'submission_method' => $t->submission_method,
                'submission_address' => $t->submission_address,
                'source_url' => $t->source_url,
                'contact_person' => $t->contact_person,
                'contact_email' => $t->contact_email,
                'contact_phone' => $t->contact_phone,
                'status' => $t->status,
                'status_reason' => $t->status_reason,
                'notes' => $t->notes,
                'assigned_employee' => $t->assignedEmployee ? [
                    'id' => $t->assignedEmployee->id,
                    'name' => trim($t->assignedEmployee->first_name.' '.$t->assignedEmployee->last_name),
                    'designation' => $t->assignedEmployee->designation,
                ] : null,
                'days_remaining' => $t->daysRemaining(),
                'hours_remaining' => $t->hoursRemaining(),
                'is_closed' => $t->isClosed(),
                'created_at' => $t->created_at->toIso8601String(),
            ],
            'documents' => $t->documents->map(fn (TenderDocument $d) => [
                'id' => $d->id,
                'file_name' => $d->file_name,
                'file_size' => $d->file_size,
                'mime_type' => $d->mime_type,
                'document_type' => $d->document_type,
                'description' => $d->description,
                'created_at' => $d->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    public function store(User $u, array $data, array $files = []): Tender
    {
        $this->permit($u, 'tenders.manage');

        return DB::transaction(function () use ($u, $data, $files) {
            $tender = new Tender([
                'reference_number' => $data['reference_number'],
                'title' => $data['title'],
                'procuring_entity' => $data['procuring_entity'],
                'entity_type' => $data['entity_type'] ?? 'government',
                'procurement_category' => $data['procurement_category'],
                'procurement_method' => $data['procurement_method'] ?? 'open_competitive_bidding',
                'opportunity_type' => $data['opportunity_type'] ?? 'tender',
                'praz_categories' => ! empty($data['praz_categories']) ? (array) $data['praz_categories'] : null,
                'unspsc_code' => $data['unspsc_code'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'estimated_value' => $data['estimated_value'] ?? null,
                'tender_fee' => $data['tender_fee'] ?? null,
                'bid_security_required' => (bool) ($data['bid_security_required'] ?? false),
                'bid_security_amount' => $data['bid_security_amount'] ?? null,
                'bid_security_details' => $data['bid_security_details'] ?? null,
                'closing_at' => $data['closing_at'],
                'clarification_deadline' => $data['clarification_deadline'] ?? null,
                'site_visit_required' => (bool) ($data['site_visit_required'] ?? false),
                'site_visit_at' => $data['site_visit_at'] ?? null,
                'site_visit_location' => $data['site_visit_location'] ?? null,
                'internal_deadline' => $data['internal_deadline'] ?? null,
                'submission_method' => $data['submission_method'] ?? 'electronic_portal',
                'submission_address' => $data['submission_address'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'assigned_employee_id' => $data['assigned_employee_id'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $tender->save();

            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $this->uploadDocument($u, $tender->id, $file);
                }
            }

            Audit::record('tender.created', (string) $tender->id, [
                'reference_number' => $tender->reference_number,
                'procuring_entity' => $tender->procuring_entity,
            ]);

            return $tender;
        });
    }

    public function update(User $u, int $id, array $data): Tender
    {
        $this->permit($u, 'tenders.manage');

        return DB::transaction(function () use ($u, $id, $data) {
            $tender = Tender::findOrFail($id);

            $tender->fill([
                'reference_number' => $data['reference_number'] ?? $tender->reference_number,
                'title' => $data['title'] ?? $tender->title,
                'procuring_entity' => $data['procuring_entity'] ?? $tender->procuring_entity,
                'entity_type' => $data['entity_type'] ?? $tender->entity_type,
                'procurement_category' => $data['procurement_category'] ?? $tender->procurement_category,
                'procurement_method' => $data['procurement_method'] ?? $tender->procurement_method,
                'opportunity_type' => $data['opportunity_type'] ?? $tender->opportunity_type,
                'praz_categories' => isset($data['praz_categories']) ? (array) $data['praz_categories'] : $tender->praz_categories,
                'unspsc_code' => array_key_exists('unspsc_code', $data) ? $data['unspsc_code'] : $tender->unspsc_code,
                'currency' => $data['currency'] ?? $tender->currency,
                'estimated_value' => array_key_exists('estimated_value', $data) ? $data['estimated_value'] : $tender->estimated_value,
                'tender_fee' => array_key_exists('tender_fee', $data) ? $data['tender_fee'] : $tender->tender_fee,
                'bid_security_required' => isset($data['bid_security_required']) ? (bool) $data['bid_security_required'] : $tender->bid_security_required,
                'bid_security_amount' => array_key_exists('bid_security_amount', $data) ? $data['bid_security_amount'] : $tender->bid_security_amount,
                'bid_security_details' => array_key_exists('bid_security_details', $data) ? $data['bid_security_details'] : $tender->bid_security_details,
                'closing_at' => $data['closing_at'] ?? $tender->closing_at,
                'clarification_deadline' => array_key_exists('clarification_deadline', $data) ? $data['clarification_deadline'] : $tender->clarification_deadline,
                'site_visit_required' => isset($data['site_visit_required']) ? (bool) $data['site_visit_required'] : $tender->site_visit_required,
                'site_visit_at' => array_key_exists('site_visit_at', $data) ? $data['site_visit_at'] : $tender->site_visit_at,
                'site_visit_location' => array_key_exists('site_visit_location', $data) ? $data['site_visit_location'] : $tender->site_visit_location,
                'internal_deadline' => array_key_exists('internal_deadline', $data) ? $data['internal_deadline'] : $tender->internal_deadline,
                'submission_method' => $data['submission_method'] ?? $tender->submission_method,
                'submission_address' => array_key_exists('submission_address', $data) ? $data['submission_address'] : $tender->submission_address,
                'source_url' => array_key_exists('source_url', $data) ? $data['source_url'] : $tender->source_url,
                'contact_person' => array_key_exists('contact_person', $data) ? $data['contact_person'] : $tender->contact_person,
                'contact_email' => array_key_exists('contact_email', $data) ? $data['contact_email'] : $tender->contact_email,
                'contact_phone' => array_key_exists('contact_phone', $data) ? $data['contact_phone'] : $tender->contact_phone,
                'assigned_employee_id' => array_key_exists('assigned_employee_id', $data) ? $data['assigned_employee_id'] : $tender->assigned_employee_id,
                'status' => $data['status'] ?? $tender->status,
                'status_reason' => array_key_exists('status_reason', $data) ? $data['status_reason'] : $tender->status_reason,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $tender->notes,
            ]);

            $tender->save();

            Audit::record('tender.updated', (string) $tender->id, [
                'reference_number' => $tender->reference_number,
                'status' => $tender->status,
            ]);

            return $tender;
        });
    }

    public function uploadDocument(User $u, int $tenderId, UploadedFile $file, array $meta = []): TenderDocument
    {
        $this->permit($u, 'tenders.manage');

        $tender = Tender::findOrFail($tenderId);

        $ext = $file->getClientOriginalExtension();
        $uuid = (string) Str::uuid();
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();
        $mimeType = $file->getMimeType();
        $fileBytes = file_get_contents($file->getRealPath());
        $fileHash = hash('sha256', $fileBytes);

        $dir = "tenders/{$u->tenant_id}/{$tender->id}";
        $filePath = $file->storeAs($dir, $uuid.'.'.$ext, 'local');

        $doc = new TenderDocument([
            'tender_id' => $tender->id,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'file_hash' => $fileHash,
            'document_type' => $meta['document_type'] ?? 'solicitation_rfp_bidding_doc',
            'description' => $meta['description'] ?? null,
        ]);

        $doc->save();

        Audit::record('tender.document_uploaded', (string) $doc->id, [
            'tender_id' => $tender->id,
            'file_name' => $doc->file_name,
        ]);

        return $doc;
    }

    public function downloadDocument(User $u, int $documentId): StreamedResponse
    {
        $this->permit($u, 'tenders.view');

        $doc = TenderDocument::findOrFail($documentId);

        if (! Storage::disk('local')->exists($doc->file_path)) {
            abort(404, 'Tender document not found.');
        }

        Audit::record('tender.document_downloaded', (string) $doc->id, [
            'tender_id' => $doc->tender_id,
            'file_name' => $doc->file_name,
        ]);

        return Storage::disk('local')->download(
            $doc->file_path,
            $doc->file_name,
            [
                'Cache-Control' => 'private, no-store, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function deleteDocument(User $u, int $documentId): void
    {
        $this->permit($u, 'tenders.manage');

        $doc = TenderDocument::findOrFail($documentId);

        if (Storage::disk('local')->exists($doc->file_path)) {
            Storage::disk('local')->delete($doc->file_path);
        }

        Audit::record('tender.document_deleted', (string) $doc->id, [
            'tender_id' => $doc->tender_id,
            'file_name' => $doc->file_name,
        ]);

        $doc->delete();
    }

    public function updateStatus(User $u, int $id, string $status, ?string $reason = null): Tender
    {
        $this->permit($u, 'tenders.manage');

        $validStatuses = ['draft', 'evaluating_go_no_go', 'active_bidding', 'submitted', 'awarded', 'unsuccessful', 'cancelled'];
        if (! in_array($status, $validStatuses, true)) {
            abort(422, 'Invalid tender status transition.');
        }

        $tender = Tender::findOrFail($id);
        $tender->update([
            'status' => $status,
            'status_reason' => $reason,
        ]);

        Audit::record('tender.status_changed', (string) $tender->id, [
            'old_status' => $tender->getOriginal('status'),
            'new_status' => $status,
            'reason' => $reason,
        ]);

        return $tender;
    }
}

