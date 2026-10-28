<?php

use App\Http\Controllers\Meta\MetaWebhookController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine endpoints: deliberately NOT in the "web" group (no session, cookies or CSRF).
Route::prefix('webhooks')->group(function () {
    Route::get('meta', [MetaWebhookController::class, 'verify'])->middleware('throttle:meta-webhook-verify')->name('webhooks.meta.verify');
    Route::post('meta', [MetaWebhookController::class, 'receive'])->middleware('throttle:meta-webhook')->name('webhooks.meta.receive');
});
