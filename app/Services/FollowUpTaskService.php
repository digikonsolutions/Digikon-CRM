<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\User;
use Carbon\CarbonInterface;

class FollowUpTaskService
{
    public function schedule(Lead $lead, CarbonInterface $dueAt, User $by, ?string $title = null, ?string $notes = null, ?int $activityId = null): LeadTask
    {
        $task = LeadTask::create([
            'lead_id' => $lead->id,
            'user_id' => $lead->assigned_to ?? $by->id,
            'created_by' => $by->id,
            'activity_id' => $activityId,
            'title' => $title ?: 'Follow up with '.$lead->display_name,
            'notes' => $notes,
            'due_at' => $dueAt,
            'status' => 'open',
        ]);

        FollowUpClock::refresh($lead);
        $lead->log('follow_up', 'Follow-up scheduled for '.$dueAt->format('d M Y, h:i A').($title ? " — {$title}" : ''), $by->id);

        return $task;
    }

    public function complete(LeadTask $task, User $by, ?string $note = null): LeadTask
    {
        if ($task->status !== 'open') {
            return $task;
        }
        $task->update(['status' => 'done', 'completed_at' => now(), 'completed_by' => $by->id]);
        FollowUpClock::refresh($task->lead);
        $task->lead->log('follow_up', 'Follow-up completed: '.$task->title.($note ? " — {$note}" : ''), $by->id);

        return $task;
    }

    public function cancel(LeadTask $task, User $by, ?string $reason = null): LeadTask
    {
        if ($task->status !== 'open') {
            return $task;
        }
        $task->update(['status' => 'cancelled']);
        FollowUpClock::refresh($task->lead);
        $task->lead->log('follow_up', 'Follow-up cancelled: '.$task->title.($reason ? " — {$reason}" : ''), $by->id);

        return $task;
    }

    /** Close the lead's open tasks that are due now (used when an agent logs the follow-up call). */
    public function completeDue(Lead $lead, User $by): int
    {
        $count = 0;
        foreach ($lead->tasks()->where('status', 'open')->where('due_at', '<=', now()->endOfDay())->get() as $task) {
            $this->complete($task, $by);
            $count++;
        }

        return $count;
    }
}
