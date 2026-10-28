<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Models\FollowUpTask;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Tasks\TaskBuckets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $list = fn (string $bucket) => TaskBuckets::bucket(TaskBuckets::open($user), $bucket)
            ->with(['lead', 'assignee:id,name'])->orderBy('follow_up_tasks.due_at')->orderBy('follow_up_tasks.id')->limit(5)->get()
            ->map(fn (FollowUpTask $t) => (new TaskResource($t))->resolve())->values();

        return Inertia::render('Dashboard', [
            'metrics' => DashboardMetrics::for($user),
            'task_lists' => [
                TaskBuckets::OVERDUE => $list(TaskBuckets::OVERDUE),
                TaskBuckets::TODAY => $list(TaskBuckets::TODAY),
                TaskBuckets::UPCOMING => $list(TaskBuckets::UPCOMING),
            ],
        ]);
    }
}
