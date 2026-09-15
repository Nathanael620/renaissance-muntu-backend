<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDonationCheckoutRequest;
use App\Models\Donation;
use App\Services\StripeDonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DonationController extends Controller
{
    /**
     * Create a pending donation and a Stripe Checkout Session for it.
     *
     * The donation is never marked as completed here: payment confirmation only
     * happens later, server-side, through the (future) verified Stripe webhook.
     */
    public function store(CreateDonationCheckoutRequest $request, StripeDonationService $stripe): JsonResponse
    {
        if (empty(config('services.stripe.secret_key'))) {
            return response()->json([
                'message' => 'Les dons en ligne ne sont pas configurés pour le moment. Veuillez réessayer plus tard.',
            ], 503);
        }

        $donation = Donation::create([
            ...$request->safe()->only([
                'donor_name',
                'donor_email',
                'donor_address',
                'donor_city',
                'donor_province',
                'donor_postal_code',
                'donor_country',
            ]),
            'internal_reference' => $this->generateInternalReference(),
            'amount' => $request->amount,
            'currency' => config('donation.currency'),
            'advantage_value' => 0,
            'status' => 'pending',
        ]);

        try {
            $session = $stripe->createCheckoutSession($donation);

            $attributes = ['stripe_session_id' => $session->id];

            if (filled($session->payment_intent)) {
                $attributes['stripe_payment_intent_id'] = $session->payment_intent;
            }

            $donation->update($attributes);

            return response()->json([
                'message' => 'Session de paiement créée.',
                'checkout_url' => $session->url,
                'reference' => $donation->internal_reference,
            ], 201);
        } catch (\Throwable $e) {
            Log::error("Échec de la création de la session Stripe pour le don {$donation->internal_reference}", [
                'error' => $e->getMessage(),
            ]);

            $donation->update(['status' => 'failed']);

            return response()->json([
                'message' => 'Le service de paiement rencontre un problème. Veuillez réessayer plus tard.',
            ], 502);
        }
    }

    /**
     * Generate a short, unique internal reference that the client can never choose.
     */
    private function generateInternalReference(): string
    {
        do {
            $reference = 'DON-'.Str::upper(Str::random(8));
        } while (Donation::where('internal_reference', $reference)->exists());

        return $reference;
    }
}