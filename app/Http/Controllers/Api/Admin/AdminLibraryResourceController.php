<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LibraryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminLibraryResourceController extends Controller
{
    private const CATEGORIES = ['livre', 'essai', 'manifeste', 'video', 'podcast', 'etude-recherche'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['draft', 'pending', 'published'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $resources = LibraryResource::query()
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where('title', 'like', '%'.$term.'%'))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($filters['per_page'] ?? 20);

        return response()->json($resources);
    }

    public function store(Request $request): JsonResponse
    {
        $resource = LibraryResource::query()->create($this->validatedData($request));

        return response()->json(['data' => $resource], 201);
    }

    public function show(int $resourceId): JsonResponse
    {
        $resource = LibraryResource::query()->findOrFail($resourceId);

        return response()->json(['data' => $resource]);
    }

    public function update(Request $request, int $resourceId): JsonResponse
    {
        $resource = LibraryResource::query()->findOrFail($resourceId);
        $resource->update($this->validatedData($request, partial: true));

        return response()->json(['data' => $resource->fresh()]);
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'category' => [$required, Rule::in(self::CATEGORIES)],
            'type' => [$required, 'string', 'max:100'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'external_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'status' => [$required, Rule::in(['draft', 'pending', 'published'])],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);
    }
}
