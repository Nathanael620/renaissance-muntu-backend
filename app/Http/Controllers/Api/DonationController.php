<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDonationCheckoutRequest;
use App\Models\Donation;
use App\Services\StripeDonationService;
use App\Support\DonationCurrencyAmount;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class DonationController extends Controller
{
    public function store(CreateDonationCheckoutRequest $request, StripeDonationService $stripe): JsonResponse
    {
        if (! $this->checkoutIsConfigured()) {
            return response()->json([
                'message' => 'Les dons en ligne ne sont pas configurés pour le moment. Veuillez réessayer plus tard.',
            ], 503);
        }

        if (! Schema::hasColumns('donations', ['checkout_idempotency_key', 'checkout_request_hash'])) {
            return response()->json([
                'message' => 'La configuration du paiement doit être mise à jour avant de pouvoir accepter des dons.',
            ], 503);
        }

        $checkoutKey = (string) $request->validated('idempotency_key');
        $requestHash = $this->buildCheckoutRequestHash($request);

        try {
            $donation = $this->findOrCreateDonation($checkoutKey, $requestHash, $request);
        } catch (QueryException $exception) {
            Log::error('La demande de don n’a pas pu être enregistrée.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Le service de paiement rencontre un problème. Veuillez réessayer plus tard.',
            ], 502);
        }

        if (! hash_equals((string) $donation->checkout_request_hash, $requestHash)) {
            return response()->json([
                'message' => 'Cette clé d\'idempotence a déjà été utilisée avec des données différentes.',
            ], 409);
        }

        if ($donation->status === 'completed') {
            return response()->json([
                'message' => 'Un don associé à cette demande a déjà été confirmé.',
                'reference' => $donation->internal_reference,
                'status' => 'completed',
            ], 200);
        }
        if (filled($donation->stripe_session_id)) {
            try {
                $session = $stripe->retrieveCheckoutSession($donation);
            } catch (Throwable $exception) {
                Log::warning('La session Stripe existante ne peut pas être récupérée.', [
                    'donation_id' => $donation->id,
                    'exception' => $exception::class,
                ]);

                return $this->checkoutFailureResponse();
            }

            if (($session->status ?? null) === 'open' && $this->hasValidCheckoutUrl($session->url ?? null)) {
                return $this->checkoutSessionResponse($donation, $session->url, 200);
            }

            return response()->json([
                'message' => 'La session de paiement n’est plus disponible. Veuillez recommencer le don.',
                'reference' => $donation->internal_reference,
            ], 409);
        }

        if ($donation->created_at !== null && $donation->created_at->lt(now()->subHours(23))) {
            Log::warning('La reprise Stripe est bloquée après la fenêtre d’idempotence du fournisseur.', [
                'donation_id' => $donation->id,
            ]);

            return $this->checkoutFailureResponse();
        }

        try {
            $session = $stripe->createCheckoutSession($donation);
        } catch (Throwable $exception) {
            Log::error('Échec de la création de la session Stripe.', [
                'donation_id' => $donation->id,
                'exception' => $exception::class,
            ]);

            Donation::query()
                ->whereKey($donation->id)
                ->where('status', 'pending')
                ->update(['status' => 'failed']);

            return $this->checkoutFailureResponse();
        }

        if (! filled($session->id) || ! $this->hasValidCheckoutUrl($session->url ?? null)) {
            Log::error('Stripe a retourné une session Checkout incomplète.', [
                'donation_id' => $donation->id,
            ]);

            return $this->checkoutFailureResponse();
        }

        $attributes = ['stripe_session_id' => $session->id];

        if (is_string($session->payment_intent ?? null)) {
            $attributes['stripe_payment_intent_id'] = $session->payment_intent;
        } elseif (is_object($session->payment_intent ?? null)
            && is_string($session->payment_intent->id ?? null)) {
            $attributes['stripe_payment_intent_id'] = $session->payment_intent->id;
        }

        $attributes['status'] = 'pending';
        $donation->update($attributes);

        return $this->checkoutSessionResponse(
            $donation,
            $session->url,
            $donation->wasRecentlyCreated ? 201 : 200
        );
    }

    private function checkoutIsConfigured(): bool
    {
        $frontendUrl = parse_url((string) config('donation.frontend_url'));
        $currency = (string) config('donation.currency', '');

        return filled(config('services.stripe.secret_key'))
            && is_array($frontendUrl)
            && in_array($frontendUrl['scheme'] ?? null, ['http', 'https'], true)
            && filled($frontendUrl['host'] ?? null)
            && preg_match('/^[a-zA-Z]{3}$/D', $currency) === 1;
    }

    private function buildCheckoutRequestHash(CreateDonationCheckoutRequest $request): string
    {
        $requestData = $request->safe()->only([
            'donor_name',
            'donor_email',
            'donor_address',
            'donor_city',
            'donor_province',
            'donor_postal_code',
            'donor_country',
        ]);

        foreach ([
            'donor_address',
            'donor_city',
            'donor_province',
            'donor_postal_code',
            'donor_country',
        ] as $optionalField) {
            $requestData[$optionalField] ??= null;
        }

        $requestData['amount'] = DonationCurrencyAmount::toStorageDecimal(
            $request->input('amount'),
            (string) config('donation.currency', 'cad')
        );
        ksort($requestData);

        return hash('sha256', json_encode($requestData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function findOrCreateDonation(
        string $checkoutKey,
        string $requestHash,
        CreateDonationCheckoutRequest $request
    ): Donation {
        try {
            return DB::transaction(function () use ($checkoutKey, $requestHash, $request): Donation {
                $existingDonation = Donation::query()
                    ->where('checkout_idempotency_key', $checkoutKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingDonation) {
                    if (! hash_equals((string) $existingDonation->checkout_request_hash, $requestHash)) {
                        return $existingDonation;
                    }

                    return $existingDonation;
                }

                $attributes = $request->safe()->only([
                    'donor_name',
                    'donor_email',
                    'donor_address',
                    'donor_city',
                    'donor_province',
                    'donor_postal_code',
                    'donor_country',
                ]);
                $attributes['internal_reference'] = $this->generateInternalReference();
                $attributes['checkout_idempotency_key'] = $checkoutKey;
                $attributes['checkout_request_hash'] = $requestHash;
                $attributes['amount'] = DonationCurrencyAmount::toStorageDecimal(
                    $request->input('amount'),
                    (string) config('donation.currency', 'cad')
                );
                $attributes['currency'] = strtolower((string) config('donation.currency'));
                $attributes['advantage_value'] = 0;
                $attributes['status'] = 'pending';

                return Donation::query()->create($attributes);
            });
        } catch (QueryException $exception) {
            $existingDonation = Donation::query()
                ->where('checkout_idempotency_key', $checkoutKey)
                ->first();

            if (! $existingDonation) {
                throw $exception;
            }

            return $existingDonation;
        }
    }

    private function checkoutSessionResponse(Donation $donation, string $checkoutUrl, int $status): JsonResponse
    {
        return response()->json([
            'message' => $status === 201 ? 'Session de paiement créée.' : 'Session de paiement déjà créée.',
            'checkout_url' => $checkoutUrl,
            'reference' => $donation->internal_reference,
        ], $status);
    }

    private function checkoutFailureResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Le service de paiement rencontre un problème. Veuillez réessayer plus tard.',
        ], 502);
    }

    private function hasValidCheckoutUrl(mixed $checkoutUrl): bool
    {
        if (! is_string($checkoutUrl)) {
            return false;
        }

        $url = parse_url($checkoutUrl);

        return is_array($url)
            && ($url['scheme'] ?? null) === 'https'
            && ($url['host'] ?? null) === 'checkout.stripe.com';
    }

    private function generateInternalReference(): string
    {
        do {
            $reference = 'DON-'.Str::upper(Str::random(8));
        } while (Donation::where('internal_reference', $reference)->exists());

        return $reference;
    }
}