<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendDonationReceiptEmail;
use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\Receipt;
use App\Services\DonationReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use Throwable;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        if (blank($secret) || blank($signature)) {
            Log::warning('Webhook Stripe rejeté : signature ou secret absent.');

            return response()->json(['received' => false], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException $exception) {
            Log::warning('Webhook Stripe rejeté : signature ou payload invalide.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['received' => false], 400);
        }

        try {
            if ($event->type !== 'checkout.session.completed') {
                return response()->json(['received' => true, 'processed' => false]);
            }

            $session = $event->data->object;

            if (($session->object ?? null) !== 'checkout.session') {
                Log::warning('Webhook Stripe rejeté : objet inattendu.', [
                    'event_id' => $event->id,
                ]);

                return response()->json(['received' => false], 400);
            }

            $result = $this->completeDonation($event, $session);

            if (in_array($result['status'], ['completed', 'already_processed'], true)) {
                $this->prepareReceiptEmail($result['donation_id']);

                return response()->json([
                    'received' => true,
                    ...($result['status'] === 'already_processed' ? ['processed' => false] : []),
                ]);
            }

            return response()->json(['received' => true, 'processed' => false]);
        } catch (Throwable $exception) {
            Log::error('Erreur interne lors du traitement du webhook Stripe.', [
                'event_id' => $event->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['received' => false], 500);
        }
    }

    /**
     * @return array{status: string, donation_id?: int}
     */
    private function completeDonation(object $event, object $session): array
    {
        $metadata = $session->metadata ?? null;
        $donationId = $this->stripeValue($metadata, 'donation_id');
        $internalReference = $this->stripeValue($metadata, 'internal_reference');
        $clientReference = $session->client_reference_id ?? null;

        if (blank($donationId) && blank($internalReference) && blank($clientReference)) {
            Log::warning('Webhook Stripe sans identifiant de donation.', [
                'event_id' => $event->id,
            ]);

            return ['status' => 'missing_reference'];
        }

        return DB::transaction(function () use ($event, $session, $donationId, $internalReference, $clientReference): array {
            $donation = $this->findDonation($donationId, $internalReference, $clientReference);

            if (! $donation) {
                Log::warning('Donation introuvable pour le webhook Stripe.', [
                    'event_id' => $event->id,
                    'donation_id' => $donationId,
                    'internal_reference' => $internalReference,
                ]);

                return ['status' => 'not_found'];
            }

            if ($donation->status === 'completed' || $donation->stripe_event_id === $event->id) {
                return ['status' => 'already_processed', 'donation_id' => $donation->id];
            }

            if (! $this->referencesMatch($donation, $internalReference, $clientReference)
                || $donation->stripe_session_id !== ($session->id ?? null)) {
                Log::warning('Incohérence des références du webhook Stripe.', [
                    'event_id' => $event->id,
                    'donation_id' => $donation->id,
                ]);

                return ['status' => 'invalid_references'];
            }

            if (($session->payment_status ?? null) !== 'paid') {
                Log::warning('Paiement Stripe non confirmé.', [
                    'event_id' => $event->id,
                    'donation_id' => $donation->id,
                ]);

                return ['status' => 'unpaid'];
            }

            if (! $this->amountAndCurrencyMatch($donation, $session)) {
                Log::warning('Montant ou devise Stripe incohérent.', [
                    'event_id' => $event->id,
                    'donation_id' => $donation->id,
                ]);

                return ['status' => 'invalid_amount'];
            }

            $donation->update([
                'stripe_event_id' => $event->id,
                'stripe_payment_intent_id' => $this->stripeIdentifier($session->payment_intent ?? null),
                'stripe_customer_id' => $this->stripeIdentifier($session->customer ?? null),
                'status' => 'completed',
                'completed_at' => now(),
                'eligible_amount' => $donation->amount,
            ]);

            return ['status' => 'completed', 'donation_id' => $donation->id];
        });
    }

    private function prepareReceiptEmail(int $donationId): void
    {
        $donation = Donation::find($donationId);

        if (! $donation || $donation->status !== 'completed') {
            return;
        }

        try {
            $receipt = app(DonationReceiptService::class)->generate($donation);
        } catch (Throwable $exception) {
            Log::error('Le reçu ne peut pas être préparé après confirmation du paiement.', [
                'donation_id' => $donationId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $shouldDispatch = DB::transaction(function () use ($receipt): bool {
            $lockedReceipt = Receipt::query()->lockForUpdate()->find($receipt->id);

            if (! $lockedReceipt || $lockedReceipt->email_sent_at !== null || $lockedReceipt->email_queued_at !== null) {
                return false;
            }

            $lockedReceipt->update(['email_queued_at' => now()]);

            return true;
        });

        if ($shouldDispatch) {
            SendDonationReceiptEmail::dispatch($receipt->id)->afterCommit();
        }
    }

    private function findDonation(?string $donationId, ?string $internalReference, ?string $clientReference): ?Donation
    {
        if (filled($donationId) && ctype_digit($donationId)) {
            return Donation::query()->whereKey((int) $donationId)->lockForUpdate()->first();
        }

        $reference = $internalReference ?: $clientReference;

        if (blank($reference)) {
            return null;
        }

        return Donation::query()->where('internal_reference', $reference)->lockForUpdate()->first();
    }

    private function referencesMatch(Donation $donation, ?string $internalReference, ?string $clientReference): bool
    {
        return (blank($internalReference) || $donation->internal_reference === $internalReference)
            && (blank($clientReference) || $donation->internal_reference === $clientReference);
    }

    private function amountAndCurrencyMatch(Donation $donation, object $session): bool
    {
        return $this->amountToMinorUnits($donation->amount) === (int) ($session->amount_total ?? -1)
            && strtolower($donation->currency) === strtolower((string) ($session->currency ?? ''));
    }

    private function amountToMinorUnits(string|float $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = explode('.', $normalized);

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function stripeValue(object|null $object, string $key): ?string
    {
        if (! $object || ! isset($object->{$key})) {
            return null;
        }

        $value = $object->{$key};

        return is_scalar($value) ? (string) $value : null;
    }

    private function stripeIdentifier(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_object($value) && isset($value->id) && is_string($value->id)) {
            return $value->id;
        }

        return null;
    }
}
