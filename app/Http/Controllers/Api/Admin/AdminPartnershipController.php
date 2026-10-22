<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnershipRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPartnershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['new', 'review', 'accepted', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $requests = PartnershipRequest::query()
            ->when($filters['q'] ?? null, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', '%'.$term.'%')
                        ->orWhere('organization', 'like', '%'.$term.'%')
                        ->orWhere('email', 'like', '%'.$term.'%')
                        ->orWhere('partnership_type', 'like', '%'.$term.'%')
                        ->orWhere('message', 'like', '%'.$term.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('review_status', $status))
            ->latest()
            ->paginate($filters['per_page'] ?? 20);

        return response()->json($requests);
    }

    public function show(int $partnershipId): JsonResponse
    {
        $partnershipRequest = PartnershipRequest::query()->findOrFail($partnershipId);

        return response()->json(['data' => $partnershipRequest]);
    }

    public function update(Request $request, int $partnershipId): JsonResponse
    {
        $partnershipRequest = PartnershipRequest::query()->findOrFail($partnershipId);
        $data = $request->validate([
            'review_status' => ['required', Rule::in(['new', 'review', 'accepted', 'rejected'])],
        ]);

        $partnershipRequest->update($data);

        return response()->json(['data' => $partnershipRequest->fresh()]);
    }
}
