<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    public const ACTIVE_STATUSES = ['scheduled', 'rescheduled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'missed_notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->hasPermission('meetings.view-team')) {
            return $q;
        }
        if ($user->hasPermission('meetings.view-own')) {
            return $q->where(fn ($w) => $w->where('assigned_agent_id', $user->id)->orWhere('organizer_id', $user->id));
        }

        return $q->whereRaw('1 = 0');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return config('crm.meeting_statuses')[$this->status] ?? ucfirst($this->status);
    }

    public function getTypeLabelAttribute(): string
    {
        return config('crm.meeting_types')[$this->meeting_type] ?? ucfirst($this->meeting_type);
    }
}
