<?php

namespace App\Http\Controllers\Meta;

use App\Actions\Meta\CompleteMetaConnection;
use App\Actions\Meta\ConnectMetaPages;
use App\Actions\Meta\DisconnectMeta;
use App\Actions\Meta\StartMetaConnection;
use App\Actions\Meta\SyncMetaForms;
use App\Actions\Meta\ValidateMetaConnection;
use App\Enums\MetaConnectionStatus;
use App\Enums\MetaEventStatus;
use App\Exceptions\MetaApiException;
use App\Exceptions\MetaConnectionException;
use App\Http\Controllers\Controller;
use App\Models\MetaConnection;
use App\Models\MetaFormMapping;
use App\Models\MetaLeadEvent;
use App\Models\MetaPage;
use App\Services\Leads\LeadOptions;
use App\Services\Meta\MetaConfig;
use App\Services\Meta\MetaLog;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin screens for the Meta Lead Ads integration. Every method requires integrations.manage (Admin only).
 * Props are an explicit allow-list: no token, pending blob or raw Meta payload ever reaches the browser.
 */
class MetaIntegrationController extends Controller
{
    public function index(): Response
    {
        $this->authorizeManage();

        $connections = MetaConnection::query()->where('status', '!=', MetaConnectionStatus::Disconnected->value)->orderBy('id')->get();
        $pages = MetaPage::query()->with('mappings')->orderBy('id')->get();
        $events = MetaLeadEvent::query()->with('lead:id,public_id')->orderByDesc('id')->limit(50)->get();
        $counts = MetaLeadEvent::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Integrations/Meta/Index', [
            'setup' => [
                'configured' => MetaConfig::isConfigured(),
                'missing' => MetaConfig::missing(),
                'redirect_uri' => MetaConfig::isConfigured() ? MetaConfig::redirectUri() : null,
                'webhook_url' => MetaConfig::webhookUrl(),
                'permissions' => MetaConfig::requiredPermissions(),
            ],
            'connections' => $connections->map(fn (MetaConnection $c) => [
                'id' => $c->public_id,
                'account_name' => $c->meta_user_name,
                'status' => $c->status->value,
                'last_validated_at' => $c->last_validated_at?->toIso8601String(),
                'last_sync_at' => $c->last_sync_at?->toIso8601String(),
                'data_access_expires_at' => $c->data_access_expires_at?->toIso8601String(),
                'error' => $c->last_error_summary,
                'pending_pages' => $this->pendingPages($c),
            ])->values(),
            'pages' => $pages->map(fn (MetaPage $p) => [
                'id' => $p->public_id,
                'connection_id' => $p->connection?->public_id,
                'name' => $p->name,
                'subscription_status' => $p->subscription_status,
                'subscription_error' => $p->subscription_error,
                'last_sync_at' => $p->last_sync_at?->toIso8601String(),
                'forms' => $p->mappings->sortBy('form_name')->map(fn (MetaFormMapping $m) => [
                    'id' => $m->public_id,
                    'form_id' => $m->form_id,
                    'name' => $m->form_name ?? $m->form_id,
                    'status' => $m->form_status,
                    'enabled' => $m->is_enabled,
                    'lead_source_id' => $m->lead_source_id,
                    'pipeline_stage_id' => $m->pipeline_stage_id,
                    'default_owner_id' => $m->default_owner_id,
                    'field_map' => $m->field_map ?? (object) [],
                    'last_import_at' => $m->last_import_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'counts' => collect(MetaEventStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
            'events' => $events->map(fn (MetaLeadEvent $e) => [
                'id' => $e->public_id,
                'status' => $e->status->value,
                'outcome' => $e->outcome,
                'received_at' => $e->received_at->toIso8601String(),
                'attempts' => $e->attempts,
                'platform' => $e->platform,
                'failure' => $e->failure_summary,
                'warnings' => $e->warnings ?? [],
                'lead_id' => $e->lead?->public_id,
                'replayable' => $e->status->isReplayable(),
                'reviewable' => $e->status === MetaEventStatus::NeedsReview,
            ])->values(),
            'options' => [
                'sources' => LeadOptions::sources(),
                'stages' => LeadOptions::stages(),
                'members' => LeadOptions::members(),
            ],
        ]);
    }

    public function connect(Request $request, StartMetaConnection $start)
    {
        $this->authorizeManage();

        try {
            return Inertia::location($start($request->user(), $request->session()));
        } catch (MetaConnectionException $e) {
            return back()->with('status', $e->getMessage());
        }
    }

    /** Meta redirects the admin's browser here. State is validated before anything else happens. */
    public function callback(Request $request, CompleteMetaConnection $complete): RedirectResponse
    {
        $this->authorizeManage();

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        try {
            if ($request->query->has('error') || $code === '') {
                // The admin cancelled (or Meta refused). Burn the state if it is ours; show a neutral message.
                $request->session()->forget('meta_oauth_nonce');
                AuditLogger::record('meta.connection.cancelled');

                return redirect()->route('integrations.meta.index')->with('status', 'The Meta connection was cancelled. Nothing was changed.');
            }

            $connection = $complete($request->user(), $state, $code, $request->session());
        } catch (MetaConnectionException $e) {
            return redirect()->route('integrations.meta.index')->with('status', $e->getMessage());
        } catch (MetaApiException $e) {
            MetaLog::warning('connection.callback_failed', ['code' => $e->code()]);

            return redirect()->route('integrations.meta.index')->with('status', $e->adminMessage());
        }

        return redirect()->route('integrations.meta.index')->with('status', $connection->pending_assets
            ? 'Connected to Meta. Choose the Pages to import leads from.'
            : 'Meta authorization refreshed.');
    }

    public function connectPages(Request $request, MetaConnection $metaConnection, ConnectMetaPages $connect): RedirectResponse
    {
        $this->authorizeManage();
        $data = $request->validate(['pages' => ['required', 'array', 'min:1', 'max:50'], 'pages.*' => ['required', 'string', 'regex:/^\d{1,40}$/']]);

        try {
            $connected = $connect($metaConnection, $data['pages']);
        } catch (MetaConnectionException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', count($connected).' Page(s) connected. Map each lead form below and enable it to start importing.');
    }

    public function syncForms(MetaPage $metaPage, SyncMetaForms $sync): RedirectResponse
    {
        $this->authorizeManage();

        try {
            $count = $sync($metaPage);
        } catch (MetaApiException $e) {
            return back()->with('status', $e->adminMessage());
        }

        return back()->with('status', "Found {$count} lead form(s).");
    }

    public function resubscribe(MetaPage $metaPage, ConnectMetaPages $connect): RedirectResponse
    {
        $this->authorizeManage();
        $connect->subscribe($metaPage);

        return back()->with('status', $metaPage->subscription_status === 'subscribed'
            ? 'Webhook subscription confirmed.' : ($metaPage->subscription_error ?? 'Could not subscribe the Page.'));
    }

    public function validateConnection(MetaConnection $metaConnection, ValidateMetaConnection $validate): RedirectResponse
    {
        $this->authorizeManage();
        $c = $validate($metaConnection);

        return back()->with('status', $c->status === MetaConnectionStatus::Active ? 'The Meta connection is healthy.' : ($c->last_error_summary ?? 'The connection needs attention.'));
    }

    public function disconnectPage(MetaPage $metaPage, DisconnectMeta $disconnect): RedirectResponse
    {
        $this->authorizeManage();
        $disconnect->page($metaPage);

        return back()->with('status', 'Page disconnected. Existing leads were kept.');
    }

    public function disconnect(MetaConnection $metaConnection, DisconnectMeta $disconnect): RedirectResponse
    {
        $this->authorizeManage();
        $disconnect->connection($metaConnection);

        return redirect()->route('integrations.meta.index')->with('status', 'Meta disconnected. Stored access was deleted and existing leads were kept.');
    }

    /** @return list<array{id: string, name: string}> Names only; tokens stay in the encrypted blob. */
    private function pendingPages(MetaConnection $c): array
    {
        if ($c->pending_assets_expires_at === null || $c->pending_assets_expires_at->isPast() || ! is_array($c->pending_assets)) {
            return [];
        }

        return collect($c->pending_assets)->map(fn ($a) => ['id' => (string) $a['id'], 'name' => (string) $a['name']])->values()->all();
    }

    private function authorizeManage(): void
    {
        Gate::authorize('manage', MetaConnection::class);
    }
}
