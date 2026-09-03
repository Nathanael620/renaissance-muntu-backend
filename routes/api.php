<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\PartnershipRequestController;
use Illuminate\Support\Facades\Route;

Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact');

Route::post('/partnerships', [PartnershipRequestController::class, 'store']);
