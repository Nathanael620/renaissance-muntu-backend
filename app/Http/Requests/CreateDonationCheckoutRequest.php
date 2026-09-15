<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDonationCheckoutRequest extends FormRequest
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
            'donor_name' => ['required', 'string', 'max:255'],
            'donor_email' => ['required', 'email', 'max:255'],
            'donor_address' => ['nullable', 'string', 'max:255'],
            'donor_city' => ['nullable', 'string', 'max:255'],
            'donor_province' => ['nullable', 'string', 'max:255'],
            'donor_postal_code' => ['nullable', 'string', 'max:20'],
            'donor_country' => ['nullable', 'string', 'max:255'],
            'amount' => [
                'required',
                'numeric',
                'min:'.config('donation.amounts.min'),
                'max:'.config('donation.amounts.max'),
            ],
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
            'donor_name.required' => 'Le nom du donateur est obligatoire.',
            'donor_email.required' => 'L\'adresse e-mail du donateur est obligatoire.',
            'donor_email.email' => 'L\'adresse e-mail du donateur n\'est pas valide.',
            'amount.required' => 'Le montant du don est obligatoire.',
            'amount.numeric' => 'Le montant du don doit être un nombre.',
            'amount.min' => 'Le montant minimum d\'un don est '.config('donation.amounts.min').' '.strtoupper((string) config('donation.currency')).'.',
            'amount.max' => 'Le montant maximum d\'un don est '.config('donation.amounts.max').' '.strtoupper((string) config('donation.currency')).'.',
        ];
    }
}