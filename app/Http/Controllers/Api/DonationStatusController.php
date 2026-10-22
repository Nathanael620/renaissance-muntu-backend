<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Http\JsonResponse;

class DonationStatusController extends Controller
{
    public function __invoke(string $sessionId): JsonResponse
    {
        $donation = Donation::query()
            ->where('stripe_session_id', $sessionId)
            ->first();

        if (! $donation) {
            return response()->json(['message' => 'Session de paiement introuvable.'], 404);
        }

        return response()->json([
            'status' => match ($donation->status) {
                'completed' => 'completed',
                'pending' => 'pending',
                default => 'not_completed',
            },
        ]);
    }
}
