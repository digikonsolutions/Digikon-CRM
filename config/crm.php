<?php

/*
|--------------------------------------------------------------------------
| Digikon CRM configuration
|--------------------------------------------------------------------------
| Roles & permissions are handled by the CRM itself (no Spatie package).
| These are the DEFAULT permissions per role. Admins can change them from
| Management → Roles & Permissions (saved in the settings table), and the
| saved matrix overrides these defaults.
*/

return [

    'timezone' => env('APP_TIMEZONE', 'Asia/Karachi'),

    'roles' => [
        'admin' => 'Admin',
        'manager' => 'Sales Manager',
        'agent' => 'Sales Agent',
        'marketing' => 'Marketing Manager',
    ],

    // Roles that can own (be assigned) leads.
    'assignable_roles' => ['agent', 'manager'],

    'permissions' => [
        'leads.view-own' => 'View own (assigned) leads',
        'leads.view-all' => 'View all leads',
        'leads.create' => 'Create leads',
        'leads.update' => 'Edit leads (that the user can see)',
        'leads.assign' => 'Assign / reassign / unassign leads',
        'leads.delete' => 'Delete leads',
        'leads.export' => 'Export leads / reports to CSV',
        'activities.create' => 'Log calls & activities',
        'activities.view-own' => 'View own activities',
        'activities.view-team' => 'View whole team activities',
        'reports.view-own' => 'View own sales report',
        'reports.view-team' => 'View team sales reports',
        'meetings.view-own' => 'View own meetings',
        'meetings.view-team' => 'View all team meetings',
        'meetings.manage' => 'Create / reschedule / cancel meetings',
        'conversations.view' => 'View WhatsApp conversations (of visible leads)',
        'lead-analyses.run' => 'Run AI WhatsApp analysis',
        'lead-analyses.review' => 'Approve / correct AI analysis',
        'lead-sources.manage' => 'Lead sources, landing pages & ingestion log',
        'tags.manage' => 'Manage tags',
        'invoices.manage' => 'Create invoices, mark paid / cancelled',
        'users.manage' => 'Manage team / users',
        'audit.view' => 'View audit logs',
        'settings.manage' => 'Manage settings, integrations & permissions',
    ],

    'role_permissions' => [
        'admin' => [
            'leads.view-own', 'leads.view-all', 'leads.create', 'leads.update', 'leads.assign', 'leads.delete', 'leads.export',
            'activities.create', 'activities.view-own', 'activities.view-team',
            'reports.view-own', 'reports.view-team',
            'meetings.view-own', 'meetings.view-team', 'meetings.manage',
            'conversations.view', 'lead-analyses.run', 'lead-analyses.review',
            'lead-sources.manage', 'tags.manage', 'users.manage', 'audit.view', 'settings.manage', 'invoices.manage',
        ],
        'manager' => [
            'leads.view-own', 'leads.view-all', 'leads.create', 'leads.update', 'leads.assign', 'leads.export',
            'activities.create', 'activities.view-own', 'activities.view-team',
            'reports.view-own', 'reports.view-team',
            'meetings.view-own', 'meetings.view-team', 'meetings.manage',
            'conversations.view', 'lead-analyses.run', 'lead-analyses.review', 'tags.manage', 'invoices.manage',
        ],
        'agent' => [
            'leads.view-own', 'leads.create', 'leads.update',
            'activities.create', 'activities.view-own',
            'reports.view-own',
            'meetings.view-own', 'meetings.manage',
            'conversations.view', 'lead-analyses.run',
        ],
        'marketing' => [
            'leads.view-all', 'leads.export',
            'reports.view-team',
            'lead-sources.manage', 'tags.manage',
        ],
    ],

    // ---- Sales activities ----
    'activity_types' => [
        'call' => 'Call',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'meeting' => 'Meeting',
        'follow_up' => 'Follow-up',
        'note' => 'Note',
        'status_change' => 'Status change',
    ],

    // Activity types an agent can log by hand from the lead page.
    'loggable_activity_types' => ['call', 'whatsapp', 'email', 'meeting', 'follow_up', 'note'],

    // "Contact" activities used for "unique leads contacted" and response time.
    'contact_activity_types' => ['call', 'whatsapp', 'email', 'meeting'],

    'call_outcomes' => [
        'answered' => 'Answered',
        'no_answer' => 'No answer',
        'busy' => 'Busy',
        'callback_requested' => 'Callback requested',
        'interested' => 'Interested',
        'not_interested' => 'Not interested',
        'meeting_booked' => 'Meeting booked',
    ],

    // Outcomes that mean the customer actually picked up the phone.
    'connected_call_outcomes' => ['answered', 'callback_requested', 'interested', 'not_interested', 'meeting_booked'],

    // ---- Segmentation ----
    'temperatures' => ['hot' => 'Hot', 'warm' => 'Warm', 'cold' => 'Cold'],

    'qualifications' => [
        'pending' => 'Not reviewed',
        'qualified' => 'Qualified',
        'needs_info' => 'Needs more info',
        'not_qualified' => 'Not qualified',
    ],

    'buying_intents' => ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'unknown' => 'Unknown'],

    // ---- Meetings ----
    'meeting_types' => ['online' => 'Online', 'phone' => 'Phone', 'in_person' => 'In-person'],
    'meeting_statuses' => [
        'scheduled' => 'Scheduled',
        'rescheduled' => 'Rescheduled',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no_show' => 'No-show',
    ],

    // ---- AI (WhatsApp conversation analysis) ----
    'ai' => [
        // Values from Settings → AI override these .env defaults.
        'provider' => env('AI_PROVIDER', 'anthropic'),   // anthropic | openai
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL'),
        'max_messages' => 150,       // last N WhatsApp messages sent to the AI
        'max_chars' => 24000,        // hard cap for the transcript
        'auto_apply_min_confidence' => 0.7,
        'debounce_minutes' => 10,    // wait after the last customer message before auto analysis
    ],

    // Run "queue:work --stop-when-empty" from the scheduler every minute.
    // Keep true on shared hosting (only a cron for schedule:run).
    // Set CRM_SCHEDULER_RUNS_QUEUE=false if you run a dedicated queue worker (Supervisor).
    'scheduler_runs_queue' => env('CRM_SCHEDULER_RUNS_QUEUE', true),
];
