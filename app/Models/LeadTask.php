<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Manual follow-up (call back on date X). Separate from automated WhatsApp follow-ups (lead_followups). */
class LeadTask extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen($q)
    {
        return $q->where('status', 'open');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'open' && $this->due_at->isPast();
    }
}
