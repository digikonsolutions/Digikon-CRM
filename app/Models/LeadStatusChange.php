<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadStatusChange extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
