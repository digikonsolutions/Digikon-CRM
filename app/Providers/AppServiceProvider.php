<?php

namespace App\Providers;

use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\User;
use App\Policies\LeadPolicy;
use App\Policies\MeetingPolicy;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiClientFactory;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolved lazily (only when an analysis runs) so a missing key never breaks the app.
        $this->app->bind(AiClient::class, fn () => (new AiClientFactory)->make());
    }

    public function boot(): void
    {
        Paginator::defaultSimpleView('partials.pagination');
        Paginator::defaultView('partials.pagination');

        // ---- Authorization (no Spatie): every permission is also a Gate → @can('reports.view-team') ----
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Meeting::class, MeetingPolicy::class);
        foreach (array_keys(config('crm.permissions', [])) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        // ---- Public landing-page endpoint: 10 submissions / minute and 60 / hour per IP ----
        RateLimiter::for('public-leads', fn (Request $request) => [
            Limit::perMinute(10)->by('pl-m-'.$request->ip())->response(fn () => response()->json(['ok' => false, 'message' => 'Too many submissions. Please try again in a minute.'], 429)),
            Limit::perHour(60)->by('pl-h-'.$request->ip()),
        ]);

        // Unread notification count for the top bar.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $view->with('unreadNotifications', $user ? $user->unreadNotifications()->count() : 0);
        });

        try {
            // SMTP settings saved from the CRM (Settings > Email) override .env
            if ($host = Setting::get('smtp_host')) {
                $encryption = Setting::get('smtp_encryption', 'tls');
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $host,
                    'mail.mailers.smtp.port' => (int) Setting::get('smtp_port', 587),
                    'mail.mailers.smtp.username' => Setting::get('smtp_username'),
                    'mail.mailers.smtp.password' => Setting::get('smtp_password'),
                    'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                ]);
            }
            if ($from = Setting::get('mail_from_address')) {
                config(['mail.from.address' => $from, 'mail.from.name' => Setting::get('mail_from_name', Setting::get('company_name', config('app.name')))]);
            }

            // Landing-page origins allowed by CORS (Lead Sources → Landing pages).
            if ($origins = Setting::get('public_form_allowed_origins')) {
                config(['cors.allowed_origins' => array_values(array_unique(array_merge(
                    config('cors.allowed_origins', []),
                    array_filter(preg_split('/\s+/', $origins))
                )))]);
            }
        } catch (\Throwable) {
            // settings table not migrated yet
        }

        Permissions::flush();
    }
}
