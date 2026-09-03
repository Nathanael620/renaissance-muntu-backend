<?php

/** @var string $name */
/** @var string $email */
/** @var string|null $phone */
/** @var string $subject */
/** @var string $contactMessage */
/** @var \Illuminate\Support\Carbon|null $receivedAt */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; background: #f3f4f6; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 24px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #7c3aed; color: #ffffff; padding: 20px 24px; }
        .header h1 { margin: 0; font-size: 20px; }
        .content { padding: 24px; }
        .content p { margin: 12px 0; line-height: 1.6; }
        .field { margin: 12px 0; }
        .label { font-weight: bold; }
        .message-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; margin-top: 8px; white-space: pre-wrap; }
        .footer { padding: 16px 24px; background: #f9fafb; color: #6b7280; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Nouveau message de contact</h1>
    </div>
    <div class="content">
        <div class="field"><span class="label">Nom complet :</span> {{ $name }}</div>
        <div class="field"><span class="label">Adresse e-mail :</span> {{ $email }}</div>
        <div class="field"><span class="label">Numéro de téléphone :</span>
            @if ($phone)
                {{ $phone }}
            @else
                non communiqué
            @endif
        </div>
        <div class="field"><span class="label">Sujet :</span> {{ $subject }}</div>
        <div class="field">
            <span class="label">Message :</span>
            <div class="message-box">{{ $contactMessage }}</div>
        </div>
        <div class="field"><span class="label">Date de réception :</span>
            {{ $receivedAt ? $receivedAt->setTimezone(config('app.timezone'))->format('d/m/Y H:i') : now()->format('d/m/Y H:i') }}
        </div>
    </div>
    <div class="footer">
        Message reçu via le formulaire de contact du site Renaissance Muntu.
    </div>
</div>
</body>
</html>