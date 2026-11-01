<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'sent' => 'Sent', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'subtotal' => 'float',
            'discount' => 'float',
            'tax_percent' => 'float',
            'total' => 'float',
            'advance_percent' => 'float',
            'amount_due' => 'float',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getTaxAmountAttribute(): float
    {
        return round(max($this->subtotal - $this->discount, 0) * $this->tax_percent / 100, 2);
    }

    public function getBalanceAfterAttribute(): float
    {
        return round(max($this->total - $this->amount_due, 0), 2);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'sent' && $this->due_date && $this->due_date->endOfDay()->isPast();
    }

    public function money($amount): string
    {
        return $this->currency.' '.number_format((float) $amount, 2);
    }

    public static function nextNumber(): string
    {
        $prefix = Setting::get('invoice_prefix', 'INV');
        $next = (static::max('id') ?? 0) + 1;

        return $prefix.'-'.now()->format('Y').'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Same visibility rule as proposals: view-all, creator, or owner of the lead. */
    public function scopeVisibleTo($q, User $user)
    {
        if ($user->hasPermission('leads.view-all')) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('user_id', $user->id)->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)));
    }
}
