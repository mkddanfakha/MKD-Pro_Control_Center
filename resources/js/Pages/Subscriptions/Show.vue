<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    subscription: {
        type: Object,
        required: true,
    },
    credit: {
        type: Object,
        required: true,
    },
});

const page = usePage();

const showConsumeConfirm = ref(false);
const consuming = ref(false);

const installationSubtitle = computed(() => {
    const installation = props.subscription.installation;

    if (!installation) {
        return '—';
    }

    const name = installation.name?.trim() || '—';
    const subdomain = installation.subdomain?.trim() || '—';

    return `${name} — ${subdomain}`;
});

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function formatDateTime(value) {
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

function formatDate(value) {
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

function formatAmount(amount, currency) {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount));

    return `${formatted} ${currency ?? 'XOF'}`;
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
        return `${value} mois`;
    }

    return `${value} mois`;
}

function remainingMonthsLabel(remaining) {
    if (remaining === null || remaining === undefined || remaining === '') {
        return '—';
    }

    const value = Number(remaining);

    if (Number.isNaN(value)) {
        return '—';
    }

    if (value === 0) {
        return '0 mois restant';
    }

    if (value === 1) {
        return '1 mois restant';
    }

    return `${value} mois restants`;
}

function consumedMonthsLabel(count) {
    if (count === null || count === undefined || count === '') {
        return '—';
    }

    const value = Number(count);

    if (Number.isNaN(value)) {
        return '—';
    }

    if (value === 0) {
        return 'Aucun mois consommé';
    }

    if (value === 1) {
        return '1 mois consommé';
    }

    return `${value} mois consommés`;
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

function installationStatusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
        suspended: 'Suspendue',
        terminated: 'Terminée',
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

const hasNotes = computed(() => {
    const notes = props.subscription.notes;

    return notes && String(notes).trim() !== '';
});

function consumeCreditUrl() {
    return `/subscriptions/${props.subscription.id}/consume-credit`;
}

function confirmConsumeNextCredit() {
    if (consuming.value) {
        return;
    }

    consuming.value = true;

    router.post(consumeCreditUrl(), {}, {
        preserveScroll: true,
        onFinish: () => {
            consuming.value = false;
            showConsumeConfirm.value = false;
        },
    });
}
</script>

<template>
    <Head title="Abonnement" />

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
                v-if="showConsumeConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="consume-credit-title"
                @click.self="showConsumeConfirm = false"
            >
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2
                        id="consume-credit-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Consommer le prochain crédit
                    </h2>

                    <p class="mt-3 text-sm text-gray-600">
                        Consommer 1 mois de crédit pour cet abonnement ?
                    </p>

                    <p class="mt-2 text-sm text-gray-500">
                        Le prochain mois disponible sera débité selon l'ordre FIFO des paiements. L'abonnement sera avancé d'une période.
                    </p>

                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="consuming"
                            @click="showConsumeConfirm = false"
                        >
                            Annuler
                        </button>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="consuming"
                            :aria-busy="consuming"
                            @click="confirmConsumeNextCredit"
                        >
                            {{ consuming ? 'Consommation…' : 'Confirmer la consommation' }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Abonnement
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ installationSubtitle }}
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        href="/subscriptions"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <Link
                        :href="`/subscriptions/${subscription.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier
                    </Link>
                </div>
            </div>

            <div class="mt-8 space-y-8">
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Abonnement
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Montant
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ formatAmount(subscription.amount, subscription.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Devise
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(subscription.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="subscriptionStatusBadgeClass(subscription.status)"
                                >
                                    {{ subscriptionStatusLabel(subscription.status) }}
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de début
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(subscription.starts_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Période actuelle
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Début de la période
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(subscription.current_period_start) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Fin de la période
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(subscription.current_period_end) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Fin de période de grâce
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(subscription.grace_period_ends_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Crédit disponible
                    </h2>

                    <div class="mt-6 space-y-6">
                        <div>
                            <p
                                v-if="credit.available_months === 0"
                                class="text-sm font-medium text-gray-900"
                            >
                                Aucun crédit disponible
                            </p>
                            <p
                                v-else
                                class="text-2xl font-semibold tabular-nums text-gray-900"
                            >
                                {{ formatMonthsCount(credit.available_months) }}
                            </p>

                            <p class="mt-2 text-sm text-gray-500">
                                Paiements :
                                <span class="font-medium text-gray-900">{{ credit.payment_count }}</span>
                            </p>

                            <div
                                v-if="credit.available_months > 0"
                                class="mt-4"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="consuming"
                                    :aria-busy="consuming"
                                    @click="showConsumeConfirm = true"
                                >
                                    Consommer le prochain crédit
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="credit.payments?.length"
                            class="space-y-4"
                        >
                            <h3 class="text-sm font-medium text-gray-500">
                                Historique des paiements et crédit
                            </h3>

                            <ul class="space-y-4">
                                <li
                                    v-for="payment in credit.payments"
                                    :key="payment.id"
                                    class="rounded-lg border border-gray-200 bg-gray-50/80 p-4 sm:p-5"
                                >
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-gray-900">
                                            Paiement #{{ payment.id }}
                                        </p>
                                        <span
                                            v-if="payment.is_refunded"
                                            class="inline-flex rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-300"
                                        >
                                            Remboursé
                                        </span>
                                    </div>

                                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                                        <div class="sm:col-span-2">
                                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                Montant
                                            </dt>
                                            <dd class="mt-0.5 text-sm font-medium text-gray-900">
                                                {{ formatAmount(payment.amount, payment.currency) }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                Mois achetés
                                            </dt>
                                            <dd class="mt-0.5 text-sm text-gray-900">
                                                {{ formatMonthsCount(payment.credit_months_purchased) }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                Mois restants
                                            </dt>
                                            <dd
                                                class="mt-0.5 text-sm text-gray-900"
                                                :class="payment.is_refunded ? 'text-gray-500' : ''"
                                            >
                                                <template v-if="payment.is_refunded">
                                                    —
                                                </template>
                                                <template v-else>
                                                    {{ remainingMonthsLabel(payment.credit_months_remaining) }}
                                                </template>
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                Mensualité de référence
                                            </dt>
                                            <dd class="mt-0.5 text-sm text-gray-900">
                                                {{ formatAmount(payment.monthly_unit_amount, payment.currency) }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                Payé le
                                            </dt>
                                            <dd class="mt-0.5 text-sm text-gray-900">
                                                {{ formatDate(payment.paid_at) }}
                                            </dd>
                                        </div>

                                        <div class="sm:col-span-2">
                                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                Consommation
                                            </dt>
                                            <dd class="mt-0.5 text-sm text-gray-900">
                                                {{ consumedMonthsLabel(payment.consumptions_count) }}
                                            </dd>
                                        </div>
                                    </dl>
                                </li>
                            </ul>
                        </div>

                        <p
                            v-else
                            class="text-sm text-gray-500"
                        >
                            Aucun paiement enregistré pour cet abonnement.
                        </p>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Installation
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Entreprise
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(subscription.installation?.client?.company_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Contact
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(subscription.installation?.client?.contact_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Nom
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(subscription.installation?.name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Sous-domaine
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(subscription.installation?.subdomain) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Domaine
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all">
                                {{ displayValue(subscription.installation?.domain) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Version
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(subscription.installation?.version) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    v-if="subscription.installation?.status"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="installationStatusBadgeClass(subscription.installation.status)"
                                >
                                    {{ installationStatusLabel(subscription.installation.status) }}
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
                        Notes
                    </h2>

                    <p
                        v-if="hasNotes"
                        class="mt-6 whitespace-pre-line text-sm text-gray-900"
                    >
                        {{ subscription.notes }}
                    </p>

                    <p
                        v-else
                        class="mt-6 text-sm text-gray-500"
                    >
                        Aucune note.
                    </p>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Dates système
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div v-if="subscription.suspended_at">
                            <dt class="text-sm font-medium text-gray-500">
                                Suspendu le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(subscription.suspended_at) }}
                            </dd>
                        </div>

                        <div v-if="subscription.terminated_at">
                            <dt class="text-sm font-medium text-gray-500">
                                Terminé le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(subscription.terminated_at) }}
                            </dd>
                        </div>

                        <div v-if="subscription.created_at">
                            <dt class="text-sm font-medium text-gray-500">
                                Créé le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(subscription.created_at) }}
                            </dd>
                        </div>

                        <div v-if="subscription.updated_at">
                            <dt class="text-sm font-medium text-gray-500">
                                Modifié le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(subscription.updated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link
                        href="/subscriptions"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour aux abonnements
                    </Link>

                    <Link
                        :href="`/subscriptions/${subscription.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier l'abonnement
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
