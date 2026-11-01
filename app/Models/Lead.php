<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    /** Sales status (pipeline stage). Kept separate from temperature and qualification. */
    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'meeting_done' => 'Meeting Done',
        'proposal' => 'Proposal Sent',
        'negotiation' => 'Negotiation',
        'won' => 'Won',
        'lost' => 'Lost',
    ];

    public const CLOSED_STATUSES = ['won', 'lost'];

    public const SOURCES = [
        'meta' => 'Meta (Facebook/Instagram)',
        'website' => 'Website / Landing page',
        'whatsapp' => 'WhatsApp',
        'referral' => 'Referral',
        'manual' => 'Manual',
    ];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'assigned_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'last_reply_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'followups_paused' => 'boolean',
            'whatsapp_opt_out' => 'boolean',
            'score' => 'integer',
        ];
    }

    // ---------------- relationships ----------------

    public function agent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function followups()
    {
        return $this->hasMany(LeadFollowup::class);
    }

    public function messages()
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(WhatsappMessage::class)->latestOfMany();
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    public function assignments()
    {
        return $this->hasMany(LeadAssignment::class);
    }

    public function statusChanges()
    {
        return $this->hasMany(LeadStatusChange::class);
    }

    public function tasks()
    {
        return $this->hasMany(LeadTask::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }

    public function analyses()
    {
        return $this->hasMany(LeadAnalysis::class);
    }

    public function latestAnalysis()
    {
        return $this->hasOne(LeadAnalysis::class)->latestOfMany();
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class)->withPivot('source', 'added_by')->withTimestamps();
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function sourceEvents()
    {
        return $this->hasMany(LeadSourceEvent::class);
    }

    // ---------------- helpers ----------------

    /** System timeline entry (kept for backward compatibility with existing code). */
    public function log(string $type, string $description, ?int $userId = null): LeadActivity
    {
        return $this->activities()->create([
            'type' => $type,
            'description' => $description,
            'user_id' => $userId ?? auth()->id(),
            'occurred_at' => now(),
        ]);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    // ---------------- scopes ----------------

    /** Leads the user is allowed to see (policy-equivalent query scope). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('leads.view-all')) {
            return $query;
        }
        if ($user->hasPermission('leads.view-own')) {
            return $query->where('leads.assigned_to', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('leads.status', self::CLOSED_STATUSES);
    }

    // ---------------- accessors ----------------

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function getTemperatureLabelAttribute(): ?string
    {
        return $this->temperature ? (config('crm.temperatures')[$this->temperature] ?? ucfirst($this->temperature)) : null;
    }

    public function getQualificationLabelAttribute(): string
    {
        return config('crm.qualifications')[$this->qualification ?? 'pending'] ?? ucfirst((string) $this->qualification);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: ($this->phone ?: 'Lead #'.$this->id);
    }

    public function getIsFollowUpOverdueAttribute(): bool
    {
        return $this->next_follow_up_at !== null && $this->next_follow_up_at->isPast() && ! $this->isClosed();
    }
}
