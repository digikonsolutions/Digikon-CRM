<?php

namespace App\Services\Ai;

use App\Models\Lead;

/**
 * Server-side validation of the AI output. The model output is untrusted:
 *  - only known fields are kept (unknown keys are dropped, never written)
 *  - enums are enforced
 *  - every extracted fact needs evidence: a quote that really appears in a message of THIS lead
 *    (otherwise the field is dropped and listed in rejected_fields — no invented budgets/timelines)
 */
class AnalysisValidator
{
    public const FACT_FIELDS = ['service_interest', 'budget', 'timeline', 'industry'];

    /** @param  array<int,string>  $messages  message_id => body */
    public function validate(mixed $raw, array $messages): array
    {
        if (! is_array($raw) || ! isset($raw['summary']) || ! is_string($raw['summary']) || trim($raw['summary']) === '') {
            throw new AiException('AI output is malformed (missing summary).');
        }

        $normalized = array_map([$this, 'norm'], $messages);
        $evidence = [];
        $rejected = [];
        $out = [
            'summary' => $this->str($raw['summary'], 2000),
            'language' => $this->str($raw['language'] ?? null, 30),
        ];

        // ---- facts: value + evidence quotes ----
        foreach (self::FACT_FIELDS as $field) {
            $node = $raw[$field] ?? null;
            $value = is_array($node) ? ($node['value'] ?? null) : null;
            $value = $this->str($value, 190);
            if ($value === null || in_array(mb_strtolower($value), ['unknown', 'n/a', 'none', 'not mentioned', 'null'], true)) {
                $out[$field] = null;
                continue;
            }
            $quotes = $this->checkEvidence($node['evidence'] ?? [], $normalized);
            if (! $quotes) {
                $out[$field] = null;
                $rejected[$field] = 'No supporting quote found in the conversation';
                continue;
            }
            $out[$field] = $value;
            $evidence[$field] = $quotes;
        }

        // ---- buying intent ----
        $intent = $raw['buying_intent'] ?? [];
        $level = is_array($intent) ? ($intent['level'] ?? 'unknown') : 'unknown';
        $level = array_key_exists($level, config('crm.buying_intents')) ? $level : 'unknown';
        if ($level !== 'unknown') {
            $quotes = $this->checkEvidence($intent['evidence'] ?? [], $normalized);
            if (! $quotes) {
                $rejected['buying_intent'] = 'No supporting quote';
                $level = 'unknown';
            } else {
                $evidence['buying_intent'] = $quotes;
            }
        }
        $out['buying_intent'] = $level;

        // ---- temperature (needs evidence + reason) ----
        $temp = $raw['temperature'] ?? [];
        $tv = is_array($temp) ? ($temp['value'] ?? null) : null;
        $out['temperature'] = null;
        $out['temperature_reason'] = is_array($temp) ? $this->str($temp['reason'] ?? null, 500) : null;
        if (array_key_exists((string) $tv, config('crm.temperatures'))) {
            $quotes = $this->checkEvidence($temp['evidence'] ?? [], $normalized);
            if ($quotes) {
                $out['temperature'] = $tv;
                $evidence['temperature'] = $quotes;
            } else {
                $rejected['temperature'] = 'Suggested "'.$tv.'" without a supporting quote';
            }
        }

        // ---- qualification ----
        $qual = $raw['qualification'] ?? [];
        $qv = is_array($qual) ? ($qual['value'] ?? null) : null;
        $out['qualification'] = in_array($qv, ['qualified', 'needs_info', 'not_qualified'], true) ? $qv : null;
        $out['qualification_reason'] = is_array($qual) ? $this->str($qual['reason'] ?? null, 500) : null;

        // ---- recommendations (never auto-applied) ----
        $rs = $raw['recommended_status'] ?? null;
        $out['recommended_status'] = is_string($rs) && array_key_exists($rs, Lead::STATUSES) ? $rs : null;

        $out['recommended_actions'] = collect(is_array($raw['recommended_actions'] ?? null) ? $raw['recommended_actions'] : [])
            ->filter(fn ($a) => is_string($a) && trim($a) !== '')
            ->map(fn ($a) => $this->str($a, 300))->take(5)->values()->all();

        $days = $raw['follow_up_in_days'] ?? null;
        $out['follow_up_in_days'] = is_numeric($days) && (int) $days >= 0 && (int) $days <= 90 ? (int) $days : null;

        $out['tags'] = collect(is_array($raw['tags'] ?? null) ? $raw['tags'] : [])
            ->filter(fn ($t) => is_string($t) && preg_match('/^[\pL\pN][\pL\pN \-&\/]{0,38}$/u', trim($t)))
            ->map(fn ($t) => trim($t))->unique(fn ($t) => mb_strtolower($t))->take(5)->values()->all();

        // ---- agreed meeting time (must be quoted, in the future, within 60 days) ----
        $out['meeting_at'] = null;
        $out['meeting_type'] = null;
        $mr = $raw['meeting_request'] ?? null;
        if (is_array($mr) && is_string($mr['value'] ?? null) && trim($mr['value']) !== '') {
            $quotes = $this->checkEvidence($mr['evidence'] ?? [], $normalized);
            $at = $this->parseLocal(trim($mr['value']));
            if (! $quotes) {
                $rejected['meeting_request'] = 'Meeting time without a supporting quote';
            } elseif (! $at || $at->lte(now()) || $at->gt(now()->addDays(60))) {
                $rejected['meeting_request'] = 'Meeting time is invalid or not in the next 60 days';
            } else {
                $out['meeting_at'] = $at;
                $out['meeting_type'] = in_array($mr['type'] ?? null, ['phone', 'online', 'in_person'], true) ? $mr['type'] : 'phone';
                $evidence['meeting_request'] = $quotes;
            }
        }

        $conf = $raw['confidence'] ?? null;
        $out['confidence'] = is_numeric($conf) ? max(0, min(1, (float) $conf)) : null;

        $out['evidence'] = $evidence;
        $out['rejected_fields'] = $rejected;

        return $out;
    }

    /** Keep only quotes that literally occur in the referenced message (or anywhere in the chat if id is missing). */
    protected function checkEvidence(mixed $items, array $normalized): array
    {
        if (! is_array($items)) {
            return [];
        }
        $valid = [];
        foreach (array_slice($items, 0, 5) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $quote = $this->str($item['quote'] ?? null, 300);
            if ($quote === null || mb_strlen($quote) < 2) {
                continue;
            }
            $nq = $this->norm($quote);
            $id = isset($item['message_id']) && is_numeric($item['message_id']) ? (int) $item['message_id'] : null;

            if ($id !== null && isset($normalized[$id]) && str_contains($normalized[$id], $nq)) {
                $valid[] = ['message_id' => $id, 'quote' => $quote];
                continue;
            }
            foreach ($normalized as $mid => $body) {
                if (str_contains($body, $nq)) {
                    $valid[] = ['message_id' => $mid, 'quote' => $quote];
                    break;
                }
            }
        }

        return $valid;
    }

    protected function parseLocal(string $value): ?\Illuminate\Support\Carbon
    {
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i'] as $fmt) {
            try {
                $d = \Illuminate\Support\Carbon::createFromFormat($fmt, $value, config('app.timezone'));
                if ($d !== false) {
                    return $d->second(0);
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    protected function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(['’', '‘', '“', '”'], ["'", "'", '"', '"'], $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    protected function str(mixed $v, int $max): ?string
    {
        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }
        $v = trim(strip_tags((string) $v));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
