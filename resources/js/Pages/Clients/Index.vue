<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    clients: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({
            status: null,
        }),
    },
});

const filterForm = reactive({
    status: props.filters.status ?? '',
});

watch(
    () => props.filters,
    (filters) => {
        filterForm.status = filters.status ?? '';
    },
    { deep: true },
);

const hasActiveFilters = computed(
    () => filterForm.status !== '' && filterForm.status != null,
);

function buildFilterParams() {
    const params = {};

    if (filterForm.status !== '') {
        params.status = filterForm.status;
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

    router.get('/clients', {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

const page = usePage();

const deletingId = ref(null);
const clientPendingDelete = ref(null);

function formatDate(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'medium',
    }).format(new Date(value));
}

function statusLabel(status) {
    return status === 'active' ? 'Actif' : 'Inactif';
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

function isPreviousLink(link) {
    return link.label.includes('Previous') || link.label.includes('Précédent') || link.label.includes('&laquo;');
}

function isNextLink(link) {
    return link.label.includes('Next') || link.label.includes('Suivant') || link.label.includes('&raquo;');
}

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

    const client = clientPendingDelete.value;
    deletingId.value = client.id;

    router.delete(`/clients/${client.id}`, {
        onFinish: () => {
            deletingId.value = null;
            clientPendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Clients" />

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
                        Êtes-vous sûr de vouloir supprimer le client « {{ clientPendingDelete.company_name }} » ?
                    </p>

                    <p class="mt-2 text-sm text-gray-500">
                        Cette action est irréversible.
                    </p>

                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="deletingId !== null"
                            @click="cancelDelete"
                        >
                            Annuler
                        </button>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="deletingId !== null"
                            :aria-busy="deletingId !== null"
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

                    <p class="mt-1 text-sm text-gray-500">
                        Gérez les entreprises et commerçants utilisant MKD-Pro.
                    </p>
                </div>

                <Link
                    href="/clients/create"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Nouveau client
                </Link>
            </div>

            <form
                class="mt-8 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:p-6"
                @submit.prevent="applyFilters"
            >
                <div>
                    <label
                        for="filter-client-status"
                        class="block text-sm font-medium text-gray-700"
                    >
                        Statut
                    </label>
                    <select
                        id="filter-client-status"
                        v-model="filterForm.status"
                        class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200 sm:max-w-md"
                    >
                        <option value="">
                            Tous les statuts
                        </option>
                        <option value="active">
                            Actif
                        </option>
                        <option value="inactive">
                            Inactif
                        </option>
                    </select>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                        @click="resetFilters"
                    >
                        Réinitialiser
                    </button>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Filtrer
                    </button>
                </div>
            </form>

            <div
                v-if="!clients.data.length"
                class="mt-8 rounded-xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center shadow-sm"
            >
                <h2 class="text-lg font-semibold text-gray-900">
                    Aucun client
                </h2>

                <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                    Aucun client n'est encore enregistré. Commencez par ajouter votre premier commerçant ou entreprise.
                </p>

                <Link
                    href="/clients/create"
                    class="mt-6 inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Créer le premier client
                </Link>
            </div>

            <div
                v-else
                class="mt-8 space-y-6"
            >
                <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                    >
                                        Entreprise
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                    >
                                        Contact
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                    >
                                        Téléphone
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6"
                                    >
                                        Ville
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                    >
                                        Statut
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 sm:table-cell sm:px-6"
                                    >
                                        Créé le
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
                                    v-for="client in clients.data"
                                    :key="client.id"
                                >
                                    <td class="whitespace-nowrap px-4 py-4 font-medium text-gray-900 sm:px-6">
                                        {{ client.company_name }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-gray-700 sm:px-6">
                                        {{ client.contact_name }}
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                        {{ displayValue(client.phone) }}
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 lg:table-cell sm:px-6">
                                        {{ displayValue(client.city) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 sm:px-6">
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
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 sm:table-cell sm:px-6">
                                        {{ formatDate(client.created_at) }}
                                    </td>
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <Link
                                                :href="`/clients/${client.id}`"
                                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            >
                                                Voir
                                            </Link>
                                            <Link
                                                :href="`/clients/${client.id}/edit`"
                                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            >
                                                Modifier
                                            </Link>
                                            <button
                                                type="button"
                                                class="text-sm font-medium text-red-700 underline-offset-2 hover:text-red-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="deletingId === client.id"
                                                :aria-busy="deletingId === client.id"
                                                @click="openDeleteConfirm(client)"
                                            >
                                                {{ deletingId === client.id ? 'Suppression…' : 'Supprimer' }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <nav
                    v-if="clients.last_page > 1"
                    class="flex flex-wrap items-center justify-center gap-1"
                    aria-label="Pagination des clients"
                >
                    <template
                        v-for="(link, index) in clients.links"
                        :key="`${link.label}-${index}`"
                    >
                        <span
                            v-if="!link.url"
                            class="inline-flex min-w-[2.25rem] items-center justify-center rounded-lg px-3 py-2 text-sm text-gray-400"
                            :class="{
                                'font-medium': link.active,
                            }"
                            aria-disabled="true"
                        >
                            {{ paginationLabel(link) }}
                        </span>
                        <Link
                            v-else
                            :href="link.url"
                            preserve-scroll
                            class="inline-flex min-w-[2.25rem] items-center justify-center rounded-lg px-3 py-2 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                            :class="
                                link.active
                                    ? 'bg-gray-900 font-medium text-white'
                                    : 'text-gray-700 hover:bg-gray-100'
                            "
                            :aria-current="link.active ? 'page' : undefined"
                            :aria-label="
                                isPreviousLink(link)
                                    ? 'Page précédente'
                                    : isNextLink(link)
                                      ? 'Page suivante'
                                      : `Page ${paginationLabel(link)}`
                            "
                        >
                            {{ paginationLabel(link) }}
                        </Link>
                    </template>
                </nav>
            </div>
        </div>
    </AdminLayout>
</template>
