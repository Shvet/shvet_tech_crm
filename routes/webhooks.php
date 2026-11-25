<?php

use App\Http\Controllers\Meta\MetaDataDeletionController;
use App\Http\Controllers\Meta\MetaWebhookController;
use App\Http\Controllers\WhatsApp\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine endpoints: deliberately NOT in the "web" group (no session, cookies or CSRF).
Route::prefix('webhooks')->group(function () {
    Route::get('meta', [MetaWebhookController::class, 'verify'])->middleware('throttle:meta-webhook-verify')->name('webhooks.meta.verify');
    Route::post('meta', [MetaWebhookController::class, 'receive'])->middleware('throttle:meta-webhook')->name('webhooks.meta.receive');

    // Meta app settings: "Data deletion request URL" and "Deauthorize callback URL". Authenticated by signed_request.
    Route::post('meta/data-deletion', [MetaDataDeletionController::class, 'deletion'])->middleware('throttle:meta-data-request')->name('webhooks.meta.data-deletion');
    Route::post('meta/deauthorize', [MetaDataDeletionController::class, 'deauthorize'])->middleware('throttle:meta-data-request')->name('webhooks.meta.deauthorize');

    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])->middleware('throttle:meta-webhook-verify')->name('webhooks.whatsapp.verify');
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:whatsapp-webhook')->name('webhooks.whatsapp.receive');
});
