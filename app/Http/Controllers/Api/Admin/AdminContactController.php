<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['new', 'read', 'in_progress', 'archived'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $contacts = Contact::query()
            ->when($filters['q'] ?? null, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', '%'.$term.'%')
                        ->orWhere('email', 'like', '%'.$term.'%')
                        ->orWhere('subject', 'like', '%'.$term.'%')
                        ->orWhere('message', 'like', '%'.$term.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('workflow_status', $status))
            ->latest()
            ->paginate($filters['per_page'] ?? 20);

        return response()->json($contacts);
    }

    public function show(int $contactId): JsonResponse
    {
        $contact = Contact::query()->findOrFail($contactId);

        return response()->json(['data' => $contact]);
    }

    public function update(Request $request, int $contactId): JsonResponse
    {
        $contact = Contact::query()->findOrFail($contactId);
        $data = $request->validate([
            'workflow_status' => ['required', Rule::in(['new', 'read', 'in_progress', 'archived'])],
        ]);

        $contact->update($data);

        return response()->json(['data' => $contact->fresh()]);
    }
}
