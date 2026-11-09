<?php

namespace App\Services\Compliance;

use App\Models\ComplianceDocument;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ComplianceService
{
    private function permit(User $u, string $permission): void
    {
        abort_unless($u->canDo($permission), 403, 'Unauthorized compliance action.');
    }

    public function list(User $u, array $filters = []): array
    {
        $this->permit($u, 'compliance.view');

        $activeDocs = ComplianceDocument::active()
            ->with(['responsibleEmployee:id,first_name,last_name,designation', 'previousVersions'])
            ->get()
            ->keyBy('type_key');

        $standards = ComplianceCatalog::standards();
        $warningDays = (int) config('compliance.expiry_warning_days', 30);
        $today = Carbon::today()->format('Y-m-d');

        $merged = [];
        $prazCategories = [];

        foreach ($standards as $std) {
            $typeKey = $std['type_key'];
            /** @var ComplianceDocument|null $doc */
            $doc = $activeDocs->get($typeKey);

            if ($doc) {
                $computedStatus = $doc->calculateComputedStatus($today, $warningDays);
                $days = $doc->daysUntilExpiry($today);
                if (! empty($doc->praz_categories)) {
                    $prazCategories = array_unique([...$prazCategories, ...$doc->praz_categories]);
                }

                $merged[] = [
                    'id' => $doc->id,
                    'is_persisted' => true,
                    'type_key' => $doc->type_key,
                    'category' => $doc->category,
                    'title' => $doc->title,
                    'issuing_authority' => $doc->issuing_authority ?? $std['issuing_authority'],
                    'reference_number' => $doc->reference_number,
                    'praz_categories' => $doc->praz_categories ?? [],
                    'issue_date' => $doc->issue_date?->format('Y-m-d'),
                    'expiry_date' => $doc->expiry_date?->format('Y-m-d'),
                    'verification_status' => $computedStatus,
                    'raw_status' => $doc->verification_status,
                    'status_reason' => $doc->status_reason,
                    'file_name' => $doc->file_name,
                    'file_size' => $doc->file_size,
                    'mime_type' => $doc->mime_type,
                    'version' => $doc->version,
                    'notes' => $doc->notes,
                    'responsible_employee' => $doc->responsibleEmployee ? [
                        'id' => $doc->responsibleEmployee->id,
                        'name' => trim($doc->responsibleEmployee->first_name.' '.$doc->responsibleEmployee->last_name),
                        'designation' => $doc->responsibleEmployee->designation,
                    ] : null,
                    'days_until_expiry' => $days,
                    'is_mandatory' => $std['is_mandatory'],
                    'has_expiry' => $std['has_expiry'],
                    'supports_praz_categories' => $std['supports_praz_categories'],
                    'description' => $std['description'],
                    'history_count' => $doc->previousVersions->count(),
                ];
            } else {
                $merged[] = [
                    'id' => null,
                    'is_persisted' => false,
                    'type_key' => $std['type_key'],
                    'category' => $std['category'],
                    'title' => $std['title'],
                    'issuing_authority' => $std['issuing_authority'],
                    'reference_number' => null,
                    'praz_categories' => [],
                    'issue_date' => null,
                    'expiry_date' => null,
                    'verification_status' => 'missing',
                    'raw_status' => 'missing',
                    'status_reason' => null,
                    'file_name' => null,
                    'file_size' => null,
                    'mime_type' => null,
                    'version' => 1,
                    'notes' => null,
                    'responsible_employee' => null,
                    'days_until_expiry' => null,
                    'is_mandatory' => $std['is_mandatory'],
                    'has_expiry' => $std['has_expiry'],
                    'supports_praz_categories' => $std['supports_praz_categories'],
                    'description' => $std['description'],
                    'history_count' => 0,
                ];
            }
        }

        // Custom documents uploaded that aren't part of standard catalog
        $standardKeys = array_column($standards, 'type_key');
        foreach ($activeDocs as $typeKey => $doc) {
            if (! in_array($typeKey, $standardKeys, true)) {
                $computedStatus = $doc->calculateComputedStatus($today, $warningDays);
                $days = $doc->daysUntilExpiry($today);
                if (! empty($doc->praz_categories)) {
                    $prazCategories = array_unique([...$prazCategories, ...$doc->praz_categories]);
                }

                $merged[] = [
                    'id' => $doc->id,
                    'is_persisted' => true,
                    'type_key' => $doc->type_key,
                    'category' => $doc->category,
                    'title' => $doc->title,
                    'issuing_authority' => $doc->issuing_authority,
                    'reference_number' => $doc->reference_number,
                    'praz_categories' => $doc->praz_categories ?? [],
                    'issue_date' => $doc->issue_date?->format('Y-m-d'),
                    'expiry_date' => $doc->expiry_date?->format('Y-m-d'),
                    'verification_status' => $computedStatus,
                    'raw_status' => $doc->verification_status,
                    'status_reason' => $doc->status_reason,
                    'file_name' => $doc->file_name,
                    'file_size' => $doc->file_size,
                    'mime_type' => $doc->mime_type,
                    'version' => $doc->version,
                    'notes' => $doc->notes,
                    'responsible_employee' => $doc->responsibleEmployee ? [
                        'id' => $doc->responsibleEmployee->id,
                        'name' => trim($doc->responsibleEmployee->first_name.' '.$doc->responsibleEmployee->last_name),
                        'designation' => $doc->responsibleEmployee->designation,
                    ] : null,
                    'days_until_expiry' => $days,
                    'is_mandatory' => false,
                    'has_expiry' => (bool) $doc->expiry_date,
                    'supports_praz_categories' => ! empty($doc->praz_categories),
                    'description' => $doc->notes ?? 'Custom uploaded tender supporting document.',
                    'history_count' => $doc->previousVersions->count(),
                ];
            }
        }

        // Filter if requested
        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $merged = array_values(array_filter($merged, fn ($d) => $d['category'] === $filters['category']));
        }
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $merged = array_values(array_filter($merged, fn ($d) => $d['verification_status'] === $filters['status']));
        }

        // Calculate Scorecard
        $totalTracked = count($merged);
        $validCount = count(array_filter($merged, fn ($d) => $d['verification_status'] === 'valid'));
        $expiringCount = count(array_filter($merged, fn ($d) => $d['verification_status'] === 'expiring'));
        $expiredCount = count(array_filter($merged, fn ($d) => $d['verification_status'] === 'expired'));
        $missingCount = count(array_filter($merged, fn ($d) => $d['verification_status'] === 'missing'));
        $naCount = count(array_filter($merged, fn ($d) => $d['verification_status'] === 'not_applicable'));
        $pendingCount = count(array_filter($merged, fn ($d) => $d['verification_status'] === 'pending_verification'));

        // Mandatory items compliance calculation
        $mandatoryItems = array_filter($merged, fn ($d) => $d['is_mandatory']);
        $mandatoryTotal = count($mandatoryItems);
        $mandatoryValid = count(array_filter($mandatoryItems, fn ($d) => in_array($d['verification_status'], ['valid', 'expiring'], true)));
        $scorePercent = $mandatoryTotal > 0 ? (int) round(($mandatoryValid / $mandatoryTotal) * 100) : 100;

        return [
            'documents' => $merged,
            'categories' => array_values(ComplianceCatalog::CATEGORIES),
            'summary' => [
                'total_tracked' => $totalTracked,
                'valid_count' => $validCount,
                'expiring_count' => $expiringCount,
                'expired_count' => $expiredCount,
                'missing_count' => $missingCount,
                'na_count' => $naCount,
                'pending_count' => $pendingCount,
                'mandatory_total' => $mandatoryTotal,
                'mandatory_valid' => $mandatoryValid,
                'health_score_percent' => $scorePercent,
                'praz_categories' => array_values($prazCategories),
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

    public function store(User $u, array $data, ?UploadedFile $file = null): ComplianceDocument
    {
        $this->permit($u, 'compliance.manage');

        return DB::transaction(function () use ($u, $data, $file) {
            $typeKey = $data['type_key'];
            $existing = ComplianceDocument::active()->where('type_key', $typeKey)->first();

            $version = 1;
            $parentDocumentId = null;

            if ($existing) {
                $version = $existing->version + 1;
                $parentDocumentId = $existing->parent_document_id ?? $existing->id;
                // Archive current version
                $existing->update(['is_active' => false]);
            }

            $filePath = null;
            $fileName = null;
            $fileSize = null;
            $mimeType = null;
            $fileHash = null;

            if ($file) {
                $ext = $file->getClientOriginalExtension();
                $uuid = (string) Str::uuid();
                $fileName = $file->getClientOriginalName();
                $fileSize = $file->getSize();
                $mimeType = $file->getMimeType();
                $fileBytes = file_get_contents($file->getRealPath());
                $fileHash = hash('sha256', $fileBytes);

                $dir = 'compliance/'.$u->tenant_id;
                $filePath = $file->storeAs($dir, $uuid.'.'.$ext, 'local');
            } elseif ($existing) {
                // If retaining the same file but updating metadata
                $filePath = $existing->file_path;
                $fileName = $existing->file_name;
                $fileSize = $existing->file_size;
                $mimeType = $existing->mime_type;
                $fileHash = $existing->file_hash;
            }

            $verificationStatus = 'valid';
            if ($data['verification_status'] ?? false) {
                $verificationStatus = $data['verification_status'];
            } elseif (! $filePath) {
                $verificationStatus = 'missing';
            }

            $doc = new ComplianceDocument([
                'category' => $data['category'],
                'type_key' => $typeKey,
                'title' => $data['title'],
                'issuing_authority' => $data['issuing_authority'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'praz_categories' => ! empty($data['praz_categories']) ? (array) $data['praz_categories'] : null,
                'issue_date' => $data['issue_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'verification_status' => $verificationStatus,
                'status_reason' => $data['status_reason'] ?? null,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'file_hash' => $fileHash,
                'version' => $version,
                'is_active' => true,
                'parent_document_id' => $parentDocumentId,
                'responsible_employee_id' => $data['responsible_employee_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);

            $doc->save();

            Audit::record('compliance.document_stored', (string) $doc->id, [
                'type_key' => $doc->type_key,
                'title' => $doc->title,
                'version' => $doc->version,
                'has_file' => (bool) $filePath,
            ]);

            return $doc;
        });
    }

    public function markNotApplicable(User $u, string $typeKey, string $reason): ComplianceDocument
    {
        $this->permit($u, 'compliance.manage');

        $standards = collect(ComplianceCatalog::standards())->keyBy('type_key');
        $std = $standards->get($typeKey);

        if ($std && $std['is_mandatory']) {
            throw ValidationException::withMessages([
                'type_key' => 'Mandatory statutory documents (e.g. ZIMRA Tax Clearance, PRAZ, NSSA) cannot be marked as Not Applicable.',
            ]);
        }

        return DB::transaction(function () use ($u, $typeKey, $reason, $std) {
            $existing = ComplianceDocument::active()->where('type_key', $typeKey)->first();

            if ($existing) {
                $existing->update([
                    'verification_status' => 'not_applicable',
                    'status_reason' => $reason,
                ]);

                Audit::record('compliance.marked_na', (string) $existing->id, [
                    'type_key' => $typeKey,
                    'reason' => $reason,
                ]);

                return $existing;
            }

            $doc = new ComplianceDocument([
                'category' => $std['category'] ?? 'statutory',
                'type_key' => $typeKey,
                'title' => $std['title'] ?? ucfirst(str_replace('_', ' ', $typeKey)),
                'issuing_authority' => $std['issuing_authority'] ?? null,
                'verification_status' => 'not_applicable',
                'status_reason' => $reason,
                'version' => 1,
                'is_active' => true,
            ]);
            $doc->save();

            Audit::record('compliance.marked_na', (string) $doc->id, [
                'type_key' => $typeKey,
                'reason' => $reason,
            ]);

            return $doc;
        });
    }

    public function download(User $u, int $id): StreamedResponse
    {
        $this->permit($u, 'compliance.view');

        $doc = ComplianceDocument::findOrFail($id);

        if (! $doc->file_path || ! Storage::disk('local')->exists($doc->file_path)) {
            abort(404, 'Compliance document file not found.');
        }

        Audit::record('compliance.document_downloaded', (string) $doc->id, [
            'type_key' => $doc->type_key,
            'file_name' => $doc->file_name,
        ]);

        return Storage::disk('local')->download(
            $doc->file_path,
            $doc->file_name ?? $doc->title.'.pdf',
            [
                'Cache-Control' => 'private, no-store, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function history(User $u, int $id): array
    {
        $this->permit($u, 'compliance.view');

        $doc = ComplianceDocument::findOrFail($id);
        $rootId = $doc->parent_document_id ?? $doc->id;

        $versions = ComplianceDocument::where('id', $rootId)
            ->orWhere('parent_document_id', $rootId)
            ->orderByDesc('version')
            ->get();

        return $versions->map(fn ($v) => [
            'id' => $v->id,
            'version' => $v->version,
            'title' => $v->title,
            'reference_number' => $v->reference_number,
            'issue_date' => $v->issue_date?->format('Y-m-d'),
            'expiry_date' => $v->expiry_date?->format('Y-m-d'),
            'verification_status' => $v->verification_status,
            'file_name' => $v->file_name,
            'file_size' => $v->file_size,
            'is_active' => $v->is_active,
            'created_at' => $v->created_at->toIso8601String(),
            'notes' => $v->notes,
        ])->all();
    }
}
