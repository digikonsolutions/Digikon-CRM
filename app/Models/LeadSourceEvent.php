<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable log of every inbound lead event (Meta leadgen webhook, landing-page submission). */
class LeadSourceEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attribution' => 'array',
            'processed_at' => 'datetime',
            'failure_notified_at' => 'datetime',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
