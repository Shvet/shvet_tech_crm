<?php

namespace App\Http\Controllers\WhatsApp;

use App\Actions\WhatsApp\LinkConversationToLead;
use App\Actions\WhatsApp\SendWhatsAppMessage;
use App\Enums\WhatsAppAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\Leads\LeadVisibility;
use App\Services\WhatsApp\ConversationVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shared WhatsApp inbox. Every query goes through ConversationVisibility; route binding does too, so a conversation the
 * user may not see is a 404. Message bodies are decrypted only for the open conversation, never for the list.
 */
class InboxController extends Controller
{
    private const LIST_LIMIT = 50;

    private const MESSAGE_LIMIT = 100;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WhatsAppConversation::class);

        return $this->render($request, null);
    }

    public function show(Request $request, WhatsAppConversation $conversation): Response
    {
        Gate::authorize('view', $conversation);

        if ($conversation->unread_count > 0) {
            WhatsAppConversation::query()->whereKey($conversation->getKey())->update(['unread_count' => 0]);
            $conversation->unread_count = 0;
        }

        return $this->render($request, $conversation);
    }

    public function send(Request $request, WhatsAppConversation $conversation, SendWhatsAppMessage $send): RedirectResponse
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'type' => ['required', Rule::in(['text', 'template'])],
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:'.(int) config('whatsapp.max_text_length', 4096)],
            'template_id' => ['required_if:type,template', 'nullable', 'integer'],
            'params' => ['array', 'max:20'],
            'params.*' => ['required', 'string', 'max:500'],
        ]);
        if ($data['type'] === 'text' && trim((string) $data['body']) === '') {
            return back()->withErrors(['body' => 'Write a message first.']);
        }

        $send($request->user(), $conversation, $data);

        return back();
    }

    public function link(Request $request, WhatsAppConversation $conversation, LinkConversationToLead $link): RedirectResponse
    {
        Gate::authorize('link', $conversation);

        $data = $request->validate(['lead_id' => ['required', 'string', 'size:26']]);
        $lead = LeadVisibility::scope(Lead::query(), $request->user())->where('leads.public_id', $data['lead_id'])->first();
        if (! $lead) {
            return back()->withErrors(['lead_id' => 'Choose a lead you can see.']);
        }

        $link->manual($request->user(), $conversation, $lead);

        return back()->with('status', 'Conversation linked to '.$lead->full_name.'.');
    }

    private function render(Request $request, ?WhatsAppConversation $open): Response
    {
        $user = $request->user();
        $search = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('filter'), ['unread', 'unlinked'], true) ? (string) $request->query('filter') : 'all';

        $conversations = ConversationVisibility::scope(WhatsAppConversation::query(), $user)
            ->with(['contact:id,wa_id,profile_name,opted_out_at', 'lead:id,public_id,full_name'])
            ->when($filter === 'unread', fn ($q) => $q->where('unread_count', '>', 0))
            ->when($filter === 'unlinked', fn ($q) => $q->where('link_status', '!=', WhatsAppConversation::LINKED))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $digits = preg_replace('/\D/', '', $search);
                $q->where(function ($q) use ($like, $digits) {
                    $q->whereHas('contact', fn ($c) => $c->where('profile_name', 'like', $like)
                        ->when(strlen((string) $digits) >= 3, fn ($c) => $c->orWhere('wa_id', 'like', '%'.$digits.'%')))
                        ->orWhereHas('lead', fn ($l) => $l->where('full_name', 'like', $like));
                });
            })
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(self::LIST_LIMIT)->get();

        $account = WhatsAppAccount::query()->where('status', '!=', WhatsAppAccountStatus::Disconnected->value)->first();

        return Inertia::render('Inbox/Index', [
            'conversations' => $conversations->map(fn (WhatsAppConversation $c) => $this->summary($c))->values(),
            'filters' => ['q' => $search, 'filter' => $filter],
            'account' => $account ? ['status' => $account->status->value, 'display_phone_number' => $account->display_phone_number] : null,
            'conversation' => $open ? $this->detail($open, $user) : null,
            'poll_seconds' => max(5, (int) config('whatsapp.inbox_poll_seconds', 10)),
            'can' => ['manage' => Gate::allows('manage', WhatsAppAccount::class)],
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(WhatsAppConversation $c): array
    {
        return [
            'id' => $c->public_id,
            'name' => $c->lead?->full_name ?? $c->contact->profile_name ?? '+'.$c->contact->wa_id,
            'phone' => '+'.$c->contact->wa_id,
            'lead' => $c->lead ? ['id' => $c->lead->public_id, 'name' => $c->lead->full_name] : null,
            'link_status' => $c->link_status,
            'unread' => $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'opted_out' => $c->contact->isOptedOut(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(WhatsAppConversation $c, User $user): array
    {
        $c->loadMissing(['contact', 'lead:id,public_id,full_name,owner_id', 'account']);
        $messages = WhatsAppMessage::query()->where('whatsapp_conversation_id', $c->getKey())
            ->with('sender:id,name')->orderByDesc('id')->limit(self::MESSAGE_LIMIT)->get()->reverse();

        $templates = $c->account
            ? WhatsAppTemplate::query()->where('whatsapp_account_id', $c->account->getKey())->orderBy('name')->get()->filter->isApproved()
            : collect();

        $candidates = [];
        if (Gate::allows('link', $c) && $c->link_status !== WhatsAppConversation::LINKED) {
            $candidates = LeadVisibility::scope(Lead::query(), $user)
                ->where('phone_e164', '+'.$c->contact->wa_id)->orderBy('id')->limit(10)->get(['public_id', 'full_name'])
                ->map(fn (Lead $l) => ['id' => $l->public_id, 'name' => $l->full_name])->values()->all();
        }

        return [
            ...$this->summary($c),
            'window_open' => $c->serviceWindowOpen(),
            'window_closes_at' => $c->serviceWindowClosesAt()?->toIso8601String(),
            'account_active' => (bool) $c->account?->isActive(),
            'messages' => $messages->map(fn (WhatsAppMessage $m) => [
                'id' => $m->public_id,
                'direction' => $m->direction,
                'kind' => $m->kind,
                'body' => $m->body,
                'template_name' => $m->template_name,
                'status' => $m->status->value,
                'error' => $m->error_title,
                'sender' => $m->sender?->name,
                'at' => ($m->provider_timestamp ?? $m->created_at)?->toIso8601String(),
            ])->values(),
            'templates' => $templates->map(fn (WhatsAppTemplate $t) => [
                'id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'params' => $t->body_param_count, 'body' => $t->bodyText(),
            ])->values(),
            'link_candidates' => $candidates,
            'can' => ['reply' => Gate::allows('reply', $c), 'link' => Gate::allows('link', $c)],
        ];
    }
}
