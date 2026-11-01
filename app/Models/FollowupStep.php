<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FollowupStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'email' => 'Email'];

    public function getDelayLabelAttribute(): string
    {
        $m = $this->delay_minutes;
        if ($m === 0) return 'Immediately';
        if ($m % 1440 === 0) return ($m / 1440).' day(s) after';
        if ($m % 60 === 0) return ($m / 60).' hour(s) after';
        return $m.' minute(s) after';
    }
}
