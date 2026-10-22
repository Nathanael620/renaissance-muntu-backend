<?php

use App\Http\Controllers\Api\Admin\AdminAccountController;
use App\Http\Controllers\Api\Admin\AdminAuthController;
use App\Http\Controllers\Api\Admin\AdminContactController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminInvitationAcceptanceController;
use App\Http\Controllers\Api\Admin\AdminLibraryResourceController;
use App\Http\Controllers\Api\Admin\AdminPartnershipController;
use App\Http\Controllers\Api\Admin\AdminProfileController;
use App\Http\Controllers\Api\DonationController;
use App\Http\Controllers\Api\DonationStatusController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\PartnershipRequestController;
use App\Models\LibraryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact');

Route::post('/partnerships', [PartnershipRequestController::class, 'store'])
    ->middleware('throttle:partnerships');

Route::post('/donations/checkout-session', [DonationController::class, 'store'])
    ->middleware('throttle:checkout');

Route::get('/donations/status/{sessionId}', DonationStatusController::class)
    ->where('sessionId', 'cs_(test|live)_[A-Za-z0-9]+')
    ->middleware('throttle:donation-status');

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

Route::get('/resources', function (Request $request) {
    return LibraryResource::query()
        ->where('status', 'published')
        ->latest()
        ->paginate(
            min(max((int) $request->query('per_page', 20), 5), 100),
            ['id', 'title', 'category', 'type', 'cover_url', 'external_url', 'created_at']
        );
});

Route::prefix('admin/v1')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');
    Route::post('/invitations/accept', AdminInvitationAcceptanceController::class)
        ->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me'])
            ->middleware('admin.permission:profile.manage');
        Route::post('/logout', [AdminAuthController::class, 'logout'])
            ->middleware('admin.permission:profile.manage');

        Route::get('/dashboard', AdminDashboardController::class)
            ->middleware('admin.permission:dashboard.view');

        Route::prefix('/messages')->middleware('admin.permission:messages.view')->group(function () {
            Route::get('/', [AdminContactController::class, 'index']);
            Route::get('/{contactId}', [AdminContactController::class, 'show'])->whereNumber('contactId');
            Route::patch('/{contactId}', [AdminContactController::class, 'update'])
                ->whereNumber('contactId')
                ->middleware('admin.permission:messages.manage');
        });

        Route::prefix('/partnerships')->middleware('admin.permission:partnerships.view')->group(function () {
            Route::get('/', [AdminPartnershipController::class, 'index']);
            Route::get('/{partnershipId}', [AdminPartnershipController::class, 'show'])->whereNumber('partnershipId');
            Route::patch('/{partnershipId}', [AdminPartnershipController::class, 'update'])
                ->whereNumber('partnershipId')
                ->middleware('admin.permission:partnerships.manage');
        });

        Route::prefix('/resources')->middleware('admin.permission:resources.view')->group(function () {
            Route::get('/', [AdminLibraryResourceController::class, 'index']);
            Route::get('/{resourceId}', [AdminLibraryResourceController::class, 'show'])->whereNumber('resourceId');
            Route::post('/', [AdminLibraryResourceController::class, 'store'])
                ->middleware('admin.permission:resources.manage');
            Route::patch('/{resourceId}', [AdminLibraryResourceController::class, 'update'])
                ->whereNumber('resourceId')
                ->middleware('admin.permission:resources.manage');
        });

        Route::middleware('admin.permission:admins.manage')->group(function () {
            Route::get('/admins', [AdminAccountController::class, 'index']);
            Route::post('/admin-invitations', [AdminAccountController::class, 'invite']);
            Route::patch('/admins/{userId}', [AdminAccountController::class, 'update'])->whereNumber('userId');
        });

        Route::middleware('admin.permission:profile.manage')->prefix('/me')->group(function () {
            Route::patch('/', [AdminProfileController::class, 'update']);
            Route::get('/preferences', [AdminProfileController::class, 'preferences']);
            Route::patch('/preferences', [AdminProfileController::class, 'updatePreferences']);
            Route::patch('/password', [AdminProfileController::class, 'updatePassword']);
        });
    });
});
