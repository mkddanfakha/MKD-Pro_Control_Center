<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
});

const page = usePage();

const clientInstallations = computed(() => props.client.installations ?? []);

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function statusLabel(status) {
    return status === 'active' ? 'Actif' : 'Inactif';
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

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
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
</script>

<template>
    <Head :title="client.company_name ? `Client — ${client.company_name}` : 'Détail du client'" />

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
                        Détail du client
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Consultez les informations de ce client.
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        href="/clients"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour aux clients
                    </Link>

                    <Link
                        :href="`/clients/${client.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier
                    </Link>
                </div>
            </div>

            <div class="mt-8 space-y-8">
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Informations de l'entreprise
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Entreprise
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(client.company_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Contact
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(client.contact_name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="
                                        client.status === 'active'
                                            ? 'bg-sky-50 text-sky-800 ring-sky-200'
                                            : 'bg-gray-100 text-gray-600 ring-gray-200'
                                    "
                                >
                                    {{ statusLabel(client.status) }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Coordonnées
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Téléphone
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(client.phone) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Adresse e-mail
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all">
                                {{ displayValue(client.email) }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Adresse
                            </dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-gray-900">
                                {{ displayValue(client.address) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Ville
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(client.city) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Pays
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(client.country) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Informations complémentaires
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Notes
                            </dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-gray-900">
                                {{ displayValue(client.notes) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de création
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(client.created_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Dernière modification
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(client.updated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">
                            Installations
                        </h2>

                        <Link
                            href="/installations/create"
                            class="inline-flex shrink-0 items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                        >
                            Nouvelle installation
                        </Link>
                    </div>

                    <div
                        v-if="!clientInstallations.length"
                        class="mt-6 rounded-lg border border-dashed border-gray-300 bg-gray-50/80 px-6 py-10 text-center"
                    >
                        <h3 class="text-base font-semibold text-gray-900">
                            Aucune installation
                        </h3>

                        <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                            Ce client n'a encore aucune installation MKD-Pro.
                        </p>
                    </div>

                    <div
                        v-else
                        class="mt-6 overflow-hidden rounded-xl ring-1 ring-gray-200"
                    >
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th
                                            scope="col"
                                            class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                        >
                                            Installation
                                        </th>
                                        <th
                                            scope="col"
                                            class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                        >
                                            Sous-domaine
                                        </th>
                                        <th
                                            scope="col"
                                            class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6"
                                        >
                                            Domaine
                                        </th>
                                        <th
                                            scope="col"
                                            class="hidden px-4 py-3 font-semibold text-gray-700 sm:table-cell sm:px-6"
                                        >
                                            Version
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                        >
                                            Statut installation
                                        </th>
                                        <th
                                            scope="col"
                                            class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                        >
                                            Dernière présence enregistrée
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                        >
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr
                                        v-for="installation in clientInstallations"
                                        :key="installation.id"
                                    >
                                        <td class="whitespace-nowrap px-4 py-4 font-medium text-gray-900 sm:px-6">
                                            {{ installation.name }}
                                        </td>
                                        <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                            {{ installation.subdomain }}
                                        </td>
                                        <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 lg:table-cell sm:px-6">
                                            {{ displayValue(installation.domain) }}
                                        </td>
                                        <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 sm:table-cell sm:px-6">
                                            {{ displayValue(installation.version) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4 sm:px-6">
                                            <span
                                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                                :class="installationStatusBadgeClass(installation.status)"
                                            >
                                                {{ installationStatusLabel(installation.status) }}
                                            </span>
                                        </td>
                                        <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                            {{ formatDate(installation.last_seen_at) }}
                                        </td>
                                        <td class="px-4 py-4 sm:px-6">
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                <Link
                                                    :href="`/installations/${installation.id}`"
                                                    class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                                >
                                                    Voir
                                                </Link>
                                                <Link
                                                    :href="`/installations/${installation.id}/edit`"
                                                    class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                                >
                                                    Modifier
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-dashed border-gray-300 bg-gray-50/80 p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Écosystème MKD-Pro
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Modules liés à ce client — contenu à venir.
                    </p>

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                            <h3 class="text-sm font-semibold text-gray-900">
                                Abonnement
                            </h3>
                            <p class="mt-2 text-sm text-gray-500">
                                L'abonnement de ce client sera affiché ici.
                            </p>
                        </div>

                        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                            <h3 class="text-sm font-semibold text-gray-900">
                                Paiements
                            </h3>
                            <p class="mt-2 text-sm text-gray-500">
                                L'historique des paiements sera affiché ici.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
