<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reçu officiel de don {{ $receipt->number }}</title>
    <style>
        @page { margin: 32px 38px; }
        body { color: #1f2933; font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.45; }
        h1 { color: #123b4a; font-size: 19px; margin: 0 0 3px; }
        h2 { color: #123b4a; font-size: 13px; margin: 0 0 12px; }
        h3 { border-bottom: 1px solid #b8c8cd; color: #123b4a; font-size: 12px; margin: 18px 0 8px; padding-bottom: 4px; }
        table { border-collapse: collapse; width: 100%; }
        td { padding: 3px 0; vertical-align: top; }
        .header { border-bottom: 2px solid #123b4a; padding-bottom: 12px; }
        .organization { color: #40545b; font-size: 10px; text-align: right; }
        .meta { margin-top: 14px; }
        .meta td:first-child, .label { color: #52666d; font-weight: bold; width: 38%; }
        .box { background: #f1f6f7; border: 1px solid #c6d5d9; padding: 10px 12px; }
        .amount { color: #123b4a; font-size: 16px; font-weight: bold; text-align: right; }
        .footer { border-top: 1px solid #b8c8cd; color: #52666d; font-size: 9px; margin-top: 22px; padding-top: 8px; }
        .signature { margin-top: 28px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>Reçu officiel de don aux fins de l'impôt sur le revenu</h1>
                <h2>Official donation receipt for income tax purposes</h2>
            </td>
            <td class="organization">
                <strong>{{ config('donation.organization.legal_name') }}</strong><br>
                {{ config('donation.organization.address.line1') }}<br>
                {{ config('donation.organization.address.line2') }}<br>
                {{ config('donation.organization.phone') }}<br>
                {{ config('donation.organization.email') }}<br>
                {{ config('donation.organization.website') }}
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td>Reçu / Receipt n°</td>
            <td>{{ $receipt->number }}</td>
        </tr>
        <tr>
            <td>Date de remise / Date issued</td>
            <td>{{ $issuedAt->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td>Lieu de remise / Location issued</td>
            <td>{{ config('donation.organization.issuance_place') }}</td>
        </tr>
        <tr>
            <td>Numéro d'enregistrement CRA</td>
            <td>{{ config('donation.organization.cra_registration_number') }}</td>
        </tr>
    </table>

    <h3>Donateur / Donor</h3>
    <table>
        <tr><td class="label">Nom / Name</td><td>{{ $donation->donor_name }}</td></tr>
        <tr><td class="label">Adresse / Address</td><td>{{ $donation->donor_address }}<br>{{ $donation->donor_city }}, {{ $donation->donor_province }} {{ $donation->donor_postal_code }}<br>{{ $donation->donor_country }}</td></tr>
        <tr><td class="label">Courriel / Email</td><td>{{ $donation->donor_email }}</td></tr>
    </table>

    <h3>Don / Donation</h3>
    <table class="box">
        <tr><td class="label">Date du don / Donation date</td><td>{{ optional($donation->created_at)->format('d/m/Y') }}</td></tr>
        <tr><td class="label">Montant / Amount</td><td class="amount">{{ $donation->amount }} {{ strtoupper($donation->currency) }}</td></tr>
        <tr><td class="label">Valeur de l'avantage / Advantage</td><td>{{ $donation->advantage_value }} {{ strtoupper($donation->currency) }}</td></tr>
        @if ($donation->advantage_description)
            <tr><td class="label">Description de l'avantage</td><td>{{ $donation->advantage_description }}</td></tr>
        @endif
        <tr><td class="label">Montant admissible / Eligible amount</td><td class="amount">{{ $donation->eligible_amount }} {{ strtoupper($donation->currency) }}</td></tr>
    </table>

    <h3>Agence du revenu du Canada / Canada Revenue Agency</h3>
    <p>Canada Revenue Agency<br>Agence du revenu du Canada<br>{{ config('donation.organization.cra_website') }}</p>

    <div class="signature">
        Signataire autorisé / Authorized signatory: <strong>{{ $authorizedSignatory }}</strong>
    </div>

    <div class="footer">
        {{ config('donation.organization.legal_name') }} est un organisme de bienfaisance enregistré. Ce reçu est établi à partir des renseignements conservés par l'organisation.
        / This receipt is issued from the organization's records.
    </div>
</body>
</html>
