<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One table for both system timeline entries (created, assigned, followup ...)
 * and sales activities (call, whatsapp, email, meeting, follow_up, note, status_change).
 *
 * is_manual   = typed in by an agent (manual call log etc.)
 * is_verified = confirmed by an integration (e.g. a future telephony system). Manual logs are never "verified".
 */
class LeadActivity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'is_verified' => 'boolean',
            'is_manual' => 'boolean',
            'meta' => 'array',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LeadActivity $a) {
            $a->occurred_at ??= now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function isSalesActivity(): bool
    {
        return array_key_exists($this->type, config('crm.activity_types'));
    }

    public function getTypeLabelAttribute(): string
    {
        return config('crm.activity_types')[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function getOutcomeLabelAttribute(): ?string
    {
        return $this->outcome ? (config('crm.call_outcomes')[$this->outcome] ?? $this->outcome) : null;
    }

    public function getDurationLabelAttribute(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }
        $m = intdiv($this->duration_seconds, 60);
        $s = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $m, $s);
    }
}
