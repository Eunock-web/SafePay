<?php

use Illuminate\Support\Facades\Route;
use Safepay\Http\Controllers\WebhookController;

// Groupe "api" : pas de CSRF ni de session pour un appel serveur à serveur.
Route::middleware('api')->post('/webhook/fedapay', [WebhookController::class, 'handle'])->name('safepay.webhook');
