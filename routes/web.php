<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FollowupStepController;
use App\Http\Controllers\LeadActivityController;
use App\Http\Controllers\LeadAnalysisController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadSourceController;
use App\Http\Controllers\LeadTaskController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// ---- Public webhooks (Meta Lead Ads + WhatsApp Cloud API) ----
Route::get('webhooks/meta', [WebhookController::class, 'verify']);
Route::post('webhooks/meta', [WebhookController::class, 'meta'])->name('webhooks.meta')->middleware('throttle:600,1');
Route::get('webhooks/whatsapp', [WebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WebhookController::class, 'whatsapp'])->name('webhooks.whatsapp')->middleware('throttle:1200,1');

// ---- Auth ----
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::post('logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// ---- CRM ----
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Leads
    Route::get('leads/pipeline', [LeadController::class, 'pipeline'])->name('leads.pipeline');
    Route::get('leads/export', [LeadController::class, 'export'])->name('leads.export');
    Route::post('leads/distribute', [LeadController::class, 'distributeUnassigned'])->name('leads.distribute');
    Route::post('leads/bulk-assign', [LeadController::class, 'bulkAssign'])->name('leads.bulk-assign');
    Route::resource('leads', LeadController::class);
    Route::post('leads/{lead}/status', [LeadController::class, 'status'])->name('leads.status');
    Route::post('leads/{lead}/segment', [LeadController::class, 'segment'])->name('leads.segment');
    Route::post('leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
    Route::post('leads/{lead}/note', [LeadController::class, 'note'])->name('leads.note');
    Route::post('leads/{lead}/whatsapp', [LeadController::class, 'whatsapp'])->name('leads.whatsapp');
    Route::post('leads/{lead}/followups', [LeadController::class, 'toggleFollowups'])->name('leads.followups');

    // Sales activities & manual follow-ups
    Route::get('activities', [LeadActivityController::class, 'index'])->name('activities.index');
    Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->name('leads.activities.store');
    Route::get('follow-ups', [LeadTaskController::class, 'index'])->name('tasks.index');
    Route::post('leads/{lead}/tasks', [LeadTaskController::class, 'store'])->name('leads.tasks.store');
    Route::post('tasks/{task}', [LeadTaskController::class, 'update'])->name('tasks.update');

    // Meetings calendar
    Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');
    Route::get('meetings/{meeting}', [MeetingController::class, 'show'])->name('meetings.show');
    Route::get('meetings/{meeting}/ics', [MeetingController::class, 'ics'])->name('meetings.ics');
    Route::post('leads/{lead}/meetings', [MeetingController::class, 'store'])->name('leads.meetings.store');
    Route::post('meetings/{meeting}', [MeetingController::class, 'update'])->name('meetings.update');

    // WhatsApp conversations + AI segregation
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('conversations/{lead}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::get('ai-reviews', [LeadAnalysisController::class, 'index'])->name('analyses.index');
    Route::post('leads/{lead}/analyses', [LeadAnalysisController::class, 'store'])->name('leads.analyses.store')->middleware('throttle:20,1');
    Route::post('analyses/{analysis}/approve', [LeadAnalysisController::class, 'approve'])->name('analyses.approve');
    Route::post('analyses/{analysis}/reject', [LeadAnalysisController::class, 'reject'])->name('analyses.reject');

    // Reports
    Route::get('reports', [SalesReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [SalesReportController::class, 'export'])->name('reports.export');

    // Proposals
    Route::resource('proposals', ProposalController::class);
    Route::get('proposals/{proposal}/pdf', [ProposalController::class, 'pdf'])->name('proposals.pdf');
    Route::post('proposals/{proposal}/send', [ProposalController::class, 'send'])->name('proposals.send');
    Route::post('proposals/{proposal}/status', [ProposalController::class, 'status'])->name('proposals.status');

    // Invoices
    Route::get('invoices', [\App\Http\Controllers\InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [\App\Http\Controllers\InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [\App\Http\Controllers\InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::post('invoices/{invoice}/send', [\App\Http\Controllers\InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('invoices/{invoice}/status', [\App\Http\Controllers\InvoiceController::class, 'status'])->name('invoices.status');
    Route::post('proposals/{proposal}/invoice', [\App\Http\Controllers\InvoiceController::class, 'store'])->name('proposals.invoice');
    Route::post('meetings/{meeting}/confirm', [MeetingController::class, 'confirm'])->name('meetings.confirm');

    // Notifications (own)
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');

    // Management
    Route::middleware('permission:tags.manage')->group(function () {
        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    });
    Route::middleware('permission:lead-sources.manage')->group(function () {
        Route::get('lead-sources', [LeadSourceController::class, 'index'])->name('lead-sources.index');
        Route::post('lead-sources/settings', [LeadSourceController::class, 'settings'])->name('lead-sources.settings');
        Route::post('lead-sources/{event}/retry', [LeadSourceController::class, 'retry'])->name('lead-sources.retry');
    });
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index')->middleware('permission:audit.view');
    Route::resource('users', UserController::class)->except('show')->middleware('permission:users.manage');

    Route::middleware('permission:settings.manage')->group(function () {
        Route::post('followups/toggle', [FollowupStepController::class, 'toggle'])->name('followups.toggle');
        Route::resource('followups', FollowupStepController::class)->except('show');
        Route::resource('packages', \App\Http\Controllers\ServicePackageController::class)->except('show');
        Route::get('roles', [RolePermissionController::class, 'index'])->name('roles.index');
        Route::post('roles', [RolePermissionController::class, 'update'])->name('roles.update');
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/test/{what}', [SettingController::class, 'test'])->name('settings.test');
    });
});
