<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $notification->title }}</title>
</head>
<body>
    <p>Bonjour,</p>

    <p><strong>{{ $notification->title }}</strong></p>

    <p>Installation concernée : <strong>{{ $notification->installation_name }}</strong></p>
    <p>Date d’échéance : <strong>{{ $periodEndLabel }}</strong> (UTC)</p>
    <p>Montant mensuel : <strong>{{ $formattedAmount }}</strong></p>

    @foreach ($bodyLines as $line)
        <p>{{ $line }}</p>
    @endforeach

    <p><em>Notification d’échéance d’abonnement MKD-Pro — aucune action de paiement n’est demandée par ce message.</em></p>
</body>
</html>
