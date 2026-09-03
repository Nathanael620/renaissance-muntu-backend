<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePartnershipRequest;
use App\Mail\PartnershipRequestNotificationMail;
use App\Models\PartnershipRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PartnershipRequestController extends Controller
{
    /**
     * Handle an incoming partnership request.
     */
    public function store(StorePartnershipRequest $request): JsonResponse
    {
        $partnershipRequest = PartnershipRequest::create([
            ...$request->safe()->only([
                'name',
                'organization',
                'email',
                'phone',
                'partnership_type',
                'message',
            ]),
            'status' => 'new',
        ]);

        $notificationEmail = config('mail.notification_address');

        if (filled($notificationEmail)) {
            try {
                Mail::to($notificationEmail)->send(
                    new PartnershipRequestNotificationMail($partnershipRequest)
                );
                $partnershipRequest->update(['status' => 'notified']);
            } catch (\Throwable $e) {
                Log::warning("Échec d'envoi de l'e-mail de notification pour le partenariat #{$partnershipRequest->id}", [
                    'error' => $e->getMessage(),
                ]);
                $partnershipRequest->update(['status' => 'email_failed']);
            }
        }

        return response()->json([
            'message' => 'Votre demande de partenariat a été envoyée avec succès.',
            'id' => $partnershipRequest->id,
        ], 201);
    }
}
