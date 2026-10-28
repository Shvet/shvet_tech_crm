<?php

namespace App\Http\Controllers;

use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Http\Requests\CompleteTaskRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Services\Leads\LeadOptions;
use App\Services\Leads\LeadVisibility;
use App\Services\Tasks\TaskBuckets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    private const TABS = [TaskBuckets::OVERDUE, TaskBuckets::TODAY, TaskBuckets::UPCOMING, TaskBuckets::COMPLETED];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FollowUpTask::class);
        $user = $request->user();

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : TaskBuckets::OVERDUE;
        $assignee = $this->assigneeFilter($request, $user);
        $assignedTo = $assignee === 'me' ? $user->getKey() : (is_numeric($assignee) ? (int) $assignee : null);

        $base = fn () => TaskBuckets::visible($user)->when($assignedTo, fn ($q) => $q->where('follow_up_tasks.assigned_to', $assignedTo));

        $query = $tab === TaskBuckets::COMPLETED
            ? $base()->where('follow_up_tasks.status', TaskStatus::Completed->value)->orderByDesc('follow_up_tasks.completed_at')
            : TaskBuckets::bucket($base()->where('follow_up_tasks.status', TaskStatus::Open->value), $tab)->orderBy('follow_up_tasks.due_at');

        $tasks = $query->orderBy('follow_up_tasks.id')->with(['lead', 'assignee:id,name'])->paginate(20)->withQueryString();

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks->through(fn (FollowUpTask $t) => (new TaskResource($t))->resolve()),
            'tab' => $tab,
            'assignee' => $assignee,
            'counts' => TaskBuckets::counts($user, $assignedTo),
            'options' => [
                'members' => LeadVisibility::level($user) === LeadVisibility::ALL ? LeadOptions::members() : [],
                'priorities' => Priority::values(),
            ],
        ]);
    }

    public function store(StoreTaskRequest $request, Lead $lead, CreateTask $createTask): RedirectResponse
    {
        $createTask($request->user(), $lead, $request->taskData());

        return back()->with('status', 'Follow-up scheduled.');
    }

    public function update(UpdateTaskRequest $request, FollowUpTask $task, UpdateTask $updateTask): RedirectResponse
    {
        $updateTask($request->user(), $task, $request->taskData());

        return back()->with('status', 'Follow-up updated.');
    }

    public function complete(CompleteTaskRequest $request, FollowUpTask $task, CompleteTask $action): RedirectResponse
    {
        $action->complete($request->user(), $task, $request->validated('outcome'));

        return back()->with('status', 'Follow-up completed.');
    }

    public function reopen(Request $request, FollowUpTask $task, CompleteTask $action): RedirectResponse
    {
        Gate::authorize('complete', $task);
        $action->reopen($request->user(), $task);

        return back()->with('status', 'Follow-up reopened.');
    }

    /** 'me' | 'all' | a member's user id (only for users who can see everyone's leads). */
    private function assigneeFilter(Request $request, $user): string
    {
        $value = (string) $request->query('assignee', 'me');

        if ($value === 'all') {
            return 'all';
        }
        if (is_numeric($value) && LeadVisibility::level($user) === LeadVisibility::ALL) {
            return (string) (int) $value;
        }

        return 'me';
    }
}
