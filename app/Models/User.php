<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /** Notification types a user can switch on/off (in-app + email). */
    public const NOTIFICATION_TYPES = [
        'lead_assigned' => 'A lead is assigned / reassigned to me',
        'new_lead' => 'New inbound lead arrives (managers)',
        'meeting_reminder' => 'Upcoming meeting reminders',
        'meeting_missed' => 'Meeting outcome not updated (missed meeting)',
        'follow_up_overdue' => 'Overdue follow-ups',
        'ingestion_failed' => 'Lead ingestion failed (Meta / landing page)',
        'daily_report' => 'Daily report available',
    ];

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'role',
        'is_active', 'receives_leads', 'last_lead_assigned_at', 'notification_preferences',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_lead_assigned_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'receives_leads' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasPermission(string $permission): bool
    {
        return $this->is_active && Permissions::roleHas((string) $this->role, $permission);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if ($this->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    /** Can this user be the owner of leads? (active + assignable role) */
    public function canOwnLeads(): bool
    {
        return $this->is_active && in_array($this->role, config('crm.assignable_roles', ['agent', 'manager']), true);
    }

    public function getRoleLabelAttribute(): string
    {
        return config('crm.roles')[$this->role] ?? ucfirst((string) $this->role);
    }

    /** In-app channel is on unless switched off; email follows the same flag + the "email" master switch. */
    public function wantsNotification(string $type): bool
    {
        $prefs = $this->notification_preferences ?? [];

        return ($prefs[$type] ?? true) !== false;
    }

    public function wantsEmailNotifications(): bool
    {
        return ($this->notification_preferences['email'] ?? false) === true;
    }

    public function scopeAssignable($query)
    {
        return $query->where('is_active', true)->whereIn('role', config('crm.assignable_roles', ['agent', 'manager']));
    }

    public function leads()
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function tasks()
    {
        return $this->hasMany(LeadTask::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class, 'assigned_agent_id');
    }
}
