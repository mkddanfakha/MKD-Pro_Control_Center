/**
 * Présentation partagée — libellés rappels d’échéance (Control Center).
 */

export function reminderStatusLabel(status) {
    const labels = {
        detected: 'Détecté',
        sent: 'Envoyé',
        failed: 'Échec d’envoi',
    };

    return labels[status] ?? status;
}

export function reminderStatusBadgeClass(status) {
    const classes = {
        detected: 'bg-amber-50 text-amber-900 ring-amber-200',
        sent: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        failed: 'bg-red-50 text-red-800 ring-red-200',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}
