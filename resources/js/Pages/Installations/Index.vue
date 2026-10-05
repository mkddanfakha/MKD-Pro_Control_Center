<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    installations: {
        type: Object,
        required: true,
    },
    admin_urls: {
        type: Object,
        default: () => ({}),
    },
    clients: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({
            status: null,
            client_id: null,
            search: null,
            version: null,
        }),
    },
});

const filterForm = reactive({
    status: props.filters.status ?? '',
    client_id: props.filters.client_id != null ? String(props.filters.client_id) : '',
    search: props.filters.search ?? '',
    version: props.filters.version ?? '',
});

watch(
    () => props.filters,
    (filters) => {
        filterForm.status = filters.status ?? '';
        filterForm.client_id = filters.client_id != null ? String(filters.client_id) : '';
        filterForm.search = filters.search ?? '';
        filterForm.version = filters.version ?? '';
    },
    { deep: true },
);

const hasActiveFilters = computed(
    () => filterForm.status !== ''
        || (filterForm.client_id !== '' && filterForm.client_id != null)
        || filterForm.search.trim() !== ''
        || filterForm.version.trim() !== '',
);

const page = usePage();
const selectedInstallation = ref(null);
const deletingId = ref(null);
const installationPendingDelete = ref(null);

const createInstallationUrl = computed(() => props.admin_urls.create ?? '/installations/create');

function installationEditUrl(installation) {
    return installation.edit_url ?? `/installations/${installation.id}/edit`;
}

function openDeleteConfirm(installation) {
    installationPendingDelete.value = installation;
}

function cancelDelete() {
    if (deletingId.value !== null) {
        return;
    }

    installationPendingDelete.value = null;
}

function confirmDelete() {
    if (!installationPendingDelete.value || deletingId.value !== null) {
        return;
    }

    const item = installationPendingDelete.value;
    deletingId.value = item.id;

    router.delete(`/installations/${item.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
            installationPendingDelete.value = null;
        },
    });
}

function buildFilterParams() {
    const params = {};

    if (filterForm.status !== '') {
        params.status = filterForm.status;
    }

    if (filterForm.client_id !== '') {
        params.client_id = filterForm.client_id;
    }

    if (filterForm.search.trim() !== '') {
        params.search = filterForm.search.trim();
    }

    if (filterForm.version.trim() !== '') {
        params.version = filterForm.version.trim();
    }

    return params;
}

function applyFilters() {
    router.get('/installations', buildFilterParams(), {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetFilters() {
    filterForm.status = '';
    filterForm.client_id = '';
    filterForm.search = '';
    filterForm.version = '';

    router.get('/installations', {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function visitPagination(url) {
    if (!url) {
        return;
    }

    router.get(url, {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function openDetail(installation) {
    selectedInstallation.value = installation;
}

function closeDetail() {
    selectedInstallation.value = null;
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

function formatPeriodEnd(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'medium',
        timeZone: 'UTC',
    }).format(new Date(value));
}

function installationStatusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
        suspended: 'Suspendu',
        terminated: 'Terminé',
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

function subscriptionStatusBadgeClass(status) {
    const classes = {
        active: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        grace_period: 'bg-amber-50 text-amber-900 ring-amber-200',
        suspended: 'bg-orange-50 text-orange-900 ring-orange-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function reminderStatusLabel(status) {
    const labels = {
        detected: 'Détecté',
        sent: 'Envoyé',
        failed: 'Échec',
    };

    return labels[status] ?? status;
}

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function paginationLabel(link) {
    const label = link.label
        .replace(/&laquo;/g, '')
        .replace(/&raquo;/g, '')
        .trim();

    if (label === 'Previous' || label === 'Précédent') {
        return 'Précédent';
    }

    if (label === 'Next' || label === 'Suivant') {
        return 'Suivant';
    }

    return label;
}
</script>

<template>
    <Head title="Installations" />

    <AdminLayout>
        <div class="space-y-6">
            <div
                v-if="installationPendingDelete"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-installation-title"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2
                        id="delete-installation-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Supprimer l'installation
                    </h2>
                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer cette installation ?
                    </p>
                    <p class="mt-2 text-sm text-gray-500">
                        La suppression est refusée si un abonnement ou des modules sont encore associés.
                    </p>
                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm"
                            :disabled="deletingId !== null"
                            @click="cancelDelete"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-red-700 disabled:opacity-60"
                            :disabled="deletingId !== null"
                            @click="confirmDelete"
                        >
                            {{ deletingId !== null ? 'Suppression…' : 'Supprimer' }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Installations
                    </h1>
                    <p class="mt-1 text-sm text-gray-600">
                        Gestion des installations MKD-Pro enregistrées dans le Control Center.
                    </p>
                </div>

                <Link
                    :href="createInstallationUrl"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800"
                >
                    Nouvelle installation
                </Link>
            </div>

            <form
                class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:p-6"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label
                            for="filter-installation-status"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Statut
                        </label>
                        <select
                            id="filter-installation-status"
                            v-model="filterForm.status"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                            <option value="">
                                Tous
                            </option>
                            <option value="active">
                                Actif
                            </option>
                            <option value="inactive">
                                Inactif
                            </option>
                            <option value="suspended">
                                Suspendu
                            </option>
                            <option value="terminated">
                                Terminé
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="filter-installation-client"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Client
                        </label>
                        <select
                            id="filter-installation-client"
                            v-model="filterForm.client_id"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                            <option value="">
                                Tous les clients
                            </option>
                            <option
                                v-for="client in clients"
                                :key="client.id"
                                :value="String(client.id)"
                            >
                                {{ client.company_name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="filter-installation-search"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Recherche
                        </label>
                        <input
                            id="filter-installation-search"
                            v-model="filterForm.search"
                            type="search"
                            placeholder="Nom, sous-domaine ou domaine"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-installation-version"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Version
                        </label>
                        <input
                            id="filter-installation-version"
                            v-model="filterForm.version"
                            type="text"
                            placeholder="ex. 1.0.0"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50"
                        @click="resetFilters"
                    >
                        Réinitialiser
                    </button>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800"
                    >
                        Filtrer
                    </button>
                </div>
            </form>

            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
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
                                    class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                >
                                    Client
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                >
                                    Sous-domaine
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                >
                                    Statut
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6"
                                >
                                    Version
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                >
                                    Abonnement
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                >
                                    Échéance
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6"
                                >
                                    Dernière activité
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                >
                                    Détail
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr
                                v-for="installation in installations.data"
                                :key="installation.id"
                                class="hover:bg-gray-50"
                            >
                                <td class="whitespace-nowrap px-4 py-4 font-medium text-gray-900 sm:px-6">
                                    {{ installation.name }}
                                </td>
                                <td class="px-4 py-4 sm:px-6">
                                    <div class="font-medium text-gray-900">
                                        {{ displayValue(installation.client?.company_name) }}
                                    </div>
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                    {{ displayValue(installation.subdomain) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 sm:px-6">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="installationStatusBadgeClass(installation.status)"
                                    >
                                        {{ installationStatusLabel(installation.status) }}
                                    </span>
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 lg:table-cell sm:px-6">
                                    {{ displayValue(installation.version) }}
                                </td>
                                <td class="px-4 py-4 sm:px-6">
                                    <template v-if="installation.current_subscription">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            :class="subscriptionStatusBadgeClass(installation.current_subscription.status)"
                                        >
                                            {{ subscriptionStatusLabel(installation.current_subscription.status) }}
                                        </span>
                                    </template>
                                    <span
                                        v-else
                                        class="text-sm text-gray-500"
                                    >
                                        Aucun abonnement actif
                                    </span>
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                    {{
                                        installation.current_subscription
                                            ? formatPeriodEnd(installation.current_subscription.current_period_end)
                                            : '—'
                                    }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 lg:table-cell sm:px-6">
                                    {{ formatDateTime(installation.last_seen_at) }}
                                </td>
                                <td class="px-4 py-4 sm:px-6">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                        <Link
                                            v-if="installation.show_url"
                                            :href="installation.show_url"
                                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                        >
                                            Voir
                                        </Link>
                                        <Link
                                            :href="installationEditUrl(installation)"
                                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                        >
                                            Modifier
                                        </Link>
                                        <button
                                            type="button"
                                            v-if="installation.can_delete !== false"
                                            class="text-sm font-medium text-red-700 underline-offset-2 hover:text-red-900 hover:underline disabled:opacity-60"
                                            :disabled="deletingId === installation.id"
                                            @click="openDeleteConfirm(installation)"
                                        >
                                            {{ deletingId === installation.id ? 'Suppression…' : 'Supprimer' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!installations.data.length">
                                <td
                                    colspan="9"
                                    class="px-4 py-10 text-center text-sm text-gray-500 sm:px-6"
                                >
                                    Aucune installation ne correspond aux critères.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="installations.links && installations.links.length > 3"
                    class="flex flex-wrap items-center justify-center gap-1 border-t border-gray-200 px-4 py-3"
                >
                    <button
                        v-for="(link, index) in installations.links"
                        :key="index"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm disabled:text-gray-400"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="paginationLabel(link)"
                    />
                </div>
            </div>
        </div>

        <div
            v-if="selectedInstallation"
            class="fixed inset-0 z-50 overflow-y-auto bg-black/40"
        >
            <div
                class="flex min-h-full items-center justify-center p-4"
                role="dialog"
                aria-modal="true"
                @click.self="closeDetail"
            >
                <div
                    class="w-full max-w-lg rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200"
                    @click.stop
                >
                    <div class="flex items-start justify-between gap-4">
                        <h2 class="text-lg font-semibold text-gray-900">
                            {{ selectedInstallation.name }}
                        </h2>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
                            @click="closeDetail"
                        >
                            ✕
                        </button>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm">
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Client
                            </dt>
                            <dd class="text-gray-900">
                                {{ displayValue(selectedInstallation.client?.company_name) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Sous-domaine
                            </dt>
                            <dd class="text-gray-900">
                                {{ displayValue(selectedInstallation.subdomain) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Domaine
                            </dt>
                            <dd class="text-gray-900">
                                {{ displayValue(selectedInstallation.domain) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Statut
                            </dt>
                            <dd>
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="installationStatusBadgeClass(selectedInstallation.status)"
                                >
                                    {{ installationStatusLabel(selectedInstallation.status) }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Version
                            </dt>
                            <dd class="text-gray-900">
                                {{ displayValue(selectedInstallation.version) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Date d'installation
                            </dt>
                            <dd class="text-gray-900">
                                {{ formatDateTime(selectedInstallation.installed_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Dernière activité
                            </dt>
                            <dd class="text-gray-900">
                                {{ formatDateTime(selectedInstallation.last_seen_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Abonnement courant
                            </dt>
                            <dd class="text-gray-900">
                                <template v-if="selectedInstallation.current_subscription">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="subscriptionStatusBadgeClass(selectedInstallation.current_subscription.status)"
                                    >
                                        {{ subscriptionStatusLabel(selectedInstallation.current_subscription.status) }}
                                    </span>
                                    <span class="ml-2 text-gray-600">
                                        Fin de période :
                                        {{ formatPeriodEnd(selectedInstallation.current_subscription.current_period_end) }}
                                        (UTC)
                                    </span>
                                    <Link
                                        :href="selectedInstallation.current_subscription.show_url ?? `/subscriptions/${selectedInstallation.current_subscription.id}`"
                                        class="mt-2 block text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                                    >
                                        Voir l'abonnement
                                    </Link>
                                    <Link
                                        :href="selectedInstallation.show_url ?? `/installations/${selectedInstallation.id}`"
                                        class="mt-2 block text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                                    >
                                        Ouvrir la fiche
                                    </Link>
                                </template>
                                <span v-else>Aucun abonnement actif</span>
                            </dd>
                        </div>
                        <div v-if="selectedInstallation.last_reminder">
                            <dt class="text-xs uppercase text-gray-500">
                                Dernier rappel (lecture seule)
                            </dt>
                            <dd class="text-gray-900">
                                {{ reminderStatusLabel(selectedInstallation.last_reminder.status) }}
                                — seuil J-{{ selectedInstallation.last_reminder.threshold_days }}
                                <span class="text-gray-500">
                                    ({{ formatDateTime(selectedInstallation.last_reminder.detected_at) }})
                                </span>
                                <Link
                                    href="/subscription-reminders"
                                    class="mt-2 block text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                                >
                                    Voir les rappels
                                </Link>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
