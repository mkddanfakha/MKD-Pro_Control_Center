<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    installation: {
        type: Object,
        required: true,
    },
    access: {
        type: Object,
        required: true,
    },
    lastSubscription: {
        type: Object,
        default: null,
    },
    credit: {
        type: Object,
        default: null,
    },
    paymentsSummary: {
        type: Object,
        default: () => ({ count: 0, last_payment: null }),
    },
    modules: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
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

function subscriptionStatusLabel(status) {
    const labels = {
        active: 'Actif',
        grace_period: 'Période de grâce',
        suspended: 'Suspendu',
        terminated: 'Terminé',
    };

    return labels[status] ?? status;
}

function subscriptionStatusShortLabel(status) {
    const labels = {
        active: 'Active',
        grace_period: 'Période de grâce',
        suspended: 'Suspendue',
        terminated: 'Terminée',
    };

    return labels[status] ?? status;
}

function installationStatusShortLabel(status) {
    const labels = {
        active: 'Active',
        inactive: 'Inactive',
        suspended: 'Suspendue',
        terminated: 'Terminée',
    };

    return labels[status] ?? status;
}

function moduleAssignmentStatusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
    };

    return labels[status] ?? status;
}

function moduleAssignmentStatusBadgeClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        inactive: 'bg-gray-100 text-gray-600 ring-gray-200',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function subscriptionStatusBadgeClass(status) {
    const classes = {
        active: 'bg-violet-50 text-violet-900 ring-violet-200',
        grace_period: 'bg-violet-100 text-violet-900 ring-violet-300',
        suspended: 'bg-orange-50 text-orange-900 ring-orange-200',
        terminated: 'bg-stone-100 text-stone-700 ring-stone-300',
    };

    return classes[status] ?? 'bg-violet-50 text-violet-900 ring-violet-200';
}

function accessPrimaryLabel(access) {
    if (!access?.accessible) {
        return 'Accès non autorisé';
    }

    return 'Accès autorisé selon l’abonnement';
}

function accessDetailLabel(access) {
    if (!access) {
        return 'Aucun abonnement courant';
    }

    if (access.status === 'suspended') {
        return 'Abonnement suspendu';
    }

    if (access.status === 'no_subscription' || access.status === 'terminated') {
        return 'Aucun abonnement courant';
    }

    if (access.subscription_status === 'grace_period') {
        return 'Période de grâce';
    }

    if (access.subscription_status === 'active') {
        return 'Abonnement actif';
    }

    return null;
}

function accessBadgeClass(access) {
    if (access?.accessible) {
        return 'border-2 border-emerald-600 bg-white text-emerald-800';
    }

    return 'border-2 border-amber-600 bg-white text-amber-900';
}

function formatMoney(amount, currency) {
    if (amount == null || amount === '') {
        return '—';
    }

    const formatted = new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(Number(amount));

    return currency ? `${formatted} ${currency}` : formatted;
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

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

function formatPeriodRange(start, end) {
    const startLabel = formatDateOnly(start);
    const endLabel = formatDateOnly(end);

    if (startLabel === '—' && endLabel === '—') {
        return '—';
    }

    return `${startLabel} → ${endLabel}`;
}

function creditMonthsHeadline(months) {
    const value = Number(months);

    if (Number.isNaN(value) || value <= 0) {
        return null;
    }

    if (value === 1) {
        return '1 mois';
    }

    return `${value} mois`;
}

function paymentsCountLabel(count) {
    const value = Number(count);

    if (Number.isNaN(value) || value <= 0) {
        return null;
    }

    if (value === 1) {
        return '1 paiement';
    }

    return `${value} paiements`;
}
</script>

<template>
    <Head :title="installation.name ? `Installation — ${installation.name}` : 'Installation'" />

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
                        Installation
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ displayValue(installation.name) }}
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        href="/installations"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <Link
                        :href="`/installations/${installation.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier
                    </Link>
                </div>
            </div>

            <div class="mt-8 space-y-8">
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Installation
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        État administratif et informations techniques de l’installation.
                    </p>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Nom
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(installation.name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut de l’installation
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="installationStatusBadgeClass(installation.status)"
                                >
                                    {{ installationStatusLabel(installation.status) }}
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Client
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.client?.company_name) }}
                            </dd>
                            <dd
                                v-if="installation.client?.id"
                                class="mt-2"
                            >
                                <Link
                                    :href="`/clients/${installation.client.id}`"
                                    class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                >
                                    Voir le client
                                </Link>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Sous-domaine
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.subdomain) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Domaine
                            </dt>
                            <dd class="mt-1 text-sm break-all text-gray-900">
                                {{ displayValue(installation.domain) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Version
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.version) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-violet-100 sm:p-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                Abonnement actuel
                            </h2>

                            <p class="mt-1 text-sm text-gray-500">
                                Abonnement non terminé pris en compte pour l’accès et le crédit.
                            </p>
                        </div>

                        <div
                            v-if="lastSubscription?.id"
                            class="flex flex-col gap-2 sm:items-end"
                        >
                            <Link
                                :href="`/subscriptions/${lastSubscription.id}`"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-violet-200 bg-white px-4 py-2 text-sm font-medium text-violet-900 shadow-sm transition hover:bg-violet-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-700 focus-visible:ring-offset-2"
                            >
                                Voir l’abonnement
                            </Link>

                            <Link
                                :href="`/subscriptions/${lastSubscription.id}`"
                                class="text-sm font-medium text-violet-800 underline-offset-2 hover:text-violet-950 hover:underline"
                            >
                                Gérer le crédit
                            </Link>
                        </div>
                    </div>

                    <dl class="mt-6 grid gap-4 rounded-lg border border-gray-200 bg-gray-50/60 p-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Installation
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ installationStatusShortLabel(installation.status) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Abonnement
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                <template v-if="lastSubscription">
                                    {{ subscriptionStatusShortLabel(lastSubscription.status) }}
                                </template>
                                <template v-else>
                                    —
                                </template>
                            </dd>
                        </div>
                    </dl>

                    <template v-if="lastSubscription">
                        <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">
                                    Statut
                                </dt>
                                <dd class="mt-1">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="subscriptionStatusBadgeClass(lastSubscription.status)"
                                    >
                                        {{ subscriptionStatusLabel(lastSubscription.status) }}
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500">
                                    Montant mensuel
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ formatMoney(lastSubscription.amount, lastSubscription.currency) }}
                                </dd>
                            </div>

                            <div class="sm:col-span-2">
                                <dt class="text-sm font-medium text-gray-500">
                                    Période actuelle
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ formatPeriodRange(lastSubscription.current_period_start, lastSubscription.current_period_end) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500">
                                    Début de période
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ formatDateTime(lastSubscription.current_period_start) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500">
                                    Fin de période
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ formatDateTime(lastSubscription.current_period_end) }}
                                </dd>
                            </div>

                            <div v-if="lastSubscription.grace_period_ends_at">
                                <dt class="text-sm font-medium text-gray-500">
                                    Fin de grâce
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ formatDateTime(lastSubscription.grace_period_ends_at) }}
                                </dd>
                            </div>
                        </dl>
                    </template>

                    <p
                        v-else
                        class="mt-6 text-sm text-gray-700"
                    >
                        Aucun abonnement actif
                    </p>
                </section>

                <section class="rounded-xl border-2 border-dashed border-gray-200 bg-gray-50/50 p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Accès selon l’abonnement
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Résultat calculé côté serveur — indépendant du statut administratif de l’installation.
                    </p>

                    <div class="mt-6">
                        <span
                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="accessBadgeClass(access)"
                        >
                            {{ accessPrimaryLabel(access) }}
                        </span>

                        <p
                            v-if="accessDetailLabel(access)"
                            class="mt-3 text-sm text-gray-700"
                        >
                            {{ accessDetailLabel(access) }}
                        </p>
                    </div>
                </section>

                <section
                    v-if="lastSubscription"
                    class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-emerald-100 sm:p-8"
                >
                    <h2 class="text-lg font-semibold text-gray-900">
                        Crédit disponible
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Synthèse fournie par le serveur pour l’abonnement actuel.
                    </p>

                    <template v-if="credit && creditMonthsHeadline(credit.available_months)">
                        <p class="mt-6 text-2xl font-semibold text-gray-900">
                            {{ creditMonthsHeadline(credit.available_months) }}
                        </p>

                        <p
                            v-if="paymentsCountLabel(credit.payment_count)"
                            class="mt-1 text-sm text-gray-600"
                        >
                            {{ paymentsCountLabel(credit.payment_count) }}
                        </p>
                    </template>

                    <p
                        v-else
                        class="mt-6 text-sm text-gray-700"
                    >
                        Aucun crédit disponible
                    </p>

                    <div
                        v-if="lastSubscription?.id"
                        class="mt-4"
                    >
                        <Link
                            :href="`/subscriptions/${lastSubscription.id}`"
                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                        >
                            Voir l’abonnement
                        </Link>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Paiements
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Résumé des paiements liés à l’abonnement ou à l’historique de cette installation.
                    </p>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Nombre de paiements
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ paymentsSummary?.count ?? 0 }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Dernier paiement
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <template v-if="paymentsSummary?.last_payment">
                                    {{ formatMoney(paymentsSummary.last_payment.amount, paymentsSummary.last_payment.currency) }}
                                    — {{ formatDateOnly(paymentsSummary.last_payment.paid_at) }}
                                </template>
                                <template v-else>
                                    —
                                </template>
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                        <Link
                            href="/payments"
                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                        >
                            Voir les paiements
                        </Link>

                        <Link
                            v-if="paymentsSummary?.last_payment?.id"
                            :href="`/payments/${paymentsSummary.last_payment.id}`"
                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                        >
                            Voir le dernier paiement
                        </Link>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                Modules
                            </h2>

                            <p class="mt-1 text-sm text-gray-500">
                                Modules affectés à cette installation.
                            </p>
                        </div>

                        <Link
                            href="/installation-modules"
                            class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                        >
                            Gérer les modules
                        </Link>
                    </div>

                    <div
                        v-if="!modules.length"
                        class="mt-6 text-sm text-gray-700"
                    >
                        Aucun module affecté à cette installation.
                    </div>

                    <ul
                        v-else
                        class="mt-6 space-y-4"
                    >
                        <li
                            v-for="assignment in modules"
                            :key="assignment.id"
                            class="rounded-lg border border-gray-200 bg-gray-50/50 p-4"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">
                                        {{ displayValue(assignment.module?.name) }}
                                    </p>

                                    <p
                                        v-if="assignment.version || assignment.module?.price != null"
                                        class="mt-1 text-sm text-gray-600"
                                    >
                                        <span v-if="assignment.version">
                                            Version {{ assignment.version }}
                                        </span>
                                        <span
                                            v-if="assignment.version && assignment.module?.price != null"
                                            class="mx-1"
                                        >
                                            ·
                                        </span>
                                        <span v-if="assignment.module?.price != null">
                                            {{ formatMoney(assignment.module.price, assignment.module.currency) }}
                                        </span>
                                    </p>
                                </div>

                                <span
                                    class="inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="moduleAssignmentStatusBadgeClass(assignment.status)"
                                >
                                    {{ moduleAssignmentStatusLabel(assignment.status) }}
                                </span>
                            </div>

                            <Link
                                :href="`/installation-modules/${assignment.id}`"
                                class="mt-3 inline-block text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                            >
                                Voir l’affectation
                            </Link>
                        </li>
                    </ul>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Base de données
                    </h2>

                    <p class="mt-1 text-sm text-amber-800/90">
                        Référence uniquement — le Control Center ne se connecte pas actuellement à cette base.
                    </p>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Nom de la base de données
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.database_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Hôte de la base de données
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.database_host) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Activité
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date d'installation
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installation.installed_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Dernière présence enregistrée
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installation.last_seen_at) }}
                                <span class="mt-1 block text-xs text-gray-500">Saisie manuelle actuellement</span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de suspension (installation)
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installation.suspended_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de terminaison (installation)
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installation.terminated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                Client
                            </h2>

                            <p class="mt-1 text-sm text-gray-500">
                                Entreprise et contact associés à cette installation.
                            </p>
                        </div>

                        <Link
                            v-if="installation.client?.id"
                            :href="`/clients/${installation.client.id}`"
                            class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                        >
                            Voir le client
                        </Link>
                    </div>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Entreprise
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(installation.client?.company_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Contact
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.client?.contact_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Téléphone
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.client?.phone) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Adresse e-mail
                            </dt>
                            <dd class="mt-1 break-all text-sm text-gray-900">
                                {{ displayValue(installation.client?.email) }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Adresse
                            </dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-gray-900">
                                {{ displayValue(installation.client?.address) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Ville
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.client?.city) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Pays
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installation.client?.country) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Dates système
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Créée le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installation.created_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Dernière modification
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installation.updated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link
                        href="/installations"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour à la liste
                    </Link>

                    <Link
                        :href="`/installations/${installation.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier l'installation
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
