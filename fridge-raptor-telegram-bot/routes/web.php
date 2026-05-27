<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Telegram webhook endpoint
Route::post('/webhook/telegram', [TelegramWebhookController::class, 'handle']);
