<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AI analysis of a lead's WhatsApp conversation. Stored separately from the original messages. */
class LeadAnalysis extends Model
{
    public const REVIEW_STATUSES = [
        'pending' => 'Waiting for review',
        'approved' => 'Approved',
        'edited' => 'Corrected & applied',
        'rejected' => 'Rejected',
        'auto_applied' => 'Auto-applied (safe fields)',
    ];

    /** Fields the analysis may write to the lead (only with evidence and after review / safe auto-apply). */
    public const APPLICABLE_FIELDS = ['service_interest', 'budget', 'timeline', 'industry', 'buying_intent', 'temperature', 'qualification', 'tags'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'recommended_actions' => 'array',
            'tags' => 'array',
            'evidence' => 'array',
            'rejected_fields' => 'array',
            'applied_fields' => 'array',
            'confidence' => 'float',
            'reviewed_at' => 'datetime',
            'meeting_at' => 'datetime',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function evidenceFor(string $field): array
    {
        return $this->evidence[$field] ?? [];
    }

    public function getReviewLabelAttribute(): string
    {
        return self::REVIEW_STATUSES[$this->review_status] ?? $this->review_status;
    }
}
