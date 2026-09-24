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
        active: 'Abonnement actif',
        grace_period: 'Période de grâce',
        suspended: 'Abonnement suspendu',
        terminated: 'Abonnement terminé',
    };

    return labels[status] ?? status;
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
                    <h2 class="text-lg font-semibold text-gray-900">
                        Abonnement
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Abonnement pris en compte : dernier abonnement enregistré.
                    </p>

                    <template v-if="lastSubscription">
                        <p class="mt-3 text-sm font-medium text-violet-900">
                            Accès déterminé selon cet abonnement.
                        </p>

                        <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">
                                    Statut de l’abonnement
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
                                    Montant
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ formatMoney(lastSubscription.amount, lastSubscription.currency) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500">
                                    Début de la période actuelle
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ formatDateTime(lastSubscription.current_period_start) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500">
                                    Fin de la période actuelle
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ formatDateTime(lastSubscription.current_period_end) }}
                                </dd>
                            </div>

                            <div v-if="lastSubscription.grace_period_ends_at">
                                <dt class="text-sm font-medium text-gray-500">
                                    Fin de la période de grâce
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
                        Aucun abonnement enregistré pour cette installation.
                    </p>
                </section>

                <section class="rounded-xl border-2 border-dashed border-gray-200 bg-gray-50/50 p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Accès selon l’abonnement
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Résultat calculé à partir du dernier abonnement — indépendant du statut d’installation.
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
                    <h2 class="text-lg font-semibold text-gray-900">
                        Client
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Entreprise et contact associés à cette installation.
                    </p>

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
