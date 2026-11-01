<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;

/**
 * Kept for backward compatibility. All logic now lives in LeadAssignmentService
 * (history, row locking, eligibility checks, notifications).
 */
class LeadDistributor
{
    public const METHODS = LeadAssignmentService::AUTO_METHODS;

    public function __construct(protected LeadAssignmentService $assignments) {}

    public function assign(Lead $lead): ?User
    {
        return $this->assignments->autoAssign($lead);
    }

    public function assignTo(Lead $lead, User $agent, ?int $byUserId): void
    {
        $this->assignments->assign($lead, $agent, $byUserId);
    }
}
