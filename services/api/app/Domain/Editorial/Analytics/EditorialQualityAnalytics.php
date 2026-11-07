<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Analytics;

use Illuminate\Support\Facades\DB;

final class EditorialQualityAnalytics
{
    /**
     * Recompute comprehensive editorial quality dashboard metrics from measured database events.
     * Acceptance criterion: Quality dashboard recomputes from measured events.
     */
    public function getQualityDashboardMetrics(): array
    {
        $signalsCount = DB::table('editorial_story_signals')->count();
        $publishedCount = DB::table('editorial_publication_records')->where('publishing_state', 'published')->count();
        $quarantinedCount = DB::table('editorial_publication_records')->where('publishing_state', 'quarantined')->count();
        $retractedCount = DB::table('editorial_publication_records')->where('publishing_state', 'retracted')->count();
        $correctionsCount = DB::table('editorial_corrections_history')->count();
        $outboxDeliveredCount = DB::table('editorial_outbox')->where('delivery_status', 'delivered')->count();
        $outboxPendingCount = DB::table('editorial_outbox')->where('delivery_status', 'pending')->count();

        // Calculate average publish latency
        $latencyRecords = DB::table('editorial_publication_records as p')
            ->join('editorial_story_signals as s', 'p.story_signal_id', '=', 's.id')
            ->whereNotNull('p.published_at')
            ->selectRaw('EXTRACT(EPOCH FROM (p.published_at - s.emitted_at)) as latency_seconds')
            ->get();

        $avgLatency = $latencyRecords->isNotEmpty()
            ? round((float) $latencyRecords->avg('latency_seconds'), 2)
            : 0.0;

        // Factual accuracy score (100% baseline with penalties for retractions and high-risk catches)
        $totalDecisions = max(1, $publishedCount + $quarantinedCount + $retractedCount);
        $factualAccuracyPct = round(max(0, 100 - (($retractedCount * 15 + $quarantinedCount * 5) / $totalDecisions)), 1);

        return [
            'metrics_computed_at' => now()->toIso8601String(),
            'total_story_signals' => $signalsCount,
            'articles' => [
                'published' => $publishedCount,
                'quarantined' => $quarantinedCount,
                'retracted' => $retractedCount,
                'corrections_applied' => $correctionsCount,
            ],
            'outbox' => [
                'delivered' => $outboxDeliveredCount,
                'pending' => $outboxPendingCount,
            ],
            'performance' => [
                'average_publish_latency_seconds' => $avgLatency,
                'factual_accuracy_score' => $factualAccuracyPct,
            ],
        ];
    }
}

