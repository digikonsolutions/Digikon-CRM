<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;

/**
 * OFFLINE analyser — no API key, no internet. Simple keyword rules for English / Roman Urdu.
 * Meant for testing the full flow (and as a fallback). It returns the same structured
 * format as the real AI, so its output goes through the same server-side validation.
 */
class LocalRuleClient implements AiClient
{
    protected const SERVICES = [
        'E-commerce store' => ['ecommerce', 'e-commerce', 'online store', 'online shop', 'shopify', 'woocommerce', 'store'],
        'Mobile app' => ['mobile app', 'android', 'ios', 'app '],
        'SEO' => ['seo', 'google ranking', 'search engine', 'google pe upar'],
        'Social media marketing' => ['social media', 'smm', 'facebook ads', 'instagram', 'meta ads', 'marketing'],
        'Branding / logo' => ['logo', 'branding', 'brand identity'],
        'Website development' => ['website', 'web site', 'webpage', 'landing page', 'site '],
    ];

    protected const INDUSTRIES = [
        'Clothing / fashion' => ['clothing', 'kapron', 'kapra', 'kapre', 'fashion', 'boutique', 'garments'],
        'Restaurant / food' => ['restaurant', 'cafe', 'food', 'bakery', 'dhaba'],
        'Real estate' => ['real estate', 'property', 'plots', 'builders'],
        'Education' => ['school', 'academy', 'college', 'institute', 'tuition'],
        'Healthcare' => ['clinic', 'hospital', 'doctor', 'pharmacy', 'dental'],
        'Beauty / salon' => ['salon', 'parlour', 'parlor', 'beauty', 'cosmetics'],
        'Travel' => ['travel', 'tours', 'umrah', 'visa'],
        'Retail' => ['shop', 'mart', 'grocery', 'electronics', 'furniture', 'jewellery', 'jewelry'],
    ];

    protected const HIGH = ['proposal', 'quotation', 'quote', 'price', 'rate', 'kitna', 'kitne', 'charges', 'book', 'confirm', 'start kar', 'advance', 'payment', 'call kar', 'meeting'];

    protected const LOW = ['not interested', 'nahi chahiye', 'interested nahi', 'abhi nahi', 'baad mein', 'later', 'mehnga', 'expensive', 'no need', 'zaroorat nahi'];

    public function provider(): string
    {
        return 'local';
    }

    public function model(): string
    {
        return 'offline-rules-v1';
    }

    public function structured(string $system, string $prompt, string $toolName, array $schema): array
    {
        if ($toolName !== 'record_lead_analysis') {
            return ['ok' => true]; // connection test
        }

        preg_match_all('/^\[#(\d+)\] \[(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\] (CUSTOMER|AGENT|AUTOMATED): (.*)$/m', $prompt, $m, PREG_SET_ORDER);
        $customer = array_values(array_filter($m, fn ($x) => $x[3] === 'CUSTOMER'));

        $find = function (array $words) use ($customer) {
            foreach ($customer as $msg) {
                $lower = mb_strtolower($msg[4]);
                foreach ($words as $w) {
                    if (($pos = mb_strpos($lower.' ', $w)) !== false) {
                        return [(int) $msg[1], mb_substr($msg[4], $pos, mb_strlen(rtrim($w)))];
                    }
                }
            }

            return null;
        };
        $fact = fn (?string $value, ?array $hit) => ['value' => $hit ? $value : null, 'evidence' => $hit ? [['message_id' => $hit[0], 'quote' => $hit[1]]] : []];

        $service = null;
        $serviceHit = null;
        foreach (self::SERVICES as $name => $words) {
            if ($hit = $find($words)) {
                [$service, $serviceHit] = [$name, $hit];
                break;
            }
        }
        $industry = null;
        $industryHit = null;
        foreach (self::INDUSTRIES as $name => $words) {
            if ($hit = $find($words)) {
                [$industry, $industryHit] = [$name, $hit];
                break;
            }
        }

        [$budget, $budgetHit] = $this->budget($customer);
        [$timeline, $timelineHit] = $this->timeline($customer);
        $highHit = $find(self::HIGH);
        $lowHit = $find(self::LOW);
        [$meetingAt, $meetingType, $meetingHit] = $this->meeting($customer);

        $intent = $lowHit ? 'low' : ($highHit || $meetingHit ? 'high' : ($service ? 'medium' : 'unknown'));
        $intentHit = $lowHit ?? $highHit ?? $meetingHit ?? $serviceHit;
        $temperature = $lowHit ? 'cold' : (($highHit || $meetingHit) ? 'hot' : ($service ? 'warm' : 'unknown'));
        $qualification = $lowHit ? 'not_qualified' : ($service && ($budget || $timeline) ? 'qualified' : ($service ? 'needs_info' : 'unknown'));

        $parts = array_filter([
            $service ? 'Interested in '.$service : 'Service not clear yet',
            $industry ? 'for a '.strtolower($industry).' business' : null,
            $budget ? 'budget '.$budget : null,
            $timeline ? 'timeline: '.$timeline : null,
            $meetingAt ? 'agreed to a '.$meetingType.' meeting on '.$meetingAt : null,
            $lowHit ? 'customer shows low interest' : null,
        ]);

        $actions = [];
        if ($meetingAt) {
            $actions[] = 'Attend the '.$meetingType.' meeting on '.$meetingAt;
        }
        if ($temperature === 'hot' && ! $meetingAt) {
            $actions[] = 'Call the customer today and book a meeting';
        }
        if ($service && ! $budget) {
            $actions[] = 'Ask for the budget';
        }
        if ($service && ! $timeline) {
            $actions[] = 'Ask when they want to start';
        }
        if ($temperature === 'cold') {
            $actions[] = 'Send a polite follow-up in a few weeks';
        }

        $tags = array_values(array_filter([
            $service ? explode(' ', $service)[0] : null,
            $timelineHit && preg_match('/urgent|asap|jaldi|eid/i', $timelineHit[1]) ? 'Urgent' : null,
            $lowHit && preg_match('/mehnga|expensive/i', $lowHit[1]) ? 'Price sensitive' : null,
        ]));

        return [
            'summary' => ucfirst(implode(', ', $parts)).'. (Offline rule-based analysis of '.count($customer).' customer message(s).)',
            'language' => 'English / Roman Urdu',
            'service_interest' => $fact($service, $serviceHit),
            'budget' => $fact($budget, $budgetHit),
            'timeline' => $fact($timeline, $timelineHit),
            'industry' => $fact($industry, $industryHit),
            'buying_intent' => ['level' => $intent, 'evidence' => $intentHit && $intent !== 'unknown' ? [['message_id' => $intentHit[0], 'quote' => $intentHit[1]]] : []],
            'temperature' => ['value' => $temperature, 'reason' => $lowHit ? 'Customer is not interested' : ($temperature === 'hot' ? 'Asked for price / proposal / a call' : 'Interested in a service'),
                'evidence' => $intentHit && $temperature !== 'unknown' ? [['message_id' => $intentHit[0], 'quote' => $intentHit[1]]] : []],
            'qualification' => ['value' => $qualification, 'reason' => $qualification === 'qualified' ? 'Service and budget/timeline known' : 'Missing details'],
            'recommended_status' => $qualification === 'qualified' ? 'qualified' : null,
            'recommended_actions' => $actions,
            'follow_up_in_days' => $temperature === 'hot' ? 1 : ($temperature === 'warm' ? 2 : 14),
            'meeting_request' => ['value' => $meetingAt, 'type' => $meetingType ?: 'phone', 'evidence' => $meetingHit ? [['message_id' => $meetingHit[0], 'quote' => $meetingHit[1]]] : []],
            'tags' => $tags,
            'confidence' => min(0.9, 0.45 + ($service ? 0.15 : 0) + ($budget ? 0.1 : 0) + ($timeline ? 0.05 : 0) + (($highHit || $lowHit || $meetingHit) ? 0.15 : 0)),
        ];
    }

    protected function budget(array $customer): array
    {
        foreach ($customer as $msg) {
            if (preg_match('/(?:budget|bajat|rs\.?|pkr)\D{0,20}?(\d[\d,\.]*\s*(?:k|lac|lakh|lakhs|million|m)?\b)(?:\s*(?:pkr|rs|rupees))?/iu', $msg[4], $x, PREG_OFFSET_CAPTURE)
                || preg_match('/(\d[\d,\.]*\s*(?:k|lac|lakh|lakhs)\b)/iu', $msg[4], $x, PREG_OFFSET_CAPTURE)) {
                $amount = strtolower(preg_replace('/\s+/', '', $x[1][0]));

                return [$amount.' PKR', [(int) $msg[1], $x[0][0]]];
            }
        }

        return [null, null];
    }

    protected function timeline(array $customer): array
    {
        foreach ($customer as $msg) {
            if (preg_match('/(next month|agle mahine|is mahine|this month|eid se pehle|before eid|asap|urgent|jaldi|\d+\s*(?:-\s*\d+\s*)?(?:weeks?|hafte|haftay|din|days|months?|mahine))/iu', $msg[4], $x)) {
                return [ucfirst($x[1]), [(int) $msg[1], $x[1]]];
            }
        }

        return [null, null];
    }

    /** "kal 4 baje call kar lein", "tomorrow at 11 am meeting", "monday 3 pm zoom" */
    protected function meeting(array $customer): array
    {
        $days = ['aaj' => 0, 'today' => 0, 'kal' => 1, 'tomorrow' => 1, 'parson' => 2, 'parso' => 2];
        $weekdays = ['monday' => 1, 'peer' => 1, 'pir' => 1, 'tuesday' => 2, 'mangal' => 2, 'wednesday' => 3, 'budh' => 3,
            'thursday' => 4, 'jumerat' => 4, 'friday' => 5, 'juma' => 5, 'saturday' => 6, 'hafta' => 6, 'sunday' => 0, 'itwar' => 0];

        foreach (array_reverse($customer) as $msg) {
            $text = $msg[4];
            if (! preg_match('/call|meeting|meet|milte|mil |baat|zoom|visit|aa jaon|aa jayen/i', $text)) {
                continue;
            }
            if (! preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(baje|bajay|bjy|pm|am|o\'?clock)/i', $text, $t, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $base = Carbon::createFromFormat('Y-m-d H:i', $msg[2], config('app.timezone'));
            $date = null;
            $dayPos = null;
            foreach ($days as $word => $add) {
                if (preg_match('/\b'.$word.'\b/i', $text, $d, PREG_OFFSET_CAPTURE)) {
                    $date = $base->copy()->addDays($add);
                    $dayPos = $d[0][1];
                    break;
                }
            }
            if (! $date) {
                foreach ($weekdays as $word => $dow) {
                    if (preg_match('/\b'.$word.'\b/i', $text, $d, PREG_OFFSET_CAPTURE)) {
                        $date = $base->copy()->next($dow);
                        $dayPos = $d[0][1];
                        break;
                    }
                }
            }
            if (! $date) {
                continue;
            }

            $hour = (int) $t[1][0];
            $min = isset($t[2][0]) && $t[2][0] !== '' ? (int) $t[2][0] : 0;
            $suffix = strtolower($t[3][0]);
            if ($suffix === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($suffix === 'am' && $hour === 12) {
                $hour = 0;
            } elseif (! in_array($suffix, ['am', 'pm'], true)) {
                if (preg_match('/subah|morning/i', $text)) {
                    // keep as is
                } elseif (preg_match('/shaam|sham|evening|raat/i', $text) || $hour <= 7) {
                    $hour = $hour < 12 ? $hour + 12 : $hour;
                }
            }
            if ($hour > 23 || $min > 59) {
                continue;
            }

            $type = preg_match('/zoom|google meet|online|video/i', $text) ? 'online' : (preg_match('/visit|office|aa jaon|aa jayen|milte/i', $text) ? 'in_person' : 'phone');
            $start = min($dayPos, $t[0][1]);
            $end = max($dayPos + 1, $t[0][1] + strlen($t[0][0]));
            $quote = substr($text, $start, $end - $start);
            // extend the quote to the end of the day word if it started at the time
            if ($dayPos > $t[0][1]) {
                $quote = substr($text, $t[0][1], ($dayPos - $t[0][1]) + strcspn(substr($text, $dayPos), ' ,.'));
            }

            return [$date->setTime($hour, $min)->format('Y-m-d H:i'), $type, [(int) $msg[1], trim($quote)]];
        }

        return [null, null, null];
    }
}
