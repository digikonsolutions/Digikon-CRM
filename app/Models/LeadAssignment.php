<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadAssignment extends Model
{
    public const METHODS = [
        'manual' => 'Manual',
        'bulk' => 'Bulk',
        'round_robin' => 'Round robin',
        'least_leads' => 'Workload (least open leads)',
        'creator' => 'Created by agent',
        'import' => 'Before upgrade',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function unassigner()
    {
        return $this->belongsTo(User::class, 'unassigned_by');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('unassigned_at');
    }
}
