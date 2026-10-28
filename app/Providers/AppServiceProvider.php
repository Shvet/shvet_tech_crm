<?php

namespace App\Providers;

use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\MetaConnection;
use App\Models\MetaFormMapping;
use App\Models\MetaLeadEvent;
use App\Models\MetaPage;
use App\Policies\MetaIntegrationPolicy;
use App\Services\Leads\LeadVisibility;
use App\Support\Facades\Tenancy;
use App\Support\ProductionSafeguards;
use App\Tenancy\TenancyManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenancyManager::class);
    }

    public function boot(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers();

            // Breached-password lookup calls an external API; keep it to production.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Public ids are ULIDs: anything else is a 404 before it touches the database (also keeps /leads/create unambiguous).
        Route::pattern('lead', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::pattern('task', '[0-9A-HJKMNP-TV-Z]{26}');
        foreach (['metaConnection', 'metaPage', 'metaMapping', 'metaEvent'] as $param) {
            Route::pattern($param, '[0-9A-HJKMNP-TV-Z]{26}');
        }
        // Tenant-scoped by the models' global scope (ResolveTenant runs before binding): foreign ids are 404s.
        Route::model('metaConnection', MetaConnection::class);
        Route::model('metaPage', MetaPage::class);
        Route::model('metaMapping', MetaFormMapping::class);
        Route::model('metaEvent', MetaLeadEvent::class);
        Gate::policy(MetaConnection::class, MetaIntegrationPolicy::class);

        // Resolve through VISIBILITY, inside the tenant: a lead/task the user may not see is indistinguishable from a missing one.
        Route::bind('lead', function (string $value) {
            $user = auth()->user() ?? abort(401);

            return LeadVisibility::scope(Lead::query(), $user, includeArchived: true)->where('leads.public_id', $value)->firstOrFail();
        });
        Route::bind('task', function (string $value) {
            $user = auth()->user() ?? abort(401);

            return FollowUpTask::query()
                ->whereIn('follow_up_tasks.lead_id', LeadVisibility::scope(Lead::query(), $user, includeArchived: true)->select('leads.id'))
                ->where('follow_up_tasks.public_id', $value)->firstOrFail();
        });

        // Page props live in the browser's history state: encrypt them, and drop the key on logout, so the back
        // button cannot reveal the previous user's pages on a shared computer.
        Inertia::encryptHistory();

        // Named limiters have their own buckets. (Unnamed `throttle:N,M` shares ONE counter per client IP across
        // every route that uses it, so login failures would eat into the password-reset budget and vice versa.)
        // Meta webhooks: signature checking is the real gate; these only cap abuse per source IP.
        RateLimiter::for('meta-webhook', fn (Request $request) => Limit::perMinute(600)->by('meta-webhook|'.$request->ip()));
        // Admin-initiated calls that hit Meta's API: per user, so a stuck button cannot burn the app's rate budget.
        RateLimiter::for('meta-admin', fn (Request $request) => Limit::perMinute(20)->by('meta-admin|'.Tenancy::id().'|'.$request->user()?->getKey()));
        RateLimiter::for('meta-webhook-verify', fn (Request $request) => Limit::perMinute(30)->by('meta-webhook-verify|'.$request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by('login|'.$request->ip()));
        RateLimiter::for('password-reset-request', fn (Request $request) => [
            Limit::perMinute(6)->by('pwreq|'.$request->ip()),
            // Caps e-mail bombing of one mailbox regardless of source IP; same answer whether or not the account exists.
            Limit::perHour(5)->by('pwreq-mail|'.sha1(strtolower((string) $request->input('email')))),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(6)->by('pwreset|'.$request->ip()));

        // Invitations send e-mail to third parties: cap them per inviter per workspace.
        RateLimiter::for('invitations', fn (Request $request) => Limit::perHour((int) config('crm.invitations_per_hour'))
            ->by('invite|'.Tenancy::id().'|'.$request->user()?->getKey()));

        // Fail closed on unsafe production configuration (HTTP only: console must stay usable to fix it).
        if (! $this->app->runningInConsole() && $this->app->isProduction()) {
            $critical = ProductionSafeguards::critical();
            if ($critical !== []) {
                throw new \RuntimeException('Unsafe production configuration: '.implode('; ', $critical));
            }
        }

        // A long-running worker must never carry tenant context from one job into the next.
        // (The sync driver runs jobs inside the dispatching request, whose context must survive.)
        Queue::looping(fn () => Tenancy::clear());
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                Tenancy::clear();
            }
        });
    }
}
