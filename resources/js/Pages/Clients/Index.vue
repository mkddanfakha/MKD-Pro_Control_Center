<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    ADMIN_EMPTY_STATE_MESSAGES,
    formatAdminDateTimeUtc,
} from '@/lib/adminPresentation.js';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    clients: {
        type: Object,
        required: true,
    },
    admin_urls: {
        type: Object,
        default: () => ({}),
    },
    filters: {
        type: Object,
        default: () => ({
            status: null,
            search: null,
            email: null,
            phone: null,
        }),
    },
});

const filterForm = reactive({
    status: props.filters.status ?? '',
    search: props.filters.search ?? '',
    email: props.filters.email ?? '',
    phone: props.filters.phone ?? '',
});

watch(
    () => props.filters,
    (filters) => {
        filterForm.status = filters.status ?? '';
        filterForm.search = filters.search ?? '';
        filterForm.email = filters.email ?? '';
        filterForm.phone = filters.phone ?? '';
    },
    { deep: true },
);

const hasActiveFilters = computed(
    () => filterForm.status !== ''
        || filterForm.search.trim() !== ''
        || filterForm.email.trim() !== ''
        || filterForm.phone.trim() !== '',
);

function buildFilterParams() {
    const params = {};

    if (filterForm.status !== '') {
        params.status = filterForm.status;
    }

    if (filterForm.search.trim() !== '') {
        params.search = filterForm.search.trim();
    }

    if (filterForm.email.trim() !== '') {
        params.email = filterForm.email.trim();
    }

    if (filterForm.phone.trim() !== '') {
        params.phone = filterForm.phone.trim();
    }

    return params;
}

function applyFilters() {
    router.get('/clients', buildFilterParams(), {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetFilters() {
    filterForm.status = '';
    filterForm.search = '';
    filterForm.email = '';
    filterForm.phone = '';

    router.get('/clients', {}, {
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

const emptyStateMessage = ADMIN_EMPTY_STATE_MESSAGES.clients;

function clientStatusLabel(status) {
    return status === 'active' ? 'Actif' : 'Inactif';
}

function clientStatusBadgeClass(status) {
    return status === 'active'
        ? 'bg-sky-50 text-sky-800 ring-sky-200'
        : 'bg-gray-100 text-gray-600 ring-gray-200';
}

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function formatCount(value) {
    return new Intl.NumberFormat('fr-FR').format(Number(value ?? 0));
}

function clientShowUrl(client) {
    return client.show_url ?? `/clients/${client.id}`;
}

function clientEditUrl(client) {
    return client.edit_url ?? `/clients/${client.id}/edit`;
}

const page = usePage();
const deletingId = ref(null);
const clientPendingDelete = ref(null);

function openDeleteConfirm(client) {
    clientPendingDelete.value = client;
}

function cancelDelete() {
    if (deletingId.value !== null) {
        return;
    }

    clientPendingDelete.value = null;
}

function confirmDelete() {
    if (!clientPendingDelete.value || deletingId.value !== null) {
        return;
    }

    const clientItem = clientPendingDelete.value;
    deletingId.value = clientItem.id;

    router.delete(`/clients/${clientItem.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
            clientPendingDelete.value = null;
        },
    });
}

const createClientUrl = computed(() => props.admin_urls.create ?? '/clients/create');
</script>

<template>
    <Head title="Clients" />

    <AdminLayout>
        <div class="space-y-6">
            <div
                v-if="clientPendingDelete"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-client-title"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2
                        id="delete-client-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Supprimer le client
                    </h2>
                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer ce client ?
                    </p>
                    <p class="mt-2 text-sm text-gray-500">
                        Cette action est irréversible. La suppression est refusée si des installations sont encore associées.
                    </p>
                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50"
                            :disabled="deletingId !== null"
                            @click="cancelDelete"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
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
                        Clients
                    </h1>
                    <p class="mt-1 text-sm text-gray-600">
                        Gestion des clients MKD-Pro et accès aux fiches associées.
                    </p>
                </div>

                <Link
                    :href="createClientUrl"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Nouveau client
                </Link>
            </div>

            <form
                class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:p-6"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2 lg:col-span-2">
                        <label
                            for="filter-client-search"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Recherche
                        </label>
                        <input
                            id="filter-client-search"
                            v-model="filterForm.search"
                            type="search"
                            placeholder="Entreprise, contact, e-mail, téléphone, installation…"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-client-status"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Statut client
                        </label>
                        <select
                            id="filter-client-status"
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
                        </select>
                    </div>

                    <div>
                        <label
                            for="filter-client-email"
                            class="block text-sm font-medium text-gray-700"
                        >
                            E-mail
                        </label>
                        <input
                            id="filter-client-email"
                            v-model="filterForm.email"
                            type="search"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-client-phone"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Téléphone
                        </label>
                        <input
                            id="filter-client-phone"
                            v-model="filterForm.phone"
                            type="search"
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
                                <th class="px-4 py-3 font-semibold text-gray-700 sm:px-6">
                                    Client
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700 sm:px-6">
                                    Contact
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6">
                                    Installations
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6">
                                    Actives
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6">
                                    Suspendues
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6">
                                    Terminées
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 xl:table-cell sm:px-6">
                                    Abonnements non terminés
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 sm:table-cell sm:px-6">
                                    Créé le
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700 sm:px-6">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <tr
                                v-for="client in clients.data"
                                :key="client.id"
                                class="hover:bg-gray-50"
                            >
                                <td class="px-4 py-4 sm:px-6">
                                    <div class="font-medium text-gray-900">
                                        {{ client.company_name }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-gray-500">
                                        {{ displayValue(client.email) }}
                                    </div>
                                    <span
                                        class="mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="clientStatusBadgeClass(client.status)"
                                    >
                                        {{ clientStatusLabel(client.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-gray-700 sm:px-6">
                                    <div>{{ client.contact_name }}</div>
                                    <div class="mt-0.5 text-xs text-gray-500 md:hidden">
                                        {{ displayValue(client.phone) }}
                                    </div>
                                </td>
                                <td class="hidden px-4 py-4 text-gray-900 md:table-cell sm:px-6">
                                    {{ formatCount(client.installations_count) }}
                                </td>
                                <td class="hidden px-4 py-4 text-gray-900 lg:table-cell sm:px-6">
                                    {{ formatCount(client.installations_active_count) }}
                                </td>
                                <td class="hidden px-4 py-4 text-gray-900 lg:table-cell sm:px-6">
                                    {{ formatCount(client.installations_suspended_count) }}
                                </td>
                                <td class="hidden px-4 py-4 text-gray-900 lg:table-cell sm:px-6">
                                    {{ formatCount(client.installations_terminated_count) }}
                                </td>
                                <td class="hidden px-4 py-4 text-gray-900 xl:table-cell sm:px-6">
                                    {{ formatCount(client.non_terminated_subscriptions_count) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 sm:table-cell sm:px-6">
                                    {{ formatAdminDateTimeUtc(client.created_at) }}
                                </td>
                                <td class="px-4 py-4 sm:px-6">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                        <Link
                                            :href="clientShowUrl(client)"
                                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                        >
                                            Voir
                                        </Link>
                                        <Link
                                            :href="clientEditUrl(client)"
                                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                        >
                                            Modifier
                                        </Link>
                                        <button
                                            v-if="client.can_delete !== false"
                                            type="button"
                                            class="text-sm font-medium text-red-700 underline-offset-2 hover:text-red-900 hover:underline disabled:cursor-not-allowed disabled:opacity-60"
                                            :disabled="deletingId === client.id"
                                            @click="openDeleteConfirm(client)"
                                        >
                                            {{ deletingId === client.id ? 'Suppression…' : 'Supprimer' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!clients.data.length">
                                <td
                                    colspan="9"
                                    class="px-4 py-10 text-center text-sm text-gray-500 sm:px-6"
                                >
                                    {{ emptyStateMessage }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="clients.links && clients.links.length > 3"
                    class="flex flex-wrap items-center justify-center gap-1 border-t border-gray-200 px-4 py-3"
                >
                    <button
                        v-for="(link, index) in clients.links"
                        :key="index"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm disabled:text-gray-400"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
