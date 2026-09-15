<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { color: #24343b; font-family: Arial, Helvetica, sans-serif; line-height: 1.6; }
        .container { margin: 0 auto; max-width: 620px; }
        .header { border-bottom: 2px solid #123b4a; padding-bottom: 14px; }
        h1 { color: #123b4a; font-size: 21px; margin: 0; }
        h2 { color: #52666d; font-size: 14px; font-weight: normal; margin: 3px 0 0; }
        .summary { background: #f1f6f7; border: 1px solid #c6d5d9; margin: 22px 0; padding: 14px 18px; }
        .label { color: #52666d; font-weight: bold; }
        .footer { border-top: 1px solid #d6e0e3; color: #52666d; font-size: 12px; margin-top: 24px; padding-top: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Paiement reçu / Payment received</h1>
        <h2>{{ config('donation.organization.legal_name') }}</h2>
    </div>

    <p>
        Bonjour {{ $donation->donor_name }},
    </p>

    <p>
        Nous confirmons la réception de votre don. Votre reçu officiel de don est joint à ce courriel.
        / We confirm receipt of your donation. Your official donation receipt is attached to this email.
    </p>

    <div class="summary">
        <div><span class="label">Reçu / Receipt :</span> {{ $receipt->number }}</div>
        <div><span class="label">Montant / Amount :</span> {{ $donation->amount }} {{ strtoupper($donation->currency) }}</div>
        <div><span class="label">Date du don / Donation date :</span> {{ optional($donation->created_at)->format('d/m/Y') }}</div>
    </div>

    <p>
        Merci pour votre soutien.<br>
        Thank you for your support.
    </p>

    <div class="footer">
        Le reçu officiel est fourni en pièce jointe au format PDF. / The official receipt is attached as a PDF document.
    </div>
</div>
</body>
</html>
