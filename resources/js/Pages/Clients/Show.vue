<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
    installationOverviews: {
        type: Array,
        default: () => [],
    },
    ecosystemSummary: {
        type: Object,
        required: true,
    },
});

const page = usePage();

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function statusLabel(status) {
    return status === 'active' ? 'Actif' : 'Inactif';
}

function installationStatusLabel(status) {
    const labels = {
        active: 'Installation active',
        inactive: 'Installation inactive',
        suspended: 'Installation suspendue',
        terminated: 'Installation terminée',
    };

    return labels[status] ?? status;
}

function installationStatusBadgeClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        inactive: 'bg-gray-100 text-gray-600 ring-gray-200',
        suspended: 'bg-amber-50 text-amber-900 ring-amber-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatAmount(amount, currency) {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount));

    return `${formatted} ${currency ?? 'XOF'}`;
}

function subscriptionStatusLabel(status) {
    const labels = {
        active: 'Actif',
        grace_period: 'Période de grâce',
        suspended: 'Suspendu',
        terminated: 'Terminé',
    };

    return labels[status] ?? status;
}

function subscriptionStatusBadgeClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        grace_period: 'bg-amber-50 text-amber-900 ring-amber-200',
        suspended: 'bg-orange-50 text-orange-900 ring-orange-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function accessPrimaryLabel(access) {
    if (!access?.accessible) {
        return 'Accès non autorisé';
    }

    return 'Accès autorisé';
}

function accessDetailLabel(access) {
    if (!access) {
        return 'Aucun abonnement';
    }

    if (access.status === 'suspended') {
        return 'Abonnement suspendu';
    }

    if (access.status === 'terminated') {
        return 'Abonnement terminé';
    }

    if (access.status === 'no_subscription') {
        return 'Aucun abonnement';
    }

    if (access.subscription_status === 'grace_period') {
        return 'Période de grâce';
    }

    if (access.subscription_status === 'active') {
        return 'Abonnement actif';
    }

    return '—';
}

function accessBadgeClass(access) {
    if (access?.accessible) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    }

    return 'border-amber-200 bg-amber-50 text-amber-950';
}

function formatPeriodRange(start, end) {
    const startLabel = formatDateOnly(start);
    const endLabel = formatDateOnly(end);

    if (startLabel === '—' && endLabel === '—') {
        return '—';
    }

    return `${startLabel} → ${endLabel}`;
}

function formatDateOnly(value) {
    if (!value || String(value).trim() === '') {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
}

function creditMonthsLabel(months) {
    const value = Number(months);

    if (Number.isNaN(value)) {
        return '—';
    }

    if (value === 0) {
        return '0 mois disponible';
    }

    if (value === 1) {
        return '1 mois disponible';
    }

    return `${value} mois disponibles`;
}

function formatDate(value) {
    if (!value || String(value).trim() === '') {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const datePart = new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);

    const timePart = new Intl.DateTimeFormat('fr-FR', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);

    return `${datePart} ${timePart}`;
}
</script>

<template>
    <Head :title="client.company_name ? `Client — ${client.company_name}` : 'Détail du client'" />

    <AdminLayout>
        <div>
            <div
                v-if="page.flash.success"
                class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800"
                role="status"
            >
                {{ page.flash.success }}
            </div>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Détail du client
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Consultez les informations de ce client.
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        href="/clients"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour aux clients
                    </Link>

                    <Link
                        :href="`/clients/${client.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier
                    </Link>
                </div>
            </div>

            <div class="mt-8 space-y-8">
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Informations de l'entreprise
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Entreprise
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(client.company_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Contact
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(client.contact_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="
                                        client.status === 'active'
                                            ? 'bg-sky-50 text-sky-800 ring-sky-200'
                                            : 'bg-gray-100 text-gray-600 ring-gray-200'
                                    "
                                >
                                    {{ statusLabel(client.status) }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Coordonnées
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Téléphone
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(client.phone) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Adresse e-mail
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all">
                                {{ displayValue(client.email) }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Adresse
                            </dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-gray-900">
                                {{ displayValue(client.address) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Ville
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(client.city) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Pays
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(client.country) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Informations complémentaires
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Notes
                            </dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-gray-900">
                                {{ displayValue(client.notes) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de création
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(client.created_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Dernière modification
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(client.updated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                Écosystème MKD-Pro
                            </h2>

                            <p class="mt-1 text-sm text-gray-500">
                                Installations, abonnements, accès et crédit pour ce client.
                            </p>
                        </div>

                        <Link
                            href="/installations/create"
                            class="inline-flex shrink-0 items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                        >
                            Nouvelle installation
                        </Link>
                    </div>

                    <div
                        v-if="!installationOverviews.length"
                        class="mt-6 rounded-lg border border-dashed border-gray-300 bg-gray-50/80 px-6 py-10 text-center"
                    >
                        <h3 class="text-base font-semibold text-gray-900">
                            Aucune installation MKD-Pro pour ce client.
                        </h3>

                        <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                            Créez une installation pour commencer à gérer l’abonnement et les paiements.
                        </p>
                    </div>

                    <div
                        v-else
                        class="mt-6 space-y-6"
                    >
                        <article
                            v-for="overview in installationOverviews"
                            :key="overview.installation.id"
                            class="rounded-xl border border-gray-200 bg-gray-50/50 p-5 sm:p-6"
                        >
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900">
                                        {{ overview.installation.name }}
                                    </h3>

                                    <p class="mt-1 text-sm text-gray-500">
                                        {{ client.company_name }}
                                    </p>
                                </div>

                                <Link
                                    :href="`/installations/${overview.installation.id}`"
                                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                >
                                    Voir l’installation
                                </Link>
                            </div>

                            <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                        Sous-domaine
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ displayValue(overview.installation.subdomain) }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                        Installation
                                    </dt>
                                    <dd class="mt-1">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            :class="installationStatusBadgeClass(overview.installation.status)"
                                        >
                                            {{ installationStatusLabel(overview.installation.status) }}
                                        </span>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                        Accès
                                    </dt>
                                    <dd class="mt-1">
                                        <span
                                            class="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                            :class="accessBadgeClass(overview.access)"
                                        >
                                            {{ accessPrimaryLabel(overview.access) }}
                                        </span>
                                        <p class="mt-1 text-sm text-gray-600">
                                            {{ accessDetailLabel(overview.access) }}
                                        </p>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                        Abonnement
                                    </dt>
                                    <dd class="mt-1">
                                        <template v-if="overview.subscription">
                                            <span
                                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                                :class="subscriptionStatusBadgeClass(overview.subscription.status)"
                                            >
                                                {{ subscriptionStatusLabel(overview.subscription.status) }}
                                            </span>
                                            <p class="mt-2 text-sm font-medium text-gray-900">
                                                {{ formatAmount(overview.subscription.amount, overview.subscription.currency) }}/mois
                                            </p>
                                            <Link
                                                v-if="overview.subscription.id"
                                                :href="`/subscriptions/${overview.subscription.id}`"
                                                class="mt-2 inline-block text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                            >
                                                Voir l’abonnement
                                            </Link>
                                        </template>
                                        <p
                                            v-else
                                            class="text-sm text-gray-600"
                                        >
                                            Aucun abonnement
                                        </p>
                                    </dd>
                                </div>

                                <div
                                    v-if="overview.subscription?.current_period_start || overview.subscription?.current_period_end"
                                    class="sm:col-span-2"
                                >
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                        Période
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ formatPeriodRange(overview.subscription.current_period_start, overview.subscription.current_period_end) }}
                                    </dd>
                                </div>

                                <div v-if="overview.credit">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                        Crédit disponible
                                    </dt>
                                    <dd class="mt-1 text-sm font-medium text-gray-900">
                                        {{ creditMonthsLabel(overview.credit.available_months) }}
                                    </dd>
                                </div>

                                <div v-if="overview.credit">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                        Paiements (abonnement)
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ overview.credit.payment_count }}
                                    </dd>
                                </div>
                            </dl>
                        </article>
                    </div>

                    <div class="mt-8 rounded-xl border border-gray-200 bg-white p-5 sm:p-6">
                        <h3 class="text-sm font-semibold text-gray-900">
                            Paiements du client
                        </h3>

                        <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                    Paiements
                                </dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">
                                    {{ ecosystemSummary.payments_count }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                    Dernier paiement
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <template v-if="ecosystemSummary.last_payment">
                                        {{ formatAmount(ecosystemSummary.last_payment.amount, ecosystemSummary.last_payment.currency) }}
                                        — {{ formatDateOnly(ecosystemSummary.last_payment.paid_at) }}
                                    </template>
                                    <template v-else>
                                        —
                                    </template>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                    Crédit disponible (total)
                                </dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">
                                    {{ creditMonthsLabel(ecosystemSummary.total_available_credit_months) }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-4">
                            <Link
                                href="/payments"
                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                            >
                                Voir tous les paiements
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
