<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    admin_urls: {
        type: Object,
        default: () => ({}),
    },
    navigation: {
        type: Object,
        default: () => ({}),
    },
    client: {
        type: Object,
        required: true,
    },
    statistics: {
        type: Object,
        required: true,
    },
    installations: {
        type: Object,
        required: true,
    },
    subscriptions: {
        type: Object,
        required: true,
    },
    payments: {
        type: Object,
        required: true,
    },
    reminders: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const deleting = ref(false);
const showDeleteConfirm = ref(false);

function cancelDelete() {
    if (deleting.value) {
        return;
    }

    showDeleteConfirm.value = false;
}

function confirmDelete() {
    if (deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(`/clients/${props.client.id}`, {
        onFinish: () => {
            deleting.value = false;
            showDeleteConfirm.value = false;
        },
    });
}

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);

    if (Number.isNaN(date.getTime())) {
        const fallback = new Date(value);

        if (Number.isNaN(fallback.getTime())) {
            return '—';
        }

        return formatFrenchDateTime(fallback);
    }

    return formatFrenchDateTime(date);
}

function formatFrenchDateTime(date) {
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

function formatPeriodEndUtc(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: 'UTC',
    }).format(new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`));
}

function clientStatusLabel(status) {
    return status === 'active' ? 'Actif' : 'Inactif';
}

function clientStatusBadgeClass(status) {
    return status === 'active'
        ? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
        : 'bg-gray-100 text-gray-600 ring-gray-200';
}

function installationStatusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
        suspended: 'Suspendu',
        terminated: 'Terminé',
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
        active: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        grace_period: 'bg-amber-50 text-amber-900 ring-amber-200',
        suspended: 'bg-orange-50 text-orange-900 ring-orange-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function paymentStatusLabel(status) {
    const labels = {
        pending: 'En attente',
        paid: 'Payé',
        failed: 'Échoué',
        refunded: 'Remboursé',
    };

    return labels[status] ?? status;
}

function paymentStatusBadgeClass(status) {
    const classes = {
        pending: 'bg-amber-50 text-amber-900 ring-amber-200',
        paid: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        failed: 'bg-red-50 text-red-800 ring-red-200',
        refunded: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function reminderStatusLabel(status) {
    const labels = {
        detected: 'Détecté',
        sent: 'Envoyé',
        failed: 'Échec',
    };

    return labels[status] ?? status;
}

function reminderStatusBadgeClass(status) {
    const classes = {
        detected: 'bg-amber-50 text-amber-900 ring-amber-200',
        sent: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        failed: 'bg-red-50 text-red-800 ring-red-200',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function thresholdLabel(days) {
    if (days === 0) {
        return 'J0';
    }

    return `J-${days}`;
}

function formatAmount(amount, currency) {
    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount ?? 0));

    return `${formatted} ${currency ?? 'XOF'}`;
}

function visitPagination(url) {
    if (!url) {
        return;
    }

    router.get(url, {}, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="client.company_name" />

    <AdminLayout>
        <div class="space-y-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <Link
                        :href="navigation.clients_index ?? '/clients'"
                        class="text-sm font-medium text-gray-600 underline-offset-2 hover:text-gray-900 hover:underline"
                    >
                        ← Retour aux clients
                    </Link>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900">
                            {{ client.company_name }}
                        </h1>
                        <span
                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                            :class="clientStatusBadgeClass(client.status)"
                        >
                            {{ clientStatusLabel(client.status) }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm text-gray-600">
                        Identifiant client #{{ client.id }}
                    </p>

                </div>

                <div class="flex flex-wrap gap-3">
                    <Link
                        v-if="navigation.edit"
                        :href="navigation.edit"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50"
                    >
                        Modifier
                    </Link>
                    <button
                        v-if="admin_urls.can_delete !== false"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-medium text-red-700 shadow-sm transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="deleting"
                        @click="showDeleteConfirm = true"
                    >
                        Supprimer
                    </button>
                    <p
                        v-else-if="admin_urls.delete_unavailable_reason"
                        class="text-sm text-gray-600"
                    >
                        {{ admin_urls.delete_unavailable_reason }}
                    </p>
                </div>
            </div>

            <div
                v-if="showDeleteConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-client-show-title"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2
                        id="delete-client-show-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Supprimer le client
                    </h2>
                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer ce client ?
                    </p>
                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm"
                            :disabled="deleting"
                            @click="cancelDelete"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-red-700 disabled:opacity-60"
                            :disabled="deleting"
                            @click="confirmDelete"
                        >
                            {{ deleting ? 'Suppression…' : 'Supprimer' }}
                        </button>
                    </div>
                </div>
            </div>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Informations client
                </h2>
                <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Contact
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(client.contact_name) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            E-mail
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(client.email) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Téléphone
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(client.phone) }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Adresse
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(client.address) }}
                            <span v-if="client.city"> — {{ client.city }}</span>
                            <span v-if="client.country"> ({{ client.country }})</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Créé le
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatDateTime(client.created_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Mis à jour le
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatDateTime(client.updated_at) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Statistiques
                </h2>
                <div class="mt-6 grid gap-6 lg:grid-cols-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800">
                            Installations
                        </h3>
                        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    Total
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.installations.total }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    Actives
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.installations.active }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    Inactives
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.installations.inactive }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    Suspendues
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.installations.suspended }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    Terminées
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.installations.terminated }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800">
                            Abonnements
                        </h3>
                        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-2">
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    Actifs
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.subscriptions.active }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    En grâce
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.subscriptions.grace_period }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    Suspendus
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.subscriptions.suspended }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="text-xs text-gray-500">
                                    Terminés
                                </dt>
                                <dd class="text-lg font-semibold text-gray-900">
                                    {{ statistics.subscriptions.terminated }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Installations
                </h2>
                <div
                    v-if="!installations.data.length"
                    class="mt-4 text-sm text-gray-500"
                >
                    Aucune installation pour ce client.
                </div>
                <div
                    v-else
                    class="mt-6 overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Nom
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Sous-domaine
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Domaine
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Statut
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Version
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 xl:table-cell">
                                    Installée le
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="installation in installations.data"
                                :key="installation.id"
                            >
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ installation.name }}
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 md:table-cell">
                                    {{ displayValue(installation.subdomain) }}
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ displayValue(installation.domain) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="installationStatusBadgeClass(installation.status)"
                                    >
                                        {{ installationStatusLabel(installation.status) }}
                                    </span>
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ displayValue(installation.version) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 xl:table-cell">
                                    {{ formatDateTime(installation.installed_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="installation.show_url ?? `/installations/${installation.id}`"
                                        class="text-sm font-medium text-gray-900 underline-offset-2 hover:underline"
                                    >
                                        Ouvrir la fiche
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="installations.links && installations.links.length > 3"
                    class="mt-4 flex flex-wrap justify-center gap-1"
                >
                    <button
                        v-for="(link, index) in installations.links"
                        :key="`inst-${index}`"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm disabled:text-gray-400"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="link.label"
                    />
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Abonnements
                </h2>
                <p class="mt-1 text-xs text-gray-500">
                    Historique consultatif — y compris les abonnements terminés.
                </p>
                <div
                    v-if="!subscriptions.data.length"
                    class="mt-4 text-sm text-gray-500"
                >
                    Aucun abonnement enregistré pour ce client.
                </div>
                <div
                    v-else
                    class="mt-6 overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Installation
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Montant
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Statut
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Fin de période
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Début
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="subscription in subscriptions.data"
                                :key="subscription.id"
                            >
                                <td class="px-4 py-3 text-gray-900">
                                    {{ displayValue(subscription.installation_name) }}
                                    <span class="text-xs text-gray-400">#{{ subscription.id }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-900">
                                    {{ formatAmount(subscription.amount, subscription.currency) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="subscriptionStatusBadgeClass(subscription.status)"
                                    >
                                        {{ subscriptionStatusLabel(subscription.status) }}
                                    </span>
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 md:table-cell">
                                    {{ formatPeriodEndUtc(subscription.current_period_end) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ formatDateTime(subscription.starts_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="subscription.show_url ?? `/subscriptions/${subscription.id}`"
                                        class="text-sm font-medium text-gray-900 underline-offset-2 hover:underline"
                                    >
                                        Voir l'abonnement
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="subscriptions.links && subscriptions.links.length > 3"
                    class="mt-4 flex flex-wrap justify-center gap-1"
                >
                    <button
                        v-for="(link, index) in subscriptions.links"
                        :key="`sub-${index}`"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm disabled:text-gray-400"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="link.label"
                    />
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Paiements
                    </h2>
                    <Link
                        v-if="navigation.payments_index"
                        :href="navigation.payments_index"
                        class="text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                    >
                        Voir les paiements
                    </Link>
                </div>
                <div
                    v-if="!payments.data.length"
                    class="mt-4 text-sm text-gray-500"
                >
                    Aucun paiement enregistré pour ce client.
                </div>
                <div
                    v-else
                    class="mt-6 overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Installation
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Montant
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Statut
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Abonnement
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Payé le
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Réf.
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="payment in payments.data"
                                :key="payment.id"
                            >
                                <td class="px-4 py-3 text-gray-900">
                                    {{ displayValue(payment.installation_name) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-900">
                                    {{ formatAmount(payment.amount, payment.currency) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="paymentStatusBadgeClass(payment.status)"
                                    >
                                        {{ paymentStatusLabel(payment.status) }}
                                    </span>
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 md:table-cell">
                                    <Link
                                        v-if="payment.subscription_show_url"
                                        :href="payment.subscription_show_url"
                                        class="font-medium text-gray-900 hover:underline"
                                    >
                                        #{{ payment.subscription_id }}
                                    </Link>
                                    <span v-else>#{{ payment.subscription_id }}</span>
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ formatDateTime(payment.paid_at) }}
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ displayValue(payment.reference ?? `#${payment.id}`) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="payments.links && payments.links.length > 3"
                    class="mt-4 flex flex-wrap justify-center gap-1"
                >
                    <button
                        v-for="(link, index) in payments.links"
                        :key="`pay-${index}`"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm disabled:text-gray-400"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="link.label"
                    />
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Rappels d'abonnement
                </h2>
                <p class="mt-1 text-xs text-gray-500">
                    Lecture seule — aucun envoi ni retraitement depuis cette page.
                </p>
                <div
                    v-if="!reminders.data.length"
                    class="mt-4 text-sm text-gray-500"
                >
                    Aucun rappel enregistré pour ce client.
                </div>
                <div
                    v-else
                    class="mt-6 overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Installation
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Seuil
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Statut
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Planifié (UTC)
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Détecté
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Envoyé
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="reminder in reminders.data"
                                :key="reminder.id"
                            >
                                <td class="px-4 py-3 text-gray-900">
                                    <Link
                                        v-if="reminder.installation_show_url"
                                        :href="reminder.installation_show_url"
                                        class="hover:underline"
                                    >
                                        {{ displayValue(reminder.installation_name) }}
                                    </Link>
                                    <span v-else>{{ displayValue(reminder.installation_name) }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-900">
                                    {{ thresholdLabel(reminder.threshold_days) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="reminderStatusBadgeClass(reminder.status)"
                                    >
                                        {{ reminderStatusLabel(reminder.status) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-600">
                                    {{ formatPeriodEndUtc(reminder.scheduled_for) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 md:table-cell">
                                    {{ formatDateTime(reminder.detected_at) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 md:table-cell">
                                    {{ formatDateTime(reminder.sent_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        v-if="reminder.subscription_show_url"
                                        :href="reminder.subscription_show_url"
                                        class="text-sm font-medium text-gray-900 underline-offset-2 hover:underline"
                                    >
                                        Voir l'abonnement
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="reminders.links && reminders.links.length > 3"
                    class="mt-4 flex flex-wrap justify-center gap-1"
                >
                    <button
                        v-for="(link, index) in reminders.links"
                        :key="`rem-${index}`"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm disabled:text-gray-400"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="link.label"
                    />
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
