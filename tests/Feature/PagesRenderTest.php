<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every page renders for every role (or is correctly forbidden) with realistic data. */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoDataSeeder::class);
    }

    public function test_all_pages_render_per_role(): void
    {
        $usman = Lead::where('name', 'Usman Tariq')->first();
        $meeting = Meeting::where('lead_id', $usman->id)->first();
        $ali = User::where('email', 'ali@crm.test')->first();

        $pages = [
            'dashboard' => route('dashboard'),
            'leads' => route('leads.index'),
            'my leads' => route('leads.index', ['scope' => 'mine']),
            'unassigned' => route('leads.index', ['scope' => 'unassigned']),
            'filtered leads' => route('leads.index', ['temperature' => 'hot', 'sort' => 'score', 'q' => 'Usman', 'overdue' => 1]),
            'lead' => route('leads.show', $usman),
            'lead edit' => route('leads.edit', $usman),
            'pipeline' => route('leads.pipeline'),
            'conversations' => route('conversations.index', ['filter' => 'review']),
            'conversation' => route('conversations.show', $usman),
            'ai reviews' => route('analyses.index'),
            'activities' => route('activities.index'),
            'follow-ups' => route('tasks.index'),
            'follow-ups upcoming' => route('tasks.index', ['tab' => 'upcoming']),
            'calendar month' => route('meetings.index'),
            'calendar week' => route('meetings.index', ['view' => 'week']),
            'calendar day' => route('meetings.index', ['view' => 'day', 'date' => now()->addDay()->toDateString()]),
            'calendar agenda' => route('meetings.index', ['view' => 'agenda']),
            'meeting' => route('meetings.show', $meeting),
            'reports daily' => route('reports.index'),
            'reports weekly' => route('reports.index', ['period' => 'weekly']),
            'reports monthly' => route('reports.index', ['period' => 'monthly']),
            'reports custom' => route('reports.index', ['period' => 'custom', 'from' => now()->subDays(10)->toDateString(), 'to' => now()->toDateString()]),
            'proposals' => route('proposals.index'),
            'notifications' => route('notifications.index'),
            'tags' => route('tags.index'),
            'lead sources' => route('lead-sources.index'),
            'audit' => route('audit.index'),
            'users' => route('users.index'),
            'roles' => route('roles.index'),
            'settings' => route('settings.index'),
            'settings ai' => route('settings.index', ['tab' => 'ai']),
            'settings meta' => route('settings.index', ['tab' => 'meta']),
            'whatsapp automation' => route('followups.index'),
            'followup email form' => route('followups.create', ['channel' => 'email']),
            'invoices' => route('invoices.index'),
            'packages' => route('packages.index'),
            'package form' => route('packages.create'),
            'settings automation' => route('settings.index', ['tab' => 'automation']),
        ];

        $forbidden = [
            'admin' => [],
            'manager' => ['lead sources', 'audit', 'users', 'roles', 'settings', 'settings ai', 'settings meta', 'whatsapp automation', 'followup email form', 'packages', 'package form', 'settings automation'],
            'agent' => ['unassigned', 'ai reviews', 'tags', 'lead sources', 'audit', 'users', 'roles', 'settings', 'settings ai', 'settings meta', 'whatsapp automation', 'followup email form', 'packages', 'package form', 'settings automation'],
            'marketing' => ['unassigned', 'lead edit', 'conversations', 'conversation', 'ai reviews', 'activities', 'calendar month', 'calendar week', 'calendar day', 'calendar agenda', 'meeting', 'audit', 'users', 'roles', 'settings', 'settings ai', 'settings meta', 'whatsapp automation', 'followup email form', 'packages', 'package form', 'settings automation'],
        ];

        $users = [
            'admin' => User::where('email', 'admin@crm.test')->first(),
            'manager' => User::where('email', 'manager@crm.test')->first(),
            'agent' => $ali,
            'marketing' => $this->user('marketing'),
        ];

        foreach ($users as $role => $user) {
            foreach ($pages as $name => $url) {
                $res = $this->actingAs($user)->get($url);
                $expected = in_array($name, $forbidden[$role], true) ? 403 : 200;
                // agents may land on "my leads" scope; follow-ups page is allowed for all
                $this->assertSame($expected, $res->status(), "{$role} → {$name} ({$url}) returned {$res->status()}");
            }
        }
    }

    public function test_csv_exports(): void
    {
        $admin = User::where('email', 'admin@crm.test')->first();
        $csv = $this->actingAs($admin)->get(route('leads.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Usman Tariq', $csv);
        $report = $this->actingAs($admin)->get(route('reports.export', ['period' => 'weekly']))->assertOk()->streamedContent();
        $this->assertStringContainsString('TEAM TOTAL', $report);
        $this->actingAs(User::where('email', 'ali@crm.test')->first())->get(route('leads.export'))->assertForbidden();
    }
}
