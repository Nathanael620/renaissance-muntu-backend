<h1>Nouvelle demande de partenariat</h1>

<p><strong>Nom :</strong> {{ $name }}</p>
<p><strong>Organisation :</strong> {{ $organization ?: 'Non renseignée' }}</p>
<p><strong>E-mail :</strong> {{ $email }}</p>
<p><strong>Téléphone :</strong> {{ $phone ?: 'Non renseigné' }}</p>
<p><strong>Type de partenariat :</strong> {{ $partnershipType }}</p>
<p><strong>Message :</strong></p>
<p>{!! nl2br(e($partnershipMessage ?: 'Aucun message')) !!}</p>
<p><strong>Reçue le :</strong> {{ $receivedAt }}</p>
