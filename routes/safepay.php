<?php

use Safepay\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/fedapay', [WebhookController::class, 'handle']);