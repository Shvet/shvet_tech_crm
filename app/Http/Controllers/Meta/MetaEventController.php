<?php

namespace App\Http\Controllers\Meta;

use App\Actions\Meta\ReplayMetaEvent;
use App\Exceptions\MetaConnectionException;
use App\Http\Controllers\Controller;
use App\Models\MetaConnection;
use App\Models\MetaLeadEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class MetaEventController extends Controller
{
    public function retry(MetaLeadEvent $metaEvent, ReplayMetaEvent $replay): RedirectResponse
    {
        return $this->run(fn () => $replay->retry($metaEvent), 'Retry queued.');
    }

    public function createAnyway(MetaLeadEvent $metaEvent, ReplayMetaEvent $replay): RedirectResponse
    {
        return $this->run(fn () => $replay->createAnyway($metaEvent), 'A new lead will be created.');
    }

    public function dismiss(MetaLeadEvent $metaEvent, ReplayMetaEvent $replay): RedirectResponse
    {
        return $this->run(fn () => $replay->dismiss($metaEvent), 'Dismissed. No lead was created.');
    }

    private function run(\Closure $action, string $message): RedirectResponse
    {
        Gate::authorize('manage', MetaConnection::class);

        try {
            $action();
        } catch (MetaConnectionException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', $message);
    }
}
