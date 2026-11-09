<?php

namespace App\Services\Tender;

use App\Models\Tender;
use App\Models\TenderOutcome;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TenderAnalyticsService
{
    /**
     * Record the final outcome (awarded, lost, disqualified, etc.)
     */
    public function recordOutcome(Tender $tender, array $data, User $user): TenderOutcome
    {
        $outcomeVal = $data['outcome'] ?? 'under_evaluation';

        $outcome = TenderOutcome::updateOrCreate(
            [
                'tenant_id' => $tender->tenant_id,
                'tender_id' => $tender->id,
            ],
            [
                'outcome' => $outcomeVal,
                'awarded_at' => isset($data['awarded_at']) ? Carbon::parse($data['awarded_at']) : ($outcomeVal === 'awarded' ? now() : null),
                'winning_bidder_name' => $data['winning_bidder_name'] ?? null,
                'winning_price' => $data['winning_price'] ?? null,
                'winning_currency' => $data['winning_currency'] ?? $tender->currency,
                'price_variance_percent' => $data['price_variance_percent'] ?? null,
                'loss_reason' => $data['loss_reason'] ?? null,
                'debrief_notes' => $data['debrief_notes'] ?? null,
                'contract_reference' => $data['contract_reference'] ?? null,
                'contract_signed_at' => isset($data['contract_signed_at']) ? Carbon::parse($data['contract_signed_at']) : null,
                'final_margin_percent' => $data['final_margin_percent'] ?? null,
                'recorded_by_id' => $user->id,
            ]
        );

        // Update tender master status
        if ($outcomeVal === 'awarded') {
            $tender->update([
                'status' => 'awarded',
                'status_reason' => "Awarded contract. Contract Ref: " . ($data['contract_reference'] ?? 'Pending'),
            ]);
        } elseif (in_array($outcomeVal, ['lost', 'disqualified'])) {
            $tender->update([
                'status' => 'unsuccessful',
                'status_reason' => "Unsuccessful. Reason: " . ($data['loss_reason'] ?? 'Competitor selected'),
            ]);
        } elseif ($outcomeVal === 'cancelled') {
            $tender->update([
                'status' => 'cancelled',
                'status_reason' => "Tender cancelled by procuring entity.",
            ]);
        }

        return $outcome;
    }

    /**
     * Calculate executive dashboard analytics and win/loss KPIs.
     */
    public function getExecutiveDashboardMetrics(int $tenantId): array
    {
        $allTenders = Tender::where('tenant_id', $tenantId)->get();
        $totalCount = $allTenders->count();

        $activeCount = $allTenders->whereIn('status', ['draft', 'evaluating_go_no_go', 'active_bidding'])->count();
        $submittedCount = $allTenders->where('status', 'submitted')->count();
        $awardedCount = $allTenders->where('status', 'awarded')->count();
        $unsuccessfulCount = $allTenders->where('status', 'unsuccessful')->count();
        $cancelledCount = $allTenders->where('status', 'cancelled')->count();

        $pipelineValue = (float) $allTenders->whereIn('status', ['draft', 'evaluating_go_no_go', 'active_bidding'])->sum('estimated_value');
        $submittedValue = (float) $allTenders->where('status', 'submitted')->sum('estimated_value');
        $awardedValue = (float) $allTenders->where('status', 'awarded')->sum('estimated_value');

        // Decided outcomes
        $decidedCount = $awardedCount + $unsuccessfulCount;
        $winRatePercent = $decidedCount > 0 ? round(($awardedCount / $decidedCount) * 100, 1) : 0.0;

        // Breakdown by procuring entity type
        $entityTypes = ['government', 'local_authority', 'parastatal', 'ngo', 'private'];
        $winRateByEntityType = [];

        foreach ($entityTypes as $type) {
            $typeTenders = $allTenders->where('entity_type', $type);
            $typeAwarded = $typeTenders->where('status', 'awarded')->count();
            $typeUnsuccessful = $typeTenders->where('status', 'unsuccessful')->count();
            $typeDecided = $typeAwarded + $typeUnsuccessful;

            $winRateByEntityType[$type] = [
                'type' => $type,
                'total_bids' => $typeTenders->count(),
                'awarded' => $typeAwarded,
                'unsuccessful' => $typeUnsuccessful,
                'win_rate' => $typeDecided > 0 ? round(($typeAwarded / $typeDecided) * 100, 1) : 0.0,
                'awarded_value' => (float) $typeTenders->where('status', 'awarded')->sum('estimated_value'),
            ];
        }

        // Loss reasons distribution from outcomes
        $outcomes = TenderOutcome::where('tenant_id', $tenantId)->get();
        $lossReasons = [];
        foreach ($outcomes->where('outcome', 'lost') as $lostOutcome) {
            $reason = $lostOutcome->loss_reason ?: 'other';
            $lossReasons[$reason] = ($lossReasons[$reason] ?? 0) + 1;
        }

        // Competitor intelligence leaderboard
        $competitorWins = [];
        foreach ($outcomes->where('outcome', 'lost')->whereNotNull('winning_bidder_name') as $lostOutcome) {
            $winner = trim($lostOutcome->winning_bidder_name);
            if ($winner !== '') {
                $competitorWins[$winner] = ($competitorWins[$winner] ?? 0) + 1;
            }
        }
        arsort($competitorWins);
        $topCompetitors = array_slice($competitorWins, 0, 5, true);

        return [
            'total_tenders' => $totalCount,
            'active_bidding' => $activeCount,
            'submitted' => $submittedCount,
            'awarded' => $awardedCount,
            'unsuccessful' => $unsuccessfulCount,
            'cancelled' => $cancelledCount,
            'pipeline_value' => $pipelineValue,
            'submitted_value' => $submittedValue,
            'awarded_value' => $awardedValue,
            'win_rate_percent' => $winRatePercent,
            'entity_type_breakdown' => $winRateByEntityType,
            'loss_reasons' => $lossReasons,
            'top_competitors' => $topCompetitors,
        ];
    }
}

