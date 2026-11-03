<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Services\DonationReceiptEmailDispatcher;
use App\Support\DonationCurrencyAmount;
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
    public function handle(Request $request, DonationReceiptEmailDispatcher $receiptEmailDispatcher): JsonResponse
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
                'exception' => $exception::class,
            ]);

            return response()->json(['received' => false], 400);
        }

        try {
            if (! in_array($event->type, [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
            ], true)) {
                return response()->json(['received' => true, 'processed' => false]);
            }

            $session = $event->data->object;

            if (($session->object ?? null) !== 'checkout.session') {
                Log::warning('Webhook Stripe rejeté : objet inattendu.', [
                    'event_id' => $event->id,
                ]);

                return response()->json(['received' => false], 400);
            }

            if ($event->type === 'checkout.session.async_payment_failed') {
                return response()->json([
                    'received' => true,
                    'processed' => $this->failDonation($event, $session),
                ]);
            }

            $result = $this->completeDonation($event, $session);

            if (in_array($result['status'], ['completed', 'already_processed'], true)) {
                $receiptEmailDispatcher->dispatchForDonation(
                    Donation::query()->findOrFail($result['donation_id'])
                );

                return response()->json([
                    'received' => true,
                    ...($result['status'] === 'already_processed' ? ['processed' => false] : []),
                ]);
            }

            return response()->json(['received' => true, 'processed' => false]);
        } catch (Throwable $exception) {
            Log::error('Erreur interne lors du traitement du webhook Stripe.', [
                'event_id' => $event->id ?? null,
                'exception' => $exception::class,
            ]);

            return response()->json(['received' => false], 500);
        }
    }

    /**
     * @return array{status: string, donation_id?: int}
     */
    private function completeDonation(object $event, object $session): array
    {
        $donationId = $this->stripeValue($session->metadata ?? null, 'donation_id');
        $internalReference = $this->stripeValue($session->metadata ?? null, 'internal_reference');
        $clientReference = is_string($session->client_reference_id ?? null)
            ? $session->client_reference_id
            : null;

        if (! filled($donationId) || ! ctype_digit($donationId)) {
            return ['status' => 'missing_reference'];
        }

        return DB::transaction(function () use (
            $event,
            $session,
            $donationId,
            $internalReference,
            $clientReference
        ): array {
            $donation = Donation::query()
                ->whereKey((int) $donationId)
                ->lockForUpdate()
                ->first();

            if (! $donation
                || ! $this->referencesMatch($donation, $internalReference, $clientReference)
                || ! filled($session->id ?? null)
                || $donation->stripe_session_id !== $session->id) {
                return ['status' => 'invalid_references'];
            }

            if (($session->mode ?? null) !== 'payment'
                || ($session->status ?? null) !== 'complete'
                || ($session->payment_status ?? null) !== 'paid') {
                return ['status' => 'unpaid'];
            }

            if (! $this->amountAndCurrencyMatch($donation, $session)) {
                return ['status' => 'invalid_amount'];
            }

            if ($donation->status === 'completed') {
                return ['status' => 'already_processed', 'donation_id' => $donation->id];
            }

            if (! in_array($donation->status, ['pending', 'failed'], true)) {
                return ['status' => 'invalid_state'];
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

    private function failDonation(object $event, object $session): bool
    {
        $donationId = $this->stripeValue($session->metadata ?? null, 'donation_id');
        $internalReference = $this->stripeValue($session->metadata ?? null, 'internal_reference');
        $clientReference = is_string($session->client_reference_id ?? null)
            ? $session->client_reference_id
            : null;

        if (! filled($donationId) || ! ctype_digit($donationId)) {
            return false;
        }

        return DB::transaction(function () use (
            $event,
            $session,
            $donationId,
            $internalReference,
            $clientReference
        ): bool {
            $donation = Donation::query()
                ->whereKey((int) $donationId)
                ->lockForUpdate()
                ->first();

            if (! $donation
                || $donation->status !== 'pending'
                || ! $this->referencesMatch($donation, $internalReference, $clientReference)
                || ! filled($session->id ?? null)
                || $donation->stripe_session_id !== $session->id
                || ($session->mode ?? null) !== 'payment'
                || ($session->status ?? null) !== 'complete'
                || ($session->payment_status ?? null) !== 'unpaid'
                || ! $this->amountAndCurrencyMatch($donation, $session)) {
                return false;
            }

            $donation->update([
                'stripe_event_id' => $event->id,
                'status' => 'failed',
            ]);

            return true;
        });
    }

    private function referencesMatch(
        Donation $donation,
        ?string $internalReference,
        ?string $clientReference
    ): bool {
        return filled($internalReference)
            && filled($clientReference)
            && $donation->internal_reference === $internalReference
            && $donation->internal_reference === $clientReference;
    }

    private function amountAndCurrencyMatch(Donation $donation, object $session): bool
    {
        $amountTotal = $session->amount_total ?? null;
        $currency = $session->currency ?? null;

        if (! is_int($amountTotal) || ! is_string($currency)) {
            return false;
        }

        try {
            return DonationCurrencyAmount::toMinorUnits($donation->amount, $donation->currency) === $amountTotal
                && strtolower($donation->currency) === strtolower($currency);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function stripeValue(?object $object, string $key): ?string
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
