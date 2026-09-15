<?php

use App\Http\Controllers\Api\DonationController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\PartnershipRequestController;
use Illuminate\Support\Facades\Route;

Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact');

Route::post('/partnerships', [PartnershipRequestController::class, 'store']);

Route::post('/donations/checkout-session', [DonationController::class, 'store'])
    ->middleware('throttle:checkout');

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
