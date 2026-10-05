<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    displayAdminValue,
    formatAdminAmount,
    formatAdminDateTimeUtc,
    subscriptionStatusBadgeClass,
    subscriptionStatusLabel,
} from '@/lib/adminPresentation.js';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    subscriptions: {
        type: Object,
        required: true,
    },
    admin_urls: {
        type: Object,
        default: () => ({}),
    },
    indicators: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({
            status: null,
            installation_status: null,
            client_id: null,
            installation_id: null,
            search: null,
            period: null,
            expiring_within_days: null,
        }),
    },
});

const filterForm = reactive({
    status: props.filters.status ?? '',
    installation_status: props.filters.installation_status ?? '',
    client_id: props.filters.client_id != null ? String(props.filters.client_id) : '',
    installation_id: props.filters.installation_id != null ? String(props.filters.installation_id) : '',
    search: props.filters.search ?? '',
    period: props.filters.period ?? '',
});

watch(
    () => props.filters,
    (filters) => {
        filterForm.status = filters.status ?? '';
        filterForm.installation_status = filters.installation_status ?? '';
        filterForm.client_id = filters.client_id != null ? String(filters.client_id) : '';
        filterForm.installation_id = filters.installation_id != null ? String(filters.installation_id) : '';
        filterForm.search = filters.search ?? '';
        filterForm.period = filters.period ?? '';
    },
    { deep: true },
);

const hasActiveFilters = computed(
    () => filterForm.status !== ''
        || filterForm.installation_status !== ''
        || filterForm.client_id !== ''
        || filterForm.installation_id !== ''
        || filterForm.search.trim() !== ''
        || filterForm.period !== '',
);

function buildFilterParams() {
    const params = {};

    if (filterForm.status !== '') {
        params.status = filterForm.status;
    }

    if (filterForm.installation_status !== '') {
        params.installation_status = filterForm.installation_status;
    }

    if (filterForm.client_id !== '') {
        params.client_id = filterForm.client_id;
    }

    if (filterForm.installation_id !== '') {
        params.installation_id = filterForm.installation_id;
    }

    if (filterForm.search.trim() !== '') {
        params.search = filterForm.search.trim();
    }

    if (filterForm.period !== '') {
        params.period = filterForm.period;
    }

    return params;
}

function applyFilters() {
    router.get('/subscriptions', buildFilterParams(), {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetFilters() {
    filterForm.status = '';
    filterForm.installation_status = '';
    filterForm.client_id = '';
    filterForm.installation_id = '';
    filterForm.search = '';
    filterForm.period = '';

    router.get('/subscriptions', {}, {
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

function formatCount(value) {
    return new Intl.NumberFormat('fr-FR').format(Number(value ?? 0));
}

const displayValue = displayAdminValue;
const formatDateTimeUtc = formatAdminDateTimeUtc;
const formatAmount = formatAdminAmount;

function periodDueStateLabel(state) {
    const labels = {
        future: 'Échéance future',
        due_7: 'J-7',
        due_3: 'J-3',
        due_1: 'Demain (J-1)',
        due_0: 'Aujourd’hui (J0)',
        expired: 'Période expirée',
    };

    return labels[state] ?? '—';
}

function periodDueStateBadgeClass(state) {
    const classes = {
        future: 'bg-gray-50 text-gray-800 ring-gray-200',
        due_7: 'bg-sky-50 text-sky-800 ring-sky-200',
        due_3: 'bg-amber-50 text-amber-900 ring-amber-200',
        due_1: 'bg-orange-50 text-orange-900 ring-orange-200',
        due_0: 'bg-red-50 text-red-800 ring-red-200',
        expired: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[state] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function filterLink(params) {
    const query = new URLSearchParams(params).toString();

    return query ? `/subscriptions?${query}` : '/subscriptions';
}

const page = usePage();
const createSubscriptionUrl = computed(() => props.admin_urls.create ?? '/subscriptions/create');
const deletingId = ref(null);
const subscriptionPendingDelete = ref(null);

function subscriptionEditUrl(subscription) {
    return subscription.edit_url ?? `/subscriptions/${subscription.id}/edit`;
}

function openDeleteConfirm(subscription) {
    subscriptionPendingDelete.value = subscription;
}

function cancelDelete() {
    if (deletingId.value !== null) {
        return;
    }

    subscriptionPendingDelete.value = null;
}

function confirmDelete() {
    if (!subscriptionPendingDelete.value || deletingId.value !== null) {
        return;
    }

    const item = subscriptionPendingDelete.value;
    deletingId.value = item.id;

    router.delete(`/subscriptions/${item.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
            subscriptionPendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Abonnements" />

    <AdminLayout>
        <div class="space-y-6">
            <div
                v-if="subscriptionPendingDelete"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Supprimer l'abonnement
                    </h2>
                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer cet abonnement ?
                    </p>
                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700"
                            :disabled="deletingId !== null"
                            @click="cancelDelete"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-60"
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
                        Abonnements
                    </h1>
                    <p class="mt-1 text-sm text-gray-600">
                        Portefeuille d’abonnements MKD-Pro.
                    </p>
                    <p
                        v-if="indicators.reference_date_utc"
                        class="mt-1 text-xs text-gray-500"
                    >
                        Échéances calculées au {{ indicators.reference_date_utc }} (UTC).
                    </p>
                </div>

                <Link
                    :href="createSubscriptionUrl"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800"
                >
                    Nouvelle souscription
                </Link>
            </div>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Indicateurs
                </h2>
                <div class="mt-6 grid gap-6 xl:grid-cols-2">
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <Link
                            :href="filterLink({})"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Total
                            </dt>
                            <dd class="text-lg font-semibold text-gray-900">
                                {{ formatCount(indicators.total) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ status: 'active' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Actifs
                            </dt>
                            <dd class="text-lg font-semibold text-gray-900">
                                {{ formatCount(indicators.active) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ status: 'grace_period' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                En grâce
                            </dt>
                            <dd class="text-lg font-semibold text-gray-900">
                                {{ formatCount(indicators.grace_period) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ status: 'suspended' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Suspendus
                            </dt>
                            <dd class="text-lg font-semibold text-gray-900">
                                {{ formatCount(indicators.suspended) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ status: 'terminated' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Terminés
                            </dt>
                            <dd class="text-lg font-semibold text-gray-900">
                                {{ formatCount(indicators.terminated) }}
                            </dd>
                        </Link>
                    </dl>
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <Link
                            :href="filterLink({ period: 'future', status: 'active' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Échéance future
                            </dt>
                            <dd class="text-lg font-semibold text-gray-900">
                                {{ formatCount(indicators.future) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ period: 'due_7', status: 'active' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                J-7
                            </dt>
                            <dd class="text-lg font-semibold text-sky-800">
                                {{ formatCount(indicators.due_in_seven_days) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ period: 'due_3', status: 'active' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                J-3
                            </dt>
                            <dd class="text-lg font-semibold text-amber-900">
                                {{ formatCount(indicators.due_in_three_days) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ period: 'due_1', status: 'active' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Demain
                            </dt>
                            <dd class="text-lg font-semibold text-orange-900">
                                {{ formatCount(indicators.due_tomorrow) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ period: 'due_0', status: 'active' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Aujourd’hui
                            </dt>
                            <dd class="text-lg font-semibold text-red-800">
                                {{ formatCount(indicators.due_today) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ period: 'expired', status: 'active' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Échéance dépassée
                            </dt>
                            <dd class="text-lg font-semibold text-gray-700">
                                {{ formatCount(indicators.period_expired) }}
                            </dd>
                        </Link>
                    </dl>
                </div>
            </section>

            <form
                class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:p-6"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label
                            for="filter-subscription-search"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Recherche
                        </label>
                        <input
                            id="filter-subscription-search"
                            v-model="filterForm.search"
                            type="search"
                            placeholder="Client, installation, sous-domaine, domaine…"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-subscription-status"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Statut abonnement
                        </label>
                        <select
                            id="filter-subscription-status"
                            v-model="filterForm.status"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                            <option value="">
                                Tous
                            </option>
                            <option value="active">
                                Actif
                            </option>
                            <option value="grace_period">
                                Période de grâce
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
                            for="filter-installation-status"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Statut installation
                        </label>
                        <select
                            id="filter-installation-status"
                            v-model="filterForm.installation_status"
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
                            for="filter-subscription-period"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Échéance (UTC)
                        </label>
                        <select
                            id="filter-subscription-period"
                            v-model="filterForm.period"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                            <option value="">
                                Toutes
                            </option>
                            <option value="future">
                                Échéance future
                            </option>
                            <option value="due_7">
                                J-7
                            </option>
                            <option value="due_3">
                                J-3
                            </option>
                            <option value="due_1">
                                Demain (J-1)
                            </option>
                            <option value="due_0">
                                Aujourd’hui (J0)
                            </option>
                            <option value="expired">
                                Période expirée
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="filter-client-id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            ID client
                        </label>
                        <input
                            id="filter-client-id"
                            v-model="filterForm.client_id"
                            type="number"
                            min="1"
                            placeholder="Ex. 12"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-installation-id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            ID installation
                        </label>
                        <input
                            id="filter-installation-id"
                            v-model="filterForm.installation_id"
                            type="number"
                            min="1"
                            placeholder="Ex. 34"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <button
                        type="submit"
                        class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800"
                    >
                        Appliquer
                    </button>
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50"
                        @click="resetFilters"
                    >
                        Réinitialiser
                    </button>
                </div>
            </form>

            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
                <div
                    v-if="!subscriptions.data.length"
                    class="p-8 text-center text-sm text-gray-500"
                >
                    Aucun abonnement ne correspond aux critères.
                </div>
                <div
                    v-else
                    class="overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Abonnement
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Client
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Installation
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Statut
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Montant
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Début
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Fin de période
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 xl:table-cell">
                                    État d’échéance
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="subscription in subscriptions.data"
                                :key="subscription.id"
                            >
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    #{{ subscription.id }}
                                </td>
                                <td class="px-4 py-3 text-gray-900">
                                    {{ displayValue(subscription.client?.name) }}
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 md:table-cell">
                                    {{ displayValue(subscription.installation?.name) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="subscriptionStatusBadgeClass(subscription.status)"
                                    >
                                        {{ subscriptionStatusLabel(subscription.status) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-900">
                                    {{ formatAmount(subscription.amount, subscription.currency) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ formatDateTimeUtc(subscription.starts_at) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-600">
                                    {{ formatDateTimeUtc(subscription.current_period_end) }}
                                </td>
                                <td class="hidden px-4 py-3 xl:table-cell">
                                    <span
                                        v-if="subscription.period_due_state"
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="periodDueStateBadgeClass(subscription.period_due_state)"
                                    >
                                        {{ periodDueStateLabel(subscription.period_due_state) }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-gray-400"
                                    >—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                        <Link
                                            :href="subscription.show_url ?? `/subscriptions/${subscription.id}`"
                                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                        >
                                            Voir
                                        </Link>
                                        <Link
                                            :href="subscriptionEditUrl(subscription)"
                                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                        >
                                            Modifier
                                        </Link>
                                        <button
                                            v-if="subscription.can_delete !== false"
                                            type="button"
                                            class="text-sm font-medium text-red-700 underline-offset-2 hover:text-red-900 hover:underline disabled:opacity-60"
                                            :disabled="deletingId === subscription.id"
                                            @click="openDeleteConfirm(subscription)"
                                        >
                                            {{ deletingId === subscription.id ? 'Suppression…' : 'Supprimer' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="subscriptions.links && subscriptions.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-gray-100 p-4"
                >
                    <button
                        v-for="(link, index) in subscriptions.links"
                        :key="`page-${index}`"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm disabled:text-gray-400"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="link.label"
                    />
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
