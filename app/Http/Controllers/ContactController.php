<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Mail\ContactNotificationMail;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    /**
     * The name of the honeypot field (invisible for real users).
     */
    private const HONEYPOT_FIELD = 'website';

    /**
     * Handle an incoming contact form submission.
     */
    public function store(StoreContactRequest $request): JsonResponse
    {
        // Honeypot: silently discard bot submissions without persisting them.
        if (filled($request->input(self::HONEYPOT_FIELD))) {
            return response()->json([
                'message' => 'Votre message a été envoyé avec succès.',
            ], 201);
        }

        $contact = Contact::create(
            $request->safe()->only(['name', 'email', 'phone', 'subject', 'message'])
        );

        // Notify the team (the recipient is configured via CONTACT_NOTIFICATION_EMAIL).
        $notificationEmail = config('mail.notification_address');

        if (filled($notificationEmail)) {
            try {
                Mail::to($notificationEmail)->send(new ContactNotificationMail($contact));
                $contact->update(['status' => 'notified']);
            } catch (\Throwable $e) {
                Log::warning("Échec d'envoi de l'e-mail de notification pour le contact #{$contact->id}", [
                    'error' => $e->getMessage(),
                ]);
                $contact->update(['status' => 'email_failed']);
            }
        }

        return response()->json([
            'message' => 'Votre message a été envoyé avec succès.',
            'id' => $contact->id,
        ], 201);
    }
}
