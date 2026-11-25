<?php

namespace App\Http\Controllers\Meta;

use App\Actions\Meta\HandleMetaDataRequest;
use App\Http\Controllers\Controller;
use App\Models\MetaDataDeletionRequest;
use App\Services\Meta\MetaLog;
use App\Services\Meta\SignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta's "Deauthorize callback URL" and "Data deletion request URL" (App Dashboard > App settings > Basic / Facebook
 * Login for Business > Settings). Public and unauthenticated: authenticity is the signed_request HMAC.
 */
class MetaDataDeletionController extends Controller
{
    /** Data deletion: Meta expects JSON { url, confirmation_code } where the person can check the status. */
    public function deletion(Request $request, HandleMetaDataRequest $handle): JsonResponse|Response
    {
        $userId = $this->userId($request);
        if ($userId instanceof Response) {
            return $userId;
        }

        $record = $handle($userId, MetaDataDeletionRequest::KIND_DELETION);

        return response()->json([
            'url' => route('legal.data-deletion.status', ['code' => $record->confirmation_code]),
            'confirmation_code' => $record->confirmation_code,
        ]);
    }

    /** Deauthorize: the person removed the app in their Facebook settings. Meta only needs a 200. */
    public function deauthorize(Request $request, HandleMetaDataRequest $handle): Response
    {
        $userId = $this->userId($request);
        if ($userId instanceof Response) {
            return $userId;
        }

        $handle($userId, MetaDataDeletionRequest::KIND_DEAUTHORIZE);

        return response('OK', 200, ['Content-Type' => 'text/plain']);
    }

    private function userId(Request $request): string|Response
    {
        $secret = (string) config('meta.app_secret');
        if ($secret === '') {
            MetaLog::error('data_request.not_configured');

            return response('Not configured', 503);
        }

        $payload = SignedRequest::parse((string) $request->input('signed_request', ''), $secret);
        $userId = is_array($payload) ? (string) ($payload['user_id'] ?? '') : '';

        if (preg_match('/^[0-9]{1,64}$/', $userId) !== 1) {
            MetaLog::warning('data_request.rejected', ['ip' => $request->ip()]);

            return response('Invalid signed_request', 403);
        }

        return $userId;
    }
}
