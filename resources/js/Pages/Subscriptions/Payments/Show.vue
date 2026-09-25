<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    payment: {
        type: Object,
        required: true,
    },
    canRenewSubscription: {
        type: Boolean,
        default: false,
    },
    renewalPreview: {
        type: Object,
        default: null,
    },
    paymentCredit: {
        type: Object,
        required: true,
    },
});

const page = usePage();

const showRenewConfirm = ref(false);
const renewing = ref(false);

const installationSubtitle = computed(() => {
    const installation = props.payment.subscription?.installation;

    if (!installation) {
        return '—';
    }

    const name = installation.name?.trim() || '—';
    const subdomain = installation.subdomain?.trim() || '—';

    return `${name} — ${subdomain}`;
});

const hasNotes = computed(() => {
    const notes = props.payment.notes;

    return notes && String(notes).trim() !== '';
});

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
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

function formatAmount(amount, currency) {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount));

    return `${formatted} ${currency ?? 'XOF'}`;
}

function statusLabel(status) {
    const labels = {
        pending: 'En attente',
        paid: 'Payé',
        failed: 'Échec',
        refunded: 'Remboursé',
    };

    return labels[status] ?? status;
}

function statusClass(status) {
    const classes = {
        pending: 'bg-amber-50 text-amber-900 ring-amber-200',
        paid: 'bg-sky-50 text-sky-800 ring-sky-200',
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

function subscriptionStatusClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        grace_period: 'bg-amber-50 text-amber-900 ring-amber-200',
        suspended: 'bg-orange-50 text-orange-900 ring-orange-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function installationStatusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
        suspended: 'Suspendue',
        terminated: 'Terminée',
    };

    return labels[status] ?? status;
}

function installationStatusClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        inactive: 'bg-gray-100 text-gray-600 ring-gray-200',
        suspended: 'bg-amber-50 text-amber-900 ring-amber-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function confirmRenewSubscription() {
    if (renewing.value) {
        return;
    }

    renewing.value = true;

    router.post(`/payments/${props.payment.id}/renew-subscription`, {}, {
        preserveScroll: true,
        onFinish: () => {
            renewing.value = false;
            showRenewConfirm.value = false;
        },
    });
}

function formatMonthsCount(count) {
    if (count === null || count === undefined || count === '') {
        return '—';
    }

    const value = Number(count);

    if (Number.isNaN(value)) {
        return '—';
    }

    if (value <= 1) {
        return '1 mois';
    }

    return `${value} mois`;
}

function consumedMonthsLabel(count) {
    const value = Number(count);

    if (Number.isNaN(value) || value === 0) {
        return '0 mois';
    }

    if (value === 1) {
        return '1 mois consommé';
    }

    return `${value} mois consommés`;
}

function remainingMonthsLabel(count) {
    const value = Number(count);

    if (Number.isNaN(value)) {
        return '—';
    }

    if (value === 0) {
        return '0 mois restants';
    }

    if (value === 1) {
        return '1 mois restant';
    }

    return `${value} mois restants`;
}

function formatPeriodMonthLabel(value) {
    if (!value || String(value).trim() === '') {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const formatted = new Intl.DateTimeFormat('fr-FR', {
        month: 'long',
        year: 'numeric',
    }).format(date);

    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
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

function consumptionPeriodLabel(consumption) {
    const startLabel = formatPeriodMonthLabel(consumption.period_start);

    if (startLabel === '—') {
        return '—';
    }

    return startLabel;
}

function paymentMethodLabel(method) {
    const labels = {
        wave: 'Wave',
        cash: 'Espèces',
        bank_transfer: 'Virement bancaire',
        other: 'Autre',
    };

    if (!method || String(method).trim() === '') {
        return '—';
    }

    return labels[method] ?? method;
}
</script>

<template>
    <Head title="Paiement" />

    <AdminLayout>
        <div>
            <div
                v-if="page.flash.success"
                class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800"
                role="status"
            >
                {{ page.flash.success }}
            </div>

            <div
                v-if="page.flash.error"
                class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"
                role="alert"
            >
                {{ page.flash.error }}
            </div>

            <div
                v-if="showRenewConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="consume-payment-credit-title"
                @click.self="showRenewConfirm = false"
            >
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2
                        id="consume-payment-credit-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Consommer 1 mois de crédit
                    </h2>

                    <p class="mt-3 text-sm text-gray-600">
                        Confirmer la consommation d’un mois de crédit sur ce paiement ?
                    </p>

                    <p class="mt-2 text-sm text-gray-500">
                        Cette action utilisera 1 mois du crédit restant de ce paiement et financera la prochaine période de l’abonnement. Le crédit d’un autre paiement ne sera pas utilisé.
                    </p>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="font-medium text-gray-500">
                                Installation
                            </dt>
                            <dd class="mt-0.5 text-gray-900">
                                {{ installationSubtitle }}
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-gray-500">
                                Montant du paiement
                            </dt>
                            <dd class="mt-0.5 text-gray-900">
                                {{ formatAmount(payment.amount, payment.currency) }}
                            </dd>
                        </div>

                        <div v-if="renewalPreview">
                            <dt class="font-medium text-gray-500">
                                Période couverte par le paiement
                            </dt>
                            <dd class="mt-0.5 text-gray-900">
                                {{ formatDate(renewalPreview.current_period_start) }}
                                →
                                {{ formatDate(renewalPreview.current_period_end) }}
                            </dd>
                        </div>

                        <div v-if="renewalPreview">
                            <dt class="font-medium text-gray-500">
                                Nouvelle période prévue
                            </dt>
                            <dd class="mt-0.5 font-medium text-gray-900">
                                {{ formatDate(renewalPreview.next_period_start) }}
                                →
                                {{ formatDate(renewalPreview.next_period_end) }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="renewing"
                            @click="showRenewConfirm = false"
                        >
                            Annuler
                        </button>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="renewing"
                            :aria-busy="renewing"
                            @click="confirmRenewSubscription"
                        >
                            {{ renewing ? 'Consommation…' : 'Confirmer la consommation' }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Paiement
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ installationSubtitle }}
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        href="/payments"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <Link
                        :href="`/payments/${payment.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier
                    </Link>
                </div>
            </div>

            <div class="mt-8 space-y-8">
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Paiement
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Montant
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ formatAmount(payment.amount, payment.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Devise
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(payment.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="statusClass(payment.status)"
                                >
                                    {{ statusLabel(payment.status) }}
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Mode de paiement
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ paymentMethodLabel(payment.payment_method) }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Référence
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all">
                                {{ displayValue(payment.reference) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Période et dates
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date d'échéance
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDate(payment.due_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de paiement
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDate(payment.paid_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Début de période
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDate(payment.period_start) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Fin de période
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDate(payment.period_end) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    v-if="paymentCredit.show_credit_details"
                    class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8"
                >
                    <h2 class="text-lg font-semibold text-gray-900">
                        Crédit de ce paiement
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Mois de crédit achetés, consommés et restants pour ce paiement (tarif enregistré au moment du paiement).
                    </p>

                    <div
                        v-if="paymentCredit.is_refunded"
                        class="mt-4 rounded-lg border border-gray-300 bg-gray-100 px-4 py-3 text-sm font-medium text-gray-800"
                        role="status"
                    >
                        Paiement remboursé — l’historique est conservé, mais ce paiement n’est plus une source de crédit disponible.
                    </div>

                    <div
                        v-else-if="!paymentCredit.is_paid"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                        role="status"
                    >
                        Crédit non disponible tant que le paiement n’est pas au statut payé.
                    </div>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Montant
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ formatAmount(paymentCredit.amount, paymentCredit.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Tarif mensuel de référence
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <template v-if="paymentCredit.monthly_unit_amount !== null">
                                    {{ formatAmount(paymentCredit.monthly_unit_amount, paymentCredit.currency) }}/mois
                                </template>
                                <template v-else>
                                    —
                                </template>
                            </dd>
                            <dd class="mt-1 text-xs text-gray-500">
                                Tarif mensuel enregistré pour ce paiement.
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Crédit acheté
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ formatMonthsCount(paymentCredit.credit_months_purchased) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Crédit consommé
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ consumedMonthsLabel(paymentCredit.consumptions_count) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Crédit restant
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                <template v-if="paymentCredit.presents_consumable_credit">
                                    {{ remainingMonthsLabel(paymentCredit.credit_months_remaining) }}
                                </template>
                                <template v-else-if="paymentCredit.is_refunded">
                                    {{ remainingMonthsLabel(paymentCredit.credit_months_remaining) }}
                                    <span class="mt-1 block text-xs font-normal text-gray-500">
                                        Non consommable (remboursé).
                                    </span>
                                </template>
                                <template v-else-if="!paymentCredit.is_paid">
                                    —
                                </template>
                                <template v-else>
                                    {{ remainingMonthsLabel(paymentCredit.credit_months_remaining) }}
                                </template>
                            </dd>
                        </div>

                        <div
                            v-if="paymentCredit.is_exhausted"
                            class="sm:col-span-2"
                        >
                            <dt class="text-sm font-medium text-gray-500">
                                État du crédit
                            </dt>
                            <dd class="mt-1">
                                <span class="inline-flex rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-300">
                                    Crédit épuisé
                                </span>
                                <span
                                    v-if="paymentCredit.credit_exhausted_at"
                                    class="mt-2 block text-sm text-gray-600"
                                >
                                    Épuisé le {{ formatDate(paymentCredit.credit_exhausted_at) }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-8">
                        <h3 class="text-sm font-semibold text-gray-900">
                            Crédit consommé
                        </h3>

                        <ul
                            v-if="paymentCredit.consumptions?.length"
                            class="mt-4 space-y-3"
                        >
                            <li
                                v-for="consumption in paymentCredit.consumptions"
                                :key="consumption.id"
                                class="rounded-lg border border-gray-200 bg-gray-50/80 px-4 py-3 text-sm text-gray-900"
                            >
                                <span class="font-medium">
                                    {{ consumptionPeriodLabel(consumption) }}
                                </span>
                                <span class="text-gray-600">
                                    → consommé le {{ formatDateOnly(consumption.consumed_at) }}
                                </span>
                            </li>
                        </ul>

                        <p
                            v-else
                            class="mt-3 text-sm text-gray-500"
                        >
                            Aucune consommation enregistrée.
                        </p>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                            <h2 class="text-lg font-semibold text-gray-900">
                                Abonnement
                            </h2>

                            <Link
                                v-if="payment.subscription?.id"
                                :href="`/subscriptions/${payment.subscription.id}`"
                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                            >
                                Voir la fiche abonnement
                            </Link>
                        </div>

                        <button
                            v-if="canRenewSubscription"
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="renewing"
                            @click="showRenewConfirm = true"
                        >
                            Consommer 1 mois de crédit
                        </button>
                    </div>

                    <p
                        v-if="payment.renewal_applied_at"
                        class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900"
                    >
                        Ce paiement a déjà servi au renouvellement le
                        {{ formatDate(payment.renewal_applied_at) }}.
                    </p>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Montant mensuel (tarif en vigueur)
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ formatAmount(payment.subscription?.amount, payment.subscription?.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Devise
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(payment.subscription?.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut de l'abonnement
                            </dt>
                            <dd class="mt-1">
                                <span
                                    v-if="payment.subscription?.status"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="subscriptionStatusClass(payment.subscription.status)"
                                >
                                    {{ subscriptionStatusLabel(payment.subscription.status) }}
                                </span>
                                <span
                                    v-else
                                    class="text-sm text-gray-900"
                                >
                                    —
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Début de l'abonnement
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDate(payment.subscription?.starts_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Fin de période actuelle
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDate(payment.subscription?.current_period_end) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Installation
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Nom
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                <Link
                                    v-if="payment.subscription?.installation?.id"
                                    :href="`/installations/${payment.subscription.installation.id}`"
                                    class="underline-offset-2 hover:text-gray-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                >
                                    {{ displayValue(payment.subscription.installation.name) }}
                                </Link>
                                <template v-else>
                                    {{ displayValue(payment.subscription?.installation?.name) }}
                                </template>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Sous-domaine
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(payment.subscription?.installation?.subdomain) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Domaine
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all">
                                {{ displayValue(payment.subscription?.installation?.domain) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Version
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(payment.subscription?.installation?.version) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    v-if="payment.subscription?.installation?.status"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="installationStatusClass(payment.subscription.installation.status)"
                                >
                                    {{ installationStatusLabel(payment.subscription.installation.status) }}
                                </span>
                                <span
                                    v-else
                                    class="text-sm text-gray-900"
                                >
                                    —
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Client
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Entreprise
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                <Link
                                    v-if="payment.subscription?.installation?.client?.id"
                                    :href="`/clients/${payment.subscription.installation.client.id}`"
                                    class="underline-offset-2 hover:text-gray-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                >
                                    {{ displayValue(payment.subscription.installation.client.company_name) }}
                                </Link>
                                <template v-else>
                                    {{ displayValue(payment.subscription?.installation?.client?.company_name) }}
                                </template>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Contact
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(payment.subscription?.installation?.client?.contact_name) }}
                            </dd>
                        </div>

                        <div v-if="payment.subscription?.installation?.client?.phone">
                            <dt class="text-sm font-medium text-gray-500">
                                Téléphone
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ payment.subscription.installation.client.phone }}
                            </dd>
                        </div>

                        <div v-if="payment.subscription?.installation?.client?.email">
                            <dt class="text-sm font-medium text-gray-500">
                                Adresse e-mail
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all">
                                {{ payment.subscription.installation.client.email }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    v-if="hasNotes"
                    class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8"
                >
                    <h2 class="text-lg font-semibold text-gray-900">
                        Notes
                    </h2>

                    <p class="mt-6 whitespace-pre-line text-sm text-gray-900">
                        {{ payment.notes }}
                    </p>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Dates système
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Créé le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDate(payment.created_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Modifié le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDate(payment.updated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link
                        href="/payments"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour aux paiements
                    </Link>

                    <Link
                        :href="`/payments/${payment.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier le paiement
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
