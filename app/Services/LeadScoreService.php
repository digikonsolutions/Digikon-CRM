<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Explicit, explainable lead score (0-100). AI output is only ONE input.
 *
 *  Temperature      hot +30, warm +15, cold 0
 *  Buying intent    high +20, medium +10
 *  Qualification    qualified +15, needs_info +5, not_qualified -20
 *  Budget known     +10
 *  Timeline known   +5
 *  Replied on WhatsApp in last 72h   +10
 *  Stage            proposal/negotiation +10
 *  Closed (won/lost) → 0 (nothing left to prioritise)
 */
class LeadScoreService
{
    public function calculate(Lead $lead): array
    {
        if ($lead->isClosed()) {
            return ['score' => 0, 'reasons' => ['Lead is closed ('.$lead->status_label.')']];
        }

        $score = 0;
        $reasons = [];
        $add = function (int $points, string $why) use (&$score, &$reasons) {
            $score += $points;
            $reasons[] = ($points >= 0 ? '+' : '').$points.' '.$why;
        };

        match ($lead->temperature) {
            'hot' => $add(30, 'hot lead'),
            'warm' => $add(15, 'warm lead'),
            default => null,
        };
        match ($lead->buying_intent) {
            'high' => $add(20, 'high buying intent'),
            'medium' => $add(10, 'medium buying intent'),
            default => null,
        };
        match ($lead->qualification) {
            'qualified' => $add(15, 'qualified'),
            'needs_info' => $add(5, 'needs more info'),
            'not_qualified' => $add(-20, 'not qualified'),
            default => null,
        };
        if (filled($lead->budget)) {
            $add(10, 'budget known');
        }
        if (filled($lead->timeline)) {
            $add(5, 'timeline known');
        }
        if ($lead->last_reply_at && $lead->last_reply_at->gt(now()->subHours(72))) {
            $add(10, 'replied on WhatsApp in last 72h');
        }
        if (in_array($lead->status, ['proposal', 'negotiation'], true)) {
            $add(10, 'late pipeline stage');
        }

        return ['score' => max(0, min(100, $score)), 'reasons' => $reasons];
    }

    public function refresh(Lead $lead): int
    {
        $score = $this->calculate($lead->fresh() ?? $lead)['score'];
        if ($lead->score !== $score) {
            $lead->forceFill(['score' => $score])->saveQuietly();
        }

        return $score;
    }
}
