<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    subscription: {
        type: Object,
        required: true,
    },
});

const page = usePage();

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
