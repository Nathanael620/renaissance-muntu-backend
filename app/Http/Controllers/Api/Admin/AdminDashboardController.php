<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminInvitation;
use App\Models\Contact;
use App\Models\LibraryResource;
use App\Models\PartnershipRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $activities = Contact::query()
            ->latest()
            ->limit(5)
            ->get(['id', 'name', 'subject', 'created_at'])
            ->map(fn (Contact $contact) => [
                'type' => 'message',
                'id' => $contact->id,
                'title' => 'Nouveau message de '.$contact->name,
                'detail' => $contact->created_at?->toISOString(),
            ])
            ->concat(
                PartnershipRequest::query()
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'name', 'organization', 'created_at'])
                    ->map(fn (PartnershipRequest $partnership) => [
                        'type' => 'partnership',
                        'id' => $partnership->id,
                        'title' => 'Demande de partenariat de '.($partnership->organization ?: $partnership->name),
                        'detail' => $partnership->created_at?->toISOString(),
                    ])
            )
            ->sortByDesc('detail')
            ->take(5)
            ->values();

        return response()->json([
            'stats' => [
                'messages' => Contact::query()->count(),
                'partnerships' => PartnershipRequest::query()->count(),
                'resources' => LibraryResource::query()->count(),
                'administrators' => User::query()
                    ->whereIn('role', User::ROLES)
                    ->where('is_active', true)
                    ->count(),
                'pending_invitations' => AdminInvitation::query()
                    ->whereNull('accepted_at')
                    ->where('expires_at', '>', now())
                    ->count(),
            ],
            'recent_activity' => $activities,
        ]);
    }
}
