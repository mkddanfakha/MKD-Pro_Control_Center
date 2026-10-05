/**
 * Présentation partagée du Control Center (libellés, dates UTC, montants).
 * Ne pas y placer de logique métier.
 */

export function displayAdminValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

export function formatAdminDateTimeUtc(value) {
    if (!value) {
        return '—';
    }

    const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const datePart = new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(date);

    const timePart = new Intl.DateTimeFormat('fr-FR', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: 'UTC',
    }).format(date);

    return `${datePart} ${timePart}`;
}

export function formatAdminAmount(amount, currency = 'XOF') {
    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount ?? 0));

    return `${formatted} ${currency ?? 'XOF'}`;
}

export function subscriptionStatusLabel(status) {
    const labels = {
        active: 'Actif',
        grace_period: 'Période de grâce',
        suspended: 'Suspendu',
        terminated: 'Terminé',
    };

    return labels[status] ?? status;
}

export function subscriptionStatusBadgeClass(status) {
    const classes = {
        active: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        grace_period: 'bg-amber-50 text-amber-900 ring-amber-200',
        suspended: 'bg-orange-50 text-orange-900 ring-orange-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

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
export function paymentStatusLabel(status) {
    const labels = {
        pending: 'En attente',
        paid: 'Payé',
        failed: 'Échoué',
        refunded: 'Remboursé',
    };

    return labels[status] ?? status;
}

export function paymentStatusBadgeClass(status) {
    const classes = {
        pending: 'bg-amber-50 text-amber-900 ring-amber-200',
        paid: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        failed: 'bg-red-50 text-red-800 ring-red-200',
        refunded: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}
export const ADMIN_EMPTY_STATE_MESSAGES = {
    clients: 'Aucun client ne correspond aux critères.',
    installations: 'Aucune installation ne correspond aux critères.',
    modules: 'Aucun module enregistré.',
    installationModules: 'Aucune affectation de module pour le moment.',
    auditLogs: 'Aucun événement d’audit ne correspond aux critères.',
};
export function moduleCatalogStatusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
    };

    return labels[status] ?? status;
}

export function moduleCatalogStatusBadgeClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        inactive: 'bg-gray-100 text-gray-600 ring-gray-200',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

export function installationModuleStatusLabel(status) {
    return moduleCatalogStatusLabel(status);
}

export function installationModuleStatusBadgeClass(status) {
    return moduleCatalogStatusBadgeClass(status);
}
