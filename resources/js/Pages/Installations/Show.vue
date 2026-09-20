<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    installation: {
        type: Object,
        required: true,
    },
});

const page = usePage();

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function statusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
        suspended: 'Suspendue',
        terminated: 'Terminée',
    };

    return labels[status] ?? status;
}

function statusBadgeClass(status) {
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
                        Informations générales
                    </h2>

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
                            <dd class="mt-1 text-sm text-gray-900 break-all">
                                {{ displayValue(installation.domain) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="statusBadgeClass(installation.status)"
                                >
                                    {{ statusLabel(installation.status) }}
                                </span>
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

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Base de données
                    </h2>

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
                                Dernière activité
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installation.last_seen_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de suspension
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installation.suspended_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de terminaison
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
                            <dd class="mt-1 text-sm text-gray-900 break-all">
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
