<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    protected function involves(User $user, Meeting $meeting): bool
    {
        return (int) $meeting->assigned_agent_id === (int) $user->id || (int) $meeting->organizer_id === (int) $user->id;
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission('meetings.view-team')
            || ($user->hasPermission('meetings.view-own') && $this->involves($user, $meeting));
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission('meetings.manage')
            && ($user->hasPermission('meetings.view-team') || $this->involves($user, $meeting));
    }

    public function cancel(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }
}
