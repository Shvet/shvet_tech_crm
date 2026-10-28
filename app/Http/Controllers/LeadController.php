<?php

namespace App\Http\Controllers;

use App\Actions\Leads\CreateLead;
use App\Actions\Leads\UpdateLead;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Priority;
use App\Exceptions\DuplicateLeadException;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Http\Resources\TaskResource;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStageHistory;
use App\Services\Leads\DuplicatePresenter;
use App\Services\Leads\LeadOptions;
use App\Services\Leads\LeadSearch;
use App\Services\Leads\LeadVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Lead::class);
        $user = $request->user();
        $level = LeadVisibility::level($user);

        return Inertia::render('Leads/Index', [
            'leads' => LeadSearch::paginate($request, $user)->through(fn (Lead $l) => (new LeadResource($l))->resolve()),
            'filters' => LeadSearch::filters($request, $user),
            'options' => [
                'stages' => LeadOptions::stages(),
                'sources' => LeadOptions::sources(),
                'owners' => $level === LeadVisibility::ALL ? LeadOptions::members() : [],
                'priorities' => Priority::values(),
            ],
            'can' => [
                'create' => Gate::allows('create', Lead::class),
                'see_archived' => LeadVisibility::seesArchived($user),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Lead::class);

        return Inertia::render('Leads/Create', [
            'options' => $this->formOptions($request),
            'submission_token' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreLeadRequest $request, CreateLead $createLead): RedirectResponse
    {
        $attributes = $request->leadAttributes();

        try {
            ['lead' => $lead, 'created' => $created] = $createLead($request->user(), $attributes, $request->confirmsDuplicate());
        } catch (DuplicateLeadException $e) {
            return $this->duplicateResponse($e, $request->user(), $attributes);
        }

        return redirect()->route('leads.show', $lead)->with('status', $created ? 'Lead created.' : 'Lead was already created.');
    }

    public function show(Request $request, Lead $lead): Response
    {
        Gate::authorize('view', $lead);
        $user = $request->user();
        $lead->load(['stage:id,name,key,outcome,color', 'source:id,name,key', 'owner:id,name']);

        $activities = LeadActivity::query()->where('lead_id', $lead->id)->with('user:id,name')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn (LeadActivity $a) => [
                'kind' => 'activity', 'id' => 'a'.$a->id, 'at' => $a->occurred_at->toIso8601String(),
                'type' => $a->type->value, 'subject' => $a->subject, 'body' => $a->body, 'user' => $a->user?->name,
            ]);

        $moves = LeadStageHistory::query()->where('lead_id', $lead->id)->with(['fromStage:id,name', 'toStage:id,name', 'actor:id,name'])
            ->orderByDesc('changed_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn (LeadStageHistory $h) => [
                'kind' => 'stage', 'id' => 's'.$h->id, 'at' => $h->changed_at->toIso8601String(),
                'from' => $h->fromStage?->name, 'to' => $h->toStage?->name, 'user' => $h->actor?->name,
            ]);

        $tasks = $lead->tasks()->with('assignee:id,name')->orderByRaw("status = 'open' desc")->orderBy('due_at')->limit(50)->get()
            ->each(fn ($t) => $t->setRelation('lead', $lead));

        return Inertia::render('Leads/Show', [
            'lead' => (new LeadResource($lead, withAbilities: true))->resolve(),
            'timeline' => $activities->concat($moves)->sortByDesc('at')->values()->take(100)->all(),
            'tasks' => $tasks->map(fn ($t) => (new TaskResource($t))->resolve())->values(),
            'options' => [
                'stages' => LeadOptions::stages(),
                'owners' => Gate::allows('assign', $lead) ? LeadOptions::members() : [],
                'assignees' => Gate::allows('createTask', $lead) ? LeadOptions::assignees($user, $lead) : [],
                'activity_types' => ActivityType::values(),
                'priorities' => Priority::values(),
            ],
            'viewer_id' => $user->getKey(),
        ]);
    }

    public function edit(Request $request, Lead $lead): Response
    {
        Gate::authorize('update', $lead);
        $lead->load(['stage:id,name,key,outcome,color', 'source:id,name,key', 'owner:id,name']);

        return Inertia::render('Leads/Edit', [
            'lead' => (new LeadResource($lead))->resolve(),
            'options' => $this->formOptions($request),
        ]);
    }

    public function update(UpdateLeadRequest $request, Lead $lead, UpdateLead $updateLead): RedirectResponse
    {
        $attributes = $request->leadAttributes();

        try {
            $updateLead($lead, $attributes, $request->confirmsDuplicate());
        } catch (DuplicateLeadException $e) {
            return $this->duplicateResponse($e, $request->user(), $attributes);
        }

        return redirect()->route('leads.show', $lead)->with('status', 'Lead updated.');
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request): array
    {
        $canAssign = $request->user()->checkPermissionTo(Permission::LeadsAssign->value);

        return [
            'stages' => LeadOptions::stages(),
            'sources' => LeadOptions::sources(),
            'owners' => $canAssign ? LeadOptions::members() : [],
            'priorities' => Priority::values(),
            'can_assign' => $canAssign,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function duplicateResponse(DuplicateLeadException $e, $user, array $attributes): RedirectResponse
    {
        return back()
            ->withErrors(['duplicate' => 'A lead with the same phone number or e-mail already exists. Review it, or confirm this is a different person.'])
            ->with('duplicates', DuplicatePresenter::forUser($e->matches, $user, $attributes['phone_e164'] ?? null, $attributes['email'] ?? null));
    }
}
