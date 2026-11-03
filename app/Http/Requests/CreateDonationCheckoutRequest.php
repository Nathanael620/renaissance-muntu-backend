<?php

namespace App\Http\Requests;

use App\Support\DonationCurrencyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

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
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/D'],
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
            ],
        ];
    }

    /**
     * The idempotency key is accepted only from the HTTP header, never from JSON.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ];
    }

    /**
     * Validate currency precision and configured limits using integer minor units.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('amount')) {
                    return;
                }

                $currency = (string) config('donation.currency', 'cad');
                $amount = $this->input('amount');

                try {
                    if (! DonationCurrencyAmount::hasSupportedPrecision($amount, $currency)
                        || ! DonationCurrencyAmount::fitsStorageRange($amount)) {
                        $validator->errors()->add('amount', 'Le montant ne respecte pas la précision de la devise.');

                        return;
                    }

                    $amountMinorUnits = DonationCurrencyAmount::toMinorUnits($amount, $currency);
                    $minimum = DonationCurrencyAmount::toMinorUnits(
                        (string) config('donation.amounts.min'),
                        $currency
                    );
                    $maximum = DonationCurrencyAmount::toMinorUnits(
                        (string) config('donation.amounts.max'),
                        $currency
                    );
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('amount', 'La devise ou la précision du montant n’est pas valide.');

                    return;
                }

                if ($amountMinorUnits < $minimum) {
                    $validator->errors()->add(
                        'amount',
                        'Le montant minimum d\'un don est '.config('donation.amounts.min').' '.strtoupper($currency).'.'
                    );

                    return;
                }

                if ($amountMinorUnits > $maximum) {
                    $validator->errors()->add(
                        'amount',
                        'Le montant maximum d\'un don est '.config('donation.amounts.max').' '.strtoupper($currency).'.'
                    );
                }
            },
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
            'idempotency_key.required' => 'L\'en-tête Idempotency-Key est obligatoire.',
            'idempotency_key.regex' => 'L\'en-tête Idempotency-Key doit contenir de 16 à 64 caractères alphanumériques, tirets ou tirets bas.',
            'donor_name.required' => 'Le nom du donateur est obligatoire.',
            'donor_email.required' => 'L\'adresse e-mail du donateur est obligatoire.',
            'donor_email.email' => 'L\'adresse e-mail du donateur n\'est pas valide.',
            'amount.required' => 'Le montant du don est obligatoire.',
            'amount.numeric' => 'Le montant du don doit être un nombre.',
        ];
    }
}