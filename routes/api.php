<?php

use App\Http\Controllers\PublicFuneralController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/funeral-requests', [PublicFuneralController::class, 'store'])->middleware('throttle:20,1');

Route::get('/v1/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/v1/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);
