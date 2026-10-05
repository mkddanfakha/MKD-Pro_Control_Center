<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    admin_urls: {
        type: Object,
        default: () => ({}),
    },
    payment: {
        type: Object,
        required: true,
    },
    credit: {
        type: Object,
        required: true,
    },
    consumptions: {
        type: Array,
        required: true,
    },
    subscription: {
        type: Object,
        default: null,
    },
    installation: {
        type: Object,
        default: null,
    },
    client: {
        type: Object,
        default: null,
    },
    audit_history: {
        type: Array,
        required: true,
    },
    navigation: {
        type: Object,
        required: true,
    },
});

const selectedAudit = ref(null);
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

    router.delete(`/payments/${props.payment.id}`, {
        onFinish: () => {
            deleting.value = false;
            showDeleteConfirm.value = false;
        },
    });
}

function displayValue(value) {
    if (value === null || value === undefined || String(value).trim() === '') {
        return 'Non renseigné';
    }

    return value;
}

function formatDateTimeUtc(value) {
    if (!value) {
        return 'Non renseigné';
    }

    const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return 'Non renseigné';
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

function formatAmount(amount, currency) {
    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount ?? 0));

    return `${formatted} ${currency ?? 'XOF'}`;
}

function paymentStatusLabel(status) {
    const labels = {
        pending: 'En attente',
        paid: 'Payé',
        failed: 'Échec',
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

function subscriptionStatusLabel(status) {
    const labels = {
        active: 'Actif',
        grace_period: 'Période de grâce',
        suspended: 'Suspendu',
        terminated: 'Terminé',
    };

    return labels[status] ?? status;
}

function formatMonthsCount(count) {
    if (count === null || count === undefined || count === '') {
        return 'Non renseigné';
    }

    const value = Number(count);

    if (Number.isNaN(value)) {
        return 'Non renseigné';
    }

    return value <= 1 ? '1 mois' : `${value} mois`;
}

function openAuditDetail(entry) {
    selectedAudit.value = entry;
}

function closeAuditDetail() {
    selectedAudit.value = null;
}

function formatJsonBlock(value) {
    if (value === null || value === undefined) {
        return 'Aucune valeur';
    }

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return 'Aucune valeur';
    }
}
</script>

<template>
    <Head :title="`Paiement #${payment.id}`" />

    <AdminLayout>
        <div
            v-if="selectedAudit"
            class="fixed inset-0 z-50 overflow-y-auto bg-black/40"
            @click.self="closeAuditDetail"
        >
            <div class="flex min-h-full items-center justify-center p-4">
                <div
                    class="flex w-full max-w-3xl max-h-[calc(100vh-2rem)] flex-col overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200"
                    @click.stop
                >
                    <div class="flex shrink-0 items-center justify-between border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">
                            Audit #{{ selectedAudit.id }}
                        </h2>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
                            @click="closeAuditDetail"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4 text-sm">
                        <p class="font-medium text-gray-900">
                            {{ selectedAudit.action }}
                        </p>
                        <p class="mt-1 text-gray-500">
                            {{ formatDateTimeUtc(selectedAudit.created_at) }}
                        </p>
                        <p class="mt-4 text-gray-700">
                            {{ selectedAudit.context_summary }}
                        </p>
                        <pre class="mt-4 max-h-48 overflow-auto rounded-lg bg-gray-50 p-3 text-xs ring-1 ring-gray-200">{{ formatJsonBlock(selectedAudit.detail?.new_values) }}</pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-6">
            <Link
                :href="navigation.payments_index"
                class="text-sm font-medium text-gray-600 underline-offset-2 hover:text-gray-900 hover:underline"
            >
                ← Retour aux paiements
            </Link>
        </div>

        <header class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Paiement #{{ payment.id }}
                    </h1>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="inline-flex rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset"
                        :class="paymentStatusBadgeClass(payment.status)"
                    >
                        {{ paymentStatusLabel(payment.status) }}
                    </span>
                    <Link
                        v-if="navigation.edit"
                        :href="navigation.edit"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                    >
                        Modifier
                    </Link>
                    <button
                        v-if="admin_urls.can_delete !== false"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-700 shadow-sm hover:bg-red-50 disabled:opacity-60"
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
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Supprimer le paiement
                    </h2>
                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer ce paiement ?
                    </p>
                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700"
                            :disabled="deleting"
                            @click="cancelDelete"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-60"
                            :disabled="deleting"
                            @click="confirmDelete"
                        >
                            {{ deleting ? 'Suppression…' : 'Supprimer' }}
                        </button>
                    </div>
                </div>
            </div>
            <p class="mt-4 text-3xl font-semibold text-gray-900">
                {{ formatAmount(payment.amount, payment.currency) }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
                Montant réellement payé (Payment.amount)
            </p>
        </header>

        <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
            <h2 class="text-lg font-semibold text-gray-900">
                Informations générales
            </h2>
            <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Devise
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(payment.currency) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Échéance
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(payment.due_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Payé le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(payment.paid_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Mode de paiement
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(payment.payment_method) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Référence
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(payment.reference) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Créé le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(payment.created_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Mis à jour le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(payment.updated_at) }}
                    </dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Notes
                    </dt>
                    <dd class="mt-1 whitespace-pre-wrap text-sm text-gray-900">
                        {{ displayValue(payment.notes) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="credit.show_credit_details"
            class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8"
        >
            <h2 class="text-lg font-semibold text-gray-900">
                Crédit associé au paiement
            </h2>
            <p class="mt-1 text-xs text-gray-500">
                Le montant payé finance des mois de crédit au tarif mensuel de référence. Aucune consommation n’est déclenchée depuis cette fiche.
            </p>
            <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Montant payé
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">
                        {{ formatAmount(credit.amount, credit.currency) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Tarif mensuel de référence
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ credit.monthly_unit_amount != null ? formatAmount(credit.monthly_unit_amount, credit.currency) : 'Non renseigné' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Mois de crédit achetés
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatMonthsCount(credit.credit_months_purchased) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Mois consommés
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ credit.consumptions_count ?? 0 }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Crédit restant (calculé)
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        <template v-if="credit.is_refunded">
                            —
                            <span class="block text-xs text-gray-500">
                                Non consommable (remboursé)
                            </span>
                        </template>
                        <template v-else>
                            {{ formatMonthsCount(credit.credit_months_remaining) }}
                        </template>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Crédit épuisé le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(credit.credit_exhausted_at) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
            <h2 class="text-lg font-semibold text-gray-900">
                Période associée
            </h2>
            <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Début de période
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(payment.period_start) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Fin de période
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(payment.period_end) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Renouvellement appliqué le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(payment.renewal_applied_at) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="subscription"
            class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">
                    Abonnement lié
                </h2>
                <Link
                    v-if="navigation.subscription_show"
                    :href="navigation.subscription_show"
                    class="text-sm font-medium text-gray-900 underline-offset-2 hover:underline"
                >
                    Voir l’abonnement
                </Link>
            </div>
            <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        ID
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        #{{ subscription.id }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Statut
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ subscriptionStatusLabel(subscription.status) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Montant abonnement
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatAmount(subscription.amount, subscription.currency) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Début
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(subscription.starts_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Période courante
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(subscription.current_period_start) }}
                        →
                        {{ formatDateTimeUtc(subscription.current_period_end) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Fin de grâce
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(subscription.grace_period_ends_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Suspendu le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(subscription.suspended_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Terminé le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(subscription.terminated_at) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="installation"
            class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">
                    Installation liée
                </h2>
                <Link
                    v-if="navigation.installation_show"
                    :href="navigation.installation_show"
                    class="text-sm font-medium text-gray-900 underline-offset-2 hover:underline"
                >
                    Voir l’installation
                </Link>
            </div>
            <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        ID
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        #{{ installation.id }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Nom
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(installation.name) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Sous-domaine
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(installation.subdomain) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Domaine
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(installation.domain) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Statut
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(installation.status) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Version
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(installation.version) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="client"
            class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">
                    Client lié
                </h2>
                <Link
                    v-if="navigation.client_show"
                    :href="navigation.client_show"
                    class="text-sm font-medium text-gray-900 underline-offset-2 hover:underline"
                >
                    Voir le client
                </Link>
            </div>
            <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        ID
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        #{{ client.id }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Raison sociale
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(client.company_name) }}
                    </dd>
                </div>
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
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Statut
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(client.status) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
            <h2 class="text-lg font-semibold text-gray-900">
                Consommations de crédit
            </h2>
            <p
                v-if="!consumptions.length"
                class="mt-4 text-sm text-gray-500"
            >
                Aucune consommation enregistrée pour ce paiement.
            </p>
            <div
                v-else
                class="mt-6 overflow-x-auto"
            >
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                ID
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                Abonnement
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                Période
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                Consommé le
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                Créé le
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr
                            v-for="row in consumptions"
                            :key="row.id"
                        >
                            <td class="px-4 py-3 font-mono text-xs text-gray-600">
                                {{ row.id }}
                            </td>
                            <td class="px-4 py-3 text-gray-900">
                                #{{ row.subscription_id }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                {{ formatDateTimeUtc(row.period_start) }}
                                →
                                {{ formatDateTimeUtc(row.period_end) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                {{ formatDateTimeUtc(row.consumed_at) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                {{ formatDateTimeUtc(row.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">
                    Historique d’audit
                </h2>
                <Link
                    v-if="navigation.audit_logs_index"
                    :href="navigation.audit_logs_index"
                    class="text-sm font-medium text-gray-900 underline-offset-2 hover:underline"
                >
                    Voir le journal d’audit
                </Link>
            </div>
            <p
                v-if="!audit_history.length"
                class="mt-4 text-sm text-gray-500"
            >
                Aucun événement d’audit enregistré pour ce paiement.
            </p>
            <ul
                v-else
                class="mt-6 divide-y divide-gray-100 text-sm"
            >
                <li
                    v-for="entry in audit_history"
                    :key="entry.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3"
                >
                    <div>
                        <span class="font-medium text-gray-900">{{ entry.action }}</span>
                        <span class="ml-2 text-gray-500">{{ formatDateTimeUtc(entry.created_at) }}</span>
                    </div>
                    <button
                        type="button"
                        class="text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                        @click="openAuditDetail(entry)"
                    >
                        Détail
                    </button>
                </li>
            </ul>
        </section>
    </AdminLayout>
</template>
