<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    totalClients: {
        type: Number,
        default: 0,
    },
    totalInstallations: {
        type: Number,
        default: 0,
    },
    activeInstallations: {
        type: Number,
        default: 0,
    },
    suspendedInstallations: {
        type: Number,
        default: 0,
    },
    terminatedInstallations: {
        type: Number,
        default: 0,
    },
    totalSubscriptions: {
        type: Number,
        default: 0,
    },
    activeSubscriptions: {
        type: Number,
        default: 0,
    },
    gracePeriodSubscriptions: {
        type: Number,
        default: 0,
    },
    suspendedSubscriptions: {
        type: Number,
        default: 0,
    },
    terminatedSubscriptions: {
        type: Number,
        default: 0,
    },
    totalPayments: {
        type: Number,
        default: 0,
    },
    paidPayments: {
        type: Number,
        default: 0,
    },
    pendingPayments: {
        type: Number,
        default: 0,
    },
    failedPayments: {
        type: Number,
        default: 0,
    },
    refundedPayments: {
        type: Number,
        default: 0,
    },
    totalPaidAmount: {
        type: Number,
        default: 0,
    },
    subscriptionsExpiringSoon: {
        type: Number,
        default: 0,
    },
    overduePayments: {
        type: Number,
        default: 0,
    },
    recentActivities: {
        type: Array,
        default: () => [],
    },
});

function formatCount(value) {
    return new Intl.NumberFormat('fr-FR').format(Number(value ?? 0));
}

function formatFcfa(amount) {
    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount ?? 0));

    return `${formatted} FCFA`;
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

function activityBadgeClass(type) {
    const classes = {
        client: 'bg-violet-50 text-violet-800 ring-violet-200',
        installation: 'bg-sky-50 text-sky-800 ring-sky-200',
        subscription: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        payment: 'bg-amber-50 text-amber-900 ring-amber-200',
    };

    return classes[type] ?? 'bg-gray-100 text-gray-700 ring-gray-200';
}
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <div class="space-y-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    Dashboard
                </h1>

                <p class="mt-1 text-sm text-gray-500">
                    Vue d’ensemble opérationnelle de votre écosystème MKD-Pro.
                </p>
            </div>

            <!-- A. Vue générale -->
            <section>
                <h2 class="text-lg font-semibold text-gray-900">
                    Vue générale
                </h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                        <p class="text-sm font-medium text-gray-500">
                            Clients
                        </p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">
                            {{ formatCount(totalClients) }}
                        </p>
                        <Link
                            href="/clients"
                            class="mt-3 inline-block text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                        >
                            Voir les clients
                        </Link>
                    </div>

                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                        <p class="text-sm font-medium text-gray-500">
                            Installations
                        </p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">
                            {{ formatCount(totalInstallations) }}
                        </p>
                        <Link
                            href="/installations"
                            class="mt-3 inline-block text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                        >
                            Voir les installations
                        </Link>
                    </div>

                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                        <p class="text-sm font-medium text-gray-500">
                            Abonnements
                        </p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">
                            {{ formatCount(totalSubscriptions) }}
                        </p>
                        <Link
                            href="/subscriptions"
                            class="mt-3 inline-block text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                        >
                            Voir les abonnements
                        </Link>
                    </div>

                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                        <p class="text-sm font-medium text-gray-500">
                            Paiements
                        </p>
                        <p class="mt-2 text-3xl font-bold text-gray-900">
                            {{ formatCount(totalPayments) }}
                        </p>
                        <Link
                            href="/payments"
                            class="mt-3 inline-block text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                        >
                            Voir les paiements
                        </Link>
                    </div>
                </div>
            </section>

            <div class="grid gap-8 xl:grid-cols-2">
                <!-- B. État des installations -->
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        État des installations
                    </h2>

                    <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Actives
                            </dt>
                            <dd class="mt-1 text-2xl font-bold text-sky-800">
                                {{ formatCount(activeInstallations) }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Suspendues
                            </dt>
                            <dd class="mt-1 text-2xl font-bold text-amber-900">
                                {{ formatCount(suspendedInstallations) }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Terminées
                            </dt>
                            <dd class="mt-1 text-2xl font-bold text-gray-700">
                                {{ formatCount(terminatedInstallations) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- C. État des abonnements -->
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        État des abonnements
                    </h2>

                    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Actifs
                            </dt>
                            <dd class="mt-1 text-2xl font-bold text-sky-800">
                                {{ formatCount(activeSubscriptions) }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Période de grâce
                            </dt>
                            <dd class="mt-1 text-2xl font-bold text-amber-900">
                                {{ formatCount(gracePeriodSubscriptions) }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Suspendus
                            </dt>
                            <dd class="mt-1 text-2xl font-bold text-orange-900">
                                {{ formatCount(suspendedSubscriptions) }}
                            </dd>
                        </div>

                        <div class="rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Terminés
                            </dt>
                            <dd class="mt-1 text-2xl font-bold text-gray-700">
                                {{ formatCount(terminatedSubscriptions) }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <!-- D. Paiements -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Paiements
                </h2>

                <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">
                            Total
                        </dt>
                        <dd class="mt-1 text-xl font-bold text-gray-900">
                            {{ formatCount(totalPayments) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500">
                            Payés
                        </dt>
                        <dd class="mt-1 text-xl font-bold text-emerald-800">
                            {{ formatCount(paidPayments) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500">
                            En attente
                        </dt>
                        <dd class="mt-1 text-xl font-bold text-amber-900">
                            {{ formatCount(pendingPayments) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500">
                            Échoués
                        </dt>
                        <dd class="mt-1 text-xl font-bold text-red-800">
                            {{ formatCount(failedPayments) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500">
                            Remboursés
                        </dt>
                        <dd class="mt-1 text-xl font-bold text-gray-700">
                            {{ formatCount(refundedPayments) }}
                        </dd>
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3 xl:col-span-1">
                        <dt class="text-sm font-medium text-gray-500">
                            Montant encaissé
                        </dt>
                        <dd class="mt-1 text-xl font-bold text-gray-900">
                            {{ formatFcfa(totalPaidAmount) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- E. Alertes opérationnelles -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Alertes opérationnelles
                </h2>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div
                        class="rounded-lg border px-4 py-4"
                        :class="
                            subscriptionsExpiringSoon > 0
                                ? 'border-amber-200 bg-amber-50'
                                : 'border-gray-200 bg-gray-50'
                        "
                    >
                        <p class="text-sm font-medium text-gray-900">
                            Échéances dans les 7 jours
                        </p>
                        <p
                            class="mt-2 text-2xl font-bold"
                            :class="subscriptionsExpiringSoon > 0 ? 'text-amber-900' : 'text-gray-900'"
                        >
                            {{ formatCount(subscriptionsExpiringSoon) }}
                        </p>
                        <p class="mt-1 text-xs text-gray-600">
                            Abonnements actifs dont la période se termine sous 7 jours.
                        </p>
                        <Link
                            v-if="subscriptionsExpiringSoon > 0"
                            href="/subscriptions?expiring_within_days=7"
                            class="mt-3 inline-block text-sm font-medium text-amber-900 underline-offset-2 hover:underline"
                        >
                            Consulter les abonnements
                        </Link>
                    </div>

                    <div
                        class="rounded-lg border px-4 py-4"
                        :class="
                            overduePayments > 0
                                ? 'border-red-200 bg-red-50'
                                : 'border-gray-200 bg-gray-50'
                        "
                    >
                        <p class="text-sm font-medium text-gray-900">
                            Paiements en retard
                        </p>
                        <p
                            class="mt-2 text-2xl font-bold"
                            :class="overduePayments > 0 ? 'text-red-800' : 'text-gray-900'"
                        >
                            {{ formatCount(overduePayments) }}
                        </p>
                        <p class="mt-1 text-xs text-gray-600">
                            Paiements en attente ou échoués dont l’échéance est dépassée.
                        </p>
                        <Link
                            v-if="overduePayments > 0"
                            href="/payments?overdue=1"
                            class="mt-3 inline-block text-sm font-medium text-red-800 underline-offset-2 hover:underline"
                        >
                            Consulter les paiements
                        </Link>
                    </div>
                </div>
            </section>

            <!-- F. Dernières activités -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Dernières activités
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Derniers enregistrements créés dans le Control Center.
                </p>

                <div
                    v-if="!recentActivities.length"
                    class="mt-6 rounded-lg border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-500"
                >
                    Aucune activité récente pour le moment.
                </div>

                <ul
                    v-else
                    class="mt-6 divide-y divide-gray-200"
                >
                    <li
                        v-for="(activity, index) in recentActivities"
                        :key="`${activity.type}-${activity.url}-${index}`"
                    >
                        <Link
                            :href="activity.url"
                            class="flex flex-col gap-3 py-4 transition hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between sm:px-2"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="activityBadgeClass(activity.type)"
                                    >
                                        {{ activity.type_label }}
                                    </span>
                                    <span class="truncate text-sm font-medium text-gray-900">
                                        {{ activity.label }}
                                    </span>
                                </div>
                            </div>

                            <time
                                class="shrink-0 text-sm text-gray-500"
                                :datetime="activity.occurred_at ?? undefined"
                            >
                                {{ formatDateTime(activity.occurred_at) }}
                            </time>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </AdminLayout>
</template>
