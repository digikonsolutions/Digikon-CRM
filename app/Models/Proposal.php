<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    public const TEMPLATES = [
        'modern' => 'Modern (colored header)',
        'classic' => 'Classic (formal letter)',
        'minimal' => 'Minimal (clean & simple)',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getSubtotalAttribute(): float
    {
        return collect($this->items ?? [])->sum(fn ($i) => (float) ($i['qty'] ?? 0) * (float) ($i['price'] ?? 0));
    }

    public function getTaxAmountAttribute(): float
    {
        return round(max($this->subtotal - $this->discount, 0) * $this->tax_percent / 100, 2);
    }

    public function calculateTotal(): float
    {
        return round(max($this->subtotal - $this->discount, 0) + $this->tax_amount, 2);
    }

    public static function nextNumber(): string
    {
        $prefix = Setting::get('proposal_prefix', 'PRP');
        $next = (static::max('id') ?? 0) + 1;

        return $prefix.'-'.now()->format('Y').'-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function money($amount): string
    {
        return $this->currency.' '.number_format((float) $amount, 2);
    }
}
