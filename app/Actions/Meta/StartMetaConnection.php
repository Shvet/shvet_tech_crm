<?php

namespace App\Actions\Meta;

use App\Exceptions\MetaConnectionException;
use App\Models\User;
use App\Services\Meta\GraphClient;
use App\Services\Meta\MetaConfig;
use App\Services\Meta\OAuthStateService;
use App\Support\AuditLogger;
use Illuminate\Contracts\Session\Session;

class StartMetaConnection
{
    public function __construct(private OAuthStateService $states, private GraphClient $graph) {}

    /** @return string Meta's authorization URL for the admin's browser. */
    public function __invoke(User $user, Session $session): string
    {
        if (! MetaConfig::isConfigured()) {
            throw new MetaConnectionException('The Meta integration is not configured on this server yet. Ask the platform operator to complete the Meta setup.');
        }

        AuditLogger::record('meta.connection.started');

        return $this->graph->authorizationUrl($this->states->issue($user, $session));
    }
}
