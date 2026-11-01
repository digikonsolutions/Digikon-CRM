<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use App\Notifications\CrmAlert;

class LeadService
{
    /** Fields a repeat enquiry may fill on the existing lead — only when they are still empty. */
    protected const FILLABLE_ON_DUPLICATE = [
        'name', 'email', 'company', 'city', 'service', 'budget',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'landing_page_url', 'referrer',
    ];

    public function __construct(
        protected LeadAssignmentService $assignments,
        protected FollowupService $followups,
    ) {}

    /**
     * Single entry point for every new lead (Meta, landing page, WhatsApp, manual).
     *
     * DUPLICATE POLICY (explicit):
     *  - Same meta_lead_id → the existing lead is returned (webhook retry).
     *  - An OPEN lead (not won/lost) with the same phone (last 10 digits) OR the same email
     *    (case-insensitive) → no new lead; the enquiry is logged on the existing lead and only
     *    its EMPTY fields are filled.
     *  - A matching lead that is already won/lost → a NEW lead is created (new opportunity).
     *  - Names are never used for matching.
     *
     * Check $lead->wasRecentlyCreated to know whether a new lead was created.
     */
    public function create(array $data, ?int $userId = null, bool $autoAssign = true, string $assignMethod = 'manual'): Lead
    {
        if (! empty($data['meta_lead_id']) && $existing = Lead::where('meta_lead_id', $data['meta_lead_id'])->first()) {
            $existing->wasRecentlyCreated = false;

            return $existing;
        }

        $data['phone'] = PhoneNumber::normalize($data['phone'] ?? null);
        if (! empty($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        $ownerId = $data['assigned_to'] ?? null;
        unset($data['assigned_to'], $data['assigned_at'], $data['assigned_by']);

        if ($duplicate = $this->findOpenDuplicate($data['phone'] ?? null, $data['email'] ?? null)) {
            $from = $data['campaign_name'] ?? $data['utm_campaign'] ?? (Lead::SOURCES[$data['source'] ?? 'manual'] ?? 'manual');
            $fill = [];
            foreach (self::FILLABLE_ON_DUPLICATE as $field) {
                if (blank($duplicate->{$field}) && filled($data[$field] ?? null)) {
                    $fill[$field] = $data[$field];
                }
            }
            if ($fill) {
                $duplicate->update($fill);
            }
            $duplicate->log('duplicate', "Repeat enquiry received ({$from}). No duplicate lead created."
                .($fill ? ' Filled empty fields: '.implode(', ', array_keys($fill)).'.' : '')
                .(filled($data['notes'] ?? null) ? "\nMessage: ".mb_substr($data['notes'], 0, 1000) : ''), $userId);
            $duplicate->wasRecentlyCreated = false;

            return $duplicate;
        }

        $lead = Lead::create($data);
        $lead->log('created', 'Lead received via '.(Lead::SOURCES[$lead->source] ?? $lead->source)
            .($lead->campaign_name ? " — campaign: {$lead->campaign_name}" : ''), $userId);
        AuditLogger::log('lead.created', $lead, [], ['source' => $lead->source], null, $userId);

        if ($ownerId && $owner = User::find($ownerId)) {
            $this->assignments->assign($lead, $owner, $userId, null, $assignMethod, notify: (int) $ownerId !== (int) $userId);
        } elseif ($autoAssign) {
            $this->assignments->autoAssign($lead);
        }

        $lead = $lead->fresh();
        $this->followups->scheduleFor($lead);
        app(LeadScoreService::class)->refresh($lead);

        if ($lead->source !== 'manual') {
            Notifier::send(Notifier::usersWithPermission('leads.assign'), 'new_lead', new CrmAlert(
                'new_lead',
                'New lead: '.$lead->display_name,
                (Lead::SOURCES[$lead->source] ?? $lead->source).($lead->campaign_name ? ' · '.$lead->campaign_name : '')
                    .' · '.($lead->agent ? 'assigned to '.$lead->agent->name : 'UNASSIGNED'),
                route('leads.show', $lead),
            ));
        }

        $lead = $lead->fresh();
        $lead->wasRecentlyCreated = true;

        return $lead;
    }

    public function findOpenDuplicate(?string $phone, ?string $email): ?Lead
    {
        $tail = PhoneNumber::tail($phone);
        $email = $email ? mb_strtolower(trim($email)) : null;
        if (! $tail && ! $email) {
            return null;
        }

        return Lead::whereNotIn('status', Lead::CLOSED_STATUSES)
            ->where(function ($q) use ($tail, $email) {
                if ($tail) {
                    $q->orWhere('phone', 'like', '%'.$tail);
                }
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->oldest('id')
            ->first();
    }

    public function findByPhone(?string $phone): ?Lead
    {
        $tail = PhoneNumber::tail($phone);

        return $tail ? Lead::where('phone', 'like', '%'.$tail)->latest()->first() : null;
    }
}
