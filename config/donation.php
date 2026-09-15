<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Beneficiary Organization
    |--------------------------------------------------------------------------
    |
    | Official information of the beneficiary organization (MENER AUTREMENT INC),
    | used for official donation receipts for Canadian income tax purposes.
    | These values come from the official registration document and must never
    | be invented or altered. The authorized signatory is NOT derived from the
    | contact person: it must be filled via CHARITY_AUTHORIZED_SIGNATORY once
    | the organization officially confirms who is authorized to sign receipts.
    |
    */

    'organization' => [
        'legal_name' => 'MENER AUTREMENT INC',
        'cra_registration_number' => '86984 1601 RR0001',
        'address' => [
            'line1' => '11794, Avenue P.-M.-Favier',
            'line2' => 'Montréal-Nord (Québec) H1G 5Z7',
        ],
        'phone' => '(514) 881-7216',
        'email' => 'info@menerautrement.org',
        'issuance_place' => 'Montréal',
        'website' => 'www.menerautrement.org',
        'cra_website' => env('CHARITY_CRA_WEBSITE', 'canada.ca/charities-giving'),

        // Person indicated in the official document — NOT automatically the
        // authorized signatory of donation receipts.
        'contact_person' => 'Oscar Elimby',
        'authorized_signatory' => env('CHARITY_AUTHORIZED_SIGNATORY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Configuration
    |--------------------------------------------------------------------------
    |
    | The currency and server-side amount limits are read from the environment
    | (DONATION_CURRENCY, DONATION_MIN_AMOUNT, DONATION_MAX_AMOUNT). The
    | 'cad' / 5 / 10000 defaults are a safe fallback for local development only;
    | production values are set via the environment.
    |
    */

    'currency' => env('DONATION_CURRENCY') ?: 'cad',

    'amounts' => [
        'min' => (float) (env('DONATION_MIN_AMOUNT') ?: 5),
        'max' => (float) (env('DONATION_MAX_AMOUNT') ?: 10000),
    ],

    // Base URL of the React frontend that hosts the /don/success and /don/cancel
    // pages. Used to build Stripe Checkout redirect URLs (never hard-coded).
    'frontend_url' => env('FRONTEND_URL'),

    /*
    |--------------------------------------------------------------------------
    | Receipt Numbering
    |--------------------------------------------------------------------------
    |
    | The receipt number prefix is driven by the environment
    | (RECEIPT_NUMBER_PREFIX) so the official series can be configured without
    | a code change. No default is hard-coded here on purpose.
    |
    */

    'receipt' => [
        'number_prefix' => env('RECEIPT_NUMBER_PREFIX'),
    ],

    'receipt_number_prefix' => env('RECEIPT_NUMBER_PREFIX'),

];
