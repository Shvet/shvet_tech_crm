<?php

namespace App\Http\Controllers\WhatsApp;

use App\Actions\WhatsApp\ConnectWhatsAppAccount;
use App\Actions\WhatsApp\DisconnectWhatsApp;
use App\Actions\WhatsApp\SyncWhatsAppTemplates;
use App\Actions\WhatsApp\ValidateWhatsAppAccount;
use App\Enums\WhatsAppAccountStatus;
use App\Exceptions\MetaApiException;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Services\Leads\ActiveMemberGuard;
use App\Services\Leads\LeadOptions;
use App\Services\Meta\MetaConfig;
use App\Support\AuditLogger;
use App\Support\TenantRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin screen for the WhatsApp number (integrations.manage). Props are an explicit allow-list: the token is
 * write-only and never returned to the browser.
 */
class WhatsAppIntegrationController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage', WhatsAppAccount::class);

        $account = WhatsAppAccount::query()->where('status', '!=', WhatsAppAccountStatus::Disconnected->value)->first();
        $templates = $account ? WhatsAppTemplate::query()->where('whatsapp_account_id', $account->getKey())->orderBy('name')->get() : collect();

        $missing = array_values(array_filter([
            blank(config('meta.app_secret')) ? 'META_APP_SECRET' : null,
            blank(config('meta.graph_version')) ? 'META_GRAPH_VERSION' : null,
            blank(config('whatsapp.webhook_verify_token')) ? 'WHATSAPP_WEBHOOK_VERIFY_TOKEN' : null,
        ]));

        return Inertia::render('Integrations/WhatsApp/Index', [
            'setup' => [
                'configured' => $missing === [],
                'missing' => $missing,
                'webhook_url' => rtrim((string) config('app.url'), '/').'/webhooks/whatsapp',
                'app_id' => MetaConfig::isConfigured() ? (string) config('meta.app_id') : null,
            ],
            'account' => $account ? [
                'id' => $account->public_id,
                'phone_number_id' => $account->phone_number_id,
                'waba_id' => $account->waba_id,
                'display_phone_number' => $account->display_phone_number,
                'verified_name' => $account->verified_name,
                'status' => $account->status->value,
                'subscription_status' => $account->subscription_status,
                'auto_create_leads' => $account->auto_create_leads,
                'default_owner_id' => $account->default_owner_id,
                'last_validated_at' => $account->last_validated_at?->toIso8601String(),
                'last_webhook_at' => $account->last_webhook_at?->toIso8601String(),
                'templates_synced_at' => $account->templates_synced_at?->toIso8601String(),
                'error' => $account->last_error_summary,
            ] : null,
            'templates' => $templates->map(fn (WhatsAppTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'language' => $t->language,
                'category' => $t->category,
                'status' => $t->status,
                'params' => $t->body_param_count,
                'body' => $t->bodyText(),
            ])->values(),
            'members' => LeadOptions::members(),
        ]);
    }

    public function connect(Request $request, ConnectWhatsAppAccount $connect): RedirectResponse
    {
        Gate::authorize('manage', WhatsAppAccount::class);

        $data = $request->validate([
            'waba_id' => ['required', 'string', 'regex:/^\d{1,40}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{1,40}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:2048', 'regex:/^[A-Za-z0-9_\-|.]+$/'],
            'auto_create_leads' => ['boolean'],
            'default_owner_id' => ['nullable', 'integer', TenantRules::activeMember()],
        ]);

        if (blank(config('meta.app_secret')) || blank(config('meta.graph_version'))) {
            return back()->with('status', 'WhatsApp is not configured on the server yet (see the setup list).');
        }

        $connect($request->user(), $data);

        return back()->with('status', 'WhatsApp number connected.');
    }

    public function update(Request $request, WhatsAppAccount $whatsappAccount): RedirectResponse
    {
        Gate::authorize('manage', WhatsAppAccount::class);

        $data = $request->validate([
            'auto_create_leads' => ['required', 'boolean'],
            'default_owner_id' => ['nullable', 'integer', TenantRules::activeMember()],
        ]);
        if ($data['default_owner_id'] !== null) {
            ActiveMemberGuard::ensure((int) $data['default_owner_id'], 'default_owner_id');
        }

        $whatsappAccount->update($data);
        AuditLogger::record('whatsapp.account.settings_updated', $whatsappAccount, $data);

        return back()->with('status', 'Settings saved.');
    }

    public function syncTemplates(WhatsAppAccount $whatsappAccount, SyncWhatsAppTemplates $sync): RedirectResponse
    {
        Gate::authorize('manage', WhatsAppAccount::class);
        abort_unless($whatsappAccount->isActive(), 409, 'WhatsApp is not connected.');

        try {
            $count = $sync($whatsappAccount);
        } catch (MetaApiException $e) {
            ValidateWhatsAppAccount::recordFailure($whatsappAccount, $e);

            return back()->with('status', 'Could not load templates from Meta ('.$e->code().').');
        }

        return back()->with('status', "Templates refreshed ({$count}).");
    }

    public function validateAccount(WhatsAppAccount $whatsappAccount, ValidateWhatsAppAccount $validate): RedirectResponse
    {
        Gate::authorize('manage', WhatsAppAccount::class);

        return back()->with('status', $validate($whatsappAccount) ? 'Connection is working.' : 'Meta rejected the connection. See the error below.');
    }

    public function disconnect(WhatsAppAccount $whatsappAccount, DisconnectWhatsApp $disconnect): RedirectResponse
    {
        Gate::authorize('manage', WhatsAppAccount::class);

        $disconnect($whatsappAccount);

        return back()->with('status', 'WhatsApp disconnected. Conversations and leads are kept.');
    }
}
