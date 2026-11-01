<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function view(User $user, Lead $lead): bool
    {
        if ($user->hasPermission('leads.view-all')) {
            return true;
        }

        return $user->hasPermission('leads.view-own') && $lead->assigned_to !== null && (int) $lead->assigned_to === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->hasPermission('leads.update') && $this->view($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->hasPermission('leads.delete');
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $user->hasPermission('leads.assign') && $this->view($user, $lead);
    }

    public function logActivity(User $user, Lead $lead): bool
    {
        return $user->hasPermission('activities.create') && $this->view($user, $lead);
    }

    public function scheduleMeeting(User $user, Lead $lead): bool
    {
        return $user->hasPermission('meetings.manage') && $this->view($user, $lead);
    }

    public function viewConversation(User $user, Lead $lead): bool
    {
        return $user->hasPermission('conversations.view') && $this->view($user, $lead);
    }

    public function runAnalysis(User $user, Lead $lead): bool
    {
        return $user->hasPermission('lead-analyses.run') && $this->view($user, $lead);
    }

    public function reviewAnalysis(User $user, Lead $lead): bool
    {
        return $user->hasPermission('lead-analyses.review') && $this->view($user, $lead);
    }
}
