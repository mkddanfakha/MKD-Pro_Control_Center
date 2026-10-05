<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { reminderStatusBadgeClass, reminderStatusLabel } from '@/lib/adminPresentation.js';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    reminder: {
        type: Object,
        required: true,
    },
    notification: {
        type: Object,
        default: null,
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
    payments: {
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

const statusLabel = reminderStatusLabel;
const statusBadgeClass = reminderStatusBadgeClass;

function thresholdLabel(days) {
    if (days === 0) {
        return 'J0';
    }

    return `J-${days}`;
}

function reminderTypeLabel(type) {
    if (type === 'subscription_expiry') {
        return 'Échéance d’abonnement';
    }

    return type;
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

function visitPaymentsPagination(url) {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function openAuditDetail(entry) {
    selectedAudit.value = entry;
}

function closeAuditDetail() {
    selectedAudit.value = null;
}
</script>

<template>
    <Head :title="`Rappel #${reminder.id}`" />

    <AdminLayout>
        <div class="mb-6">
            <Link
                :href="navigation.reminders_index"
                class="text-sm font-medium text-gray-600 underline-offset-2 hover:text-gray-900 hover:underline"
            >
                ← Retour aux rappels
            </Link>
        </div>

        <header class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Rappel #{{ reminder.id }}
                    </h1>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ reminderTypeLabel(reminder.reminder_type) }} — consultation administrative (lecture seule)
                    </p>
                </div>
                <span
                    class="inline-flex rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset"
                    :class="statusBadgeClass(reminder.status)"
                >
                    {{ statusLabel(reminder.status) }}
                </span>
            </div>
        </header>

        <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
            <h2 class="text-lg font-semibold text-gray-900">
                État du rappel
            </h2>
            <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Seuil
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ thresholdLabel(reminder.threshold_days) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Date programmée
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(reminder.scheduled_for) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Détecté le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(reminder.detected_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Envoyé le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(reminder.sent_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Créé le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(reminder.created_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Mis à jour le
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(reminder.updated_at) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
            <h2 class="text-lg font-semibold text-gray-900">
                Notification
            </h2>
            <p
                v-if="!notification"
                class="mt-4 text-sm text-gray-500"
            >
                Aperçu indisponible (données insuffisantes pour composer la notification).
            </p>
            <dl
                v-else
                class="mt-6 grid gap-4 sm:grid-cols-2"
            >
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Destinataire
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ notification.recipient_label }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Canal
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        E-mail
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Titre
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ notification.title }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Montant
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatAmount(notification.amount, notification.currency) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Échéance abonnement
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ formatDateTimeUtc(notification.current_period_end) }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Installation concernée
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ displayValue(notification.installation_name) }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Contenu
                    </dt>
                    <dd class="mt-1 whitespace-pre-wrap rounded-lg bg-gray-50 p-4 text-sm text-gray-800 ring-1 ring-gray-200">
                        {{ notification.body }}
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
                        Montant
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
                Paiements de l’abonnement
            </h2>
            <p
                v-if="!payments || !payments.data.length"
                class="mt-4 text-sm text-gray-500"
            >
                Aucun paiement enregistré pour cet abonnement.
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
                                Montant
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                Statut
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                Payé le
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                Crédit (mois)
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-700">
                                Fiche
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr
                            v-for="payment in payments.data"
                            :key="payment.id"
                        >
                            <td class="px-4 py-3 font-mono text-xs text-gray-600">
                                {{ payment.id }}
                            </td>
                            <td class="px-4 py-3 text-gray-900">
                                {{ formatAmount(payment.amount, payment.currency) }}
                            </td>
                            <td class="px-4 py-3 text-gray-700">
                                {{ payment.status }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                {{ formatDateTimeUtc(payment.paid_at) }}
                            </td>
                            <td class="px-4 py-3 text-gray-700">
                                {{ payment.credit_months_purchased ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    v-if="payment.show_url"
                                    :href="payment.show_url"
                                    class="font-medium text-gray-900 underline-offset-2 hover:underline"
                                >
                                    Ouvrir la fiche
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                v-if="payments && payments.last_page > 1"
                class="mt-4 flex flex-wrap justify-center gap-1"
            >
                <button
                    v-for="(link, index) in payments.links"
                    :key="index"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm"
                    :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                    :disabled="!link.url"
                    @click="visitPaymentsPagination(link.url)"
                    v-html="link.label"
                />
            </nav>
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
                Aucun événement d’audit associé à ce rappel.
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
