<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['wa_timestamp' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** customer | agent | automation */
    public function getSenderTypeAttribute(): string
    {
        if ($this->direction === 'in') {
            return 'customer';
        }

        return $this->user_id ? 'agent' : 'automation';
    }

    public function getSentAtAttribute()
    {
        return $this->wa_timestamp ?? $this->created_at;
    }
}
