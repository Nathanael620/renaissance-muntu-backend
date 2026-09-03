<?php

namespace App\Http\Requests;

use App\Models\PartnershipRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnershipRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'partnership_type' => ['required', 'string', Rule::in(PartnershipRequest::TYPES)],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Get custom (French) messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
            'organization.max' => 'L’organisation ne doit pas dépasser 255 caractères.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'L’adresse e-mail n’est pas valide.',
            'email.max' => 'L’adresse e-mail ne doit pas dépasser 255 caractères.',
            'phone.max' => 'Le téléphone ne doit pas dépasser 30 caractères.',
            'partnership_type.required' => 'Le type de partenariat est obligatoire.',
            'partnership_type.in' => 'Le type de partenariat sélectionné n’est pas valide.',
            'message.max' => 'Le message ne doit pas dépasser 5000 caractères.',
        ];
    }
}
