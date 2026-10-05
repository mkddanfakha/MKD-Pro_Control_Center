<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    formatAdminAmount,
    formatAdminDateTimeUtc,
    paymentStatusBadgeClass,
    paymentStatusLabel,
} from '@/lib/adminPresentation.js';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    payments: {
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
            payment_method: null,
            client_id: null,
            installation_id: null,
            subscription_id: null,
            search: null,
            date_from: null,
            date_to: null,
            overdue: null,
        }),
    },
});

const filterForm = reactive({
    status: props.filters.status ?? '',
    payment_method: props.filters.payment_method ?? '',
    client_id: props.filters.client_id != null ? String(props.filters.client_id) : '',
    installation_id: props.filters.installation_id != null ? String(props.filters.installation_id) : '',
    subscription_id: props.filters.subscription_id != null ? String(props.filters.subscription_id) : '',
    search: props.filters.search ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

watch(
    () => props.filters,
    (filters) => {
        filterForm.status = filters.status ?? '';
        filterForm.payment_method = filters.payment_method ?? '';
        filterForm.client_id = filters.client_id != null ? String(filters.client_id) : '';
        filterForm.installation_id = filters.installation_id != null ? String(filters.installation_id) : '';
        filterForm.subscription_id = filters.subscription_id != null ? String(filters.subscription_id) : '';
        filterForm.search = filters.search ?? '';
        filterForm.date_from = filters.date_from ?? '';
        filterForm.date_to = filters.date_to ?? '';
    },
    { deep: true },
);

const hasActiveFilters = computed(
    () => filterForm.status !== ''
        || filterForm.payment_method.trim() !== ''
        || filterForm.client_id !== ''
        || filterForm.installation_id !== ''
        || filterForm.subscription_id !== ''
        || filterForm.search.trim() !== ''
        || filterForm.date_from !== ''
        || filterForm.date_to !== '',
);

function buildFilterParams() {
    const params = {};

    if (filterForm.status !== '') {
        params.status = filterForm.status;
    }

    if (filterForm.payment_method.trim() !== '') {
        params.payment_method = filterForm.payment_method.trim();
    }

    if (filterForm.client_id !== '') {
        params.client_id = filterForm.client_id;
    }

    if (filterForm.installation_id !== '') {
        params.installation_id = filterForm.installation_id;
    }

    if (filterForm.subscription_id !== '') {
        params.subscription_id = filterForm.subscription_id;
    }

    if (filterForm.search.trim() !== '') {
        params.search = filterForm.search.trim();
    }

    if (filterForm.date_from !== '') {
        params.date_from = filterForm.date_from;
    }

    if (filterForm.date_to !== '') {
        params.date_to = filterForm.date_to;
    }

    return params;
}

function applyFilters() {
    router.get('/payments', buildFilterParams(), {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetFilters() {
    filterForm.status = '';
    filterForm.payment_method = '';
    filterForm.client_id = '';
    filterForm.installation_id = '';
    filterForm.subscription_id = '';
    filterForm.search = '';
    filterForm.date_from = '';
    filterForm.date_to = '';

    router.get('/payments', {}, {
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

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

const formatDateTimeUtc = formatAdminDateTimeUtc;
const formatAmount = formatAdminAmount;

function creditSummary(payment) {
    if (payment.credit_months_purchased == null) {
        return '—';
    }

    const months = payment.credit_months_purchased;
    const exhausted = payment.credit_exhausted_at
        ? ` · épuisé le ${formatDateTimeUtc(payment.credit_exhausted_at)}`
        : '';

    return `${months} mois${exhausted}`;
}

function filterLink(params) {
    const query = new URLSearchParams(params).toString();

    return query ? `/payments?${query}` : '/payments';
}

const page = usePage();
const createPaymentUrl = computed(() => props.admin_urls.create ?? '/payments/create');
const deletingId = ref(null);
const paymentPendingDelete = ref(null);

function paymentEditUrl(payment) {
    return payment.edit_url ?? `/payments/${payment.id}/edit`;
}

function openDeleteConfirm(payment) {
    paymentPendingDelete.value = payment;
}

function cancelDelete() {
    if (deletingId.value !== null) {
        return;
    }

    paymentPendingDelete.value = null;
}

function confirmDelete() {
    if (!paymentPendingDelete.value || deletingId.value !== null) {
        return;
    }

    const item = paymentPendingDelete.value;
    deletingId.value = item.id;

    router.delete(`/payments/${item.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
            paymentPendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Paiements" />

    <AdminLayout>
        <div class="space-y-6">
            <div
                v-if="paymentPendingDelete"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Supprimer le paiement
                    </h2>
                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer ce paiement ?
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
                            class="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
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
                        Paiements
                    </h1>
                    <p class="mt-1 text-sm text-gray-600">
                        Encaissements, crédits et suivi administratif des paiements.
                    </p>
                </div>

                <Link
                    :href="createPaymentUrl"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800"
                >
                    Enregistrer un paiement
                </Link>
            </div>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Indicateurs
                </h2>
                <div class="mt-6 grid gap-6 lg:grid-cols-2">
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
                            :href="filterLink({ status: 'paid' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Payés
                            </dt>
                            <dd class="text-lg font-semibold text-emerald-800">
                                {{ formatCount(indicators.paid) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ status: 'pending' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                En attente
                            </dt>
                            <dd class="text-lg font-semibold text-amber-900">
                                {{ formatCount(indicators.pending) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ status: 'failed' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Échoués
                            </dt>
                            <dd class="text-lg font-semibold text-red-800">
                                {{ formatCount(indicators.failed) }}
                            </dd>
                        </Link>
                        <Link
                            :href="filterLink({ status: 'refunded' })"
                            class="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100 hover:bg-gray-100"
                        >
                            <dt class="text-xs text-gray-500">
                                Remboursés
                            </dt>
                            <dd class="text-lg font-semibold text-gray-700">
                                {{ formatCount(indicators.refunded) }}
                            </dd>
                        </Link>
                    </dl>
                    <dl class="grid gap-3">
                        <div class="rounded-lg bg-emerald-50 px-4 py-3 ring-1 ring-emerald-100">
                            <dt class="text-xs font-medium text-emerald-800">
                                Montant total payé
                            </dt>
                            <dd class="mt-1 text-xl font-bold text-emerald-900">
                                {{ formatAmount(indicators.total_paid_amount, 'XOF') }}
                            </dd>
                        </div>
                        <div class="rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-amber-100">
                            <dt class="text-xs font-medium text-amber-900">
                                Montant total en attente
                            </dt>
                            <dd class="mt-1 text-xl font-bold text-amber-950">
                                {{ formatAmount(indicators.total_pending_amount, 'XOF') }}
                            </dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-4 py-3 ring-1 ring-gray-200">
                            <dt class="text-xs font-medium text-gray-600">
                                Montant total remboursé
                            </dt>
                            <dd class="mt-1 text-xl font-bold text-gray-800">
                                {{ formatAmount(indicators.total_refunded_amount, 'XOF') }}
                            </dd>
                        </div>
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
                            for="filter-payment-search"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Recherche
                        </label>
                        <input
                            id="filter-payment-search"
                            v-model="filterForm.search"
                            type="search"
                            placeholder="Client, installation, sous-domaine, référence, n° paiement…"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-payment-status"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Statut
                        </label>
                        <select
                            id="filter-payment-status"
                            v-model="filterForm.status"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                            <option value="">
                                Tous
                            </option>
                            <option value="paid">
                                Payé
                            </option>
                            <option value="pending">
                                En attente
                            </option>
                            <option value="failed">
                                Échoué
                            </option>
                            <option value="refunded">
                                Remboursé
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="filter-payment-method"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Mode de paiement
                        </label>
                        <input
                            id="filter-payment-method"
                            v-model="filterForm.payment_method"
                            type="text"
                            placeholder="Valeur exacte"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-date-from"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Date du (payé le / créé le)
                        </label>
                        <input
                            id="filter-date-from"
                            v-model="filterForm.date_from"
                            type="date"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-date-to"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Date au
                        </label>
                        <input
                            id="filter-date-to"
                            v-model="filterForm.date_to"
                            type="date"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
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
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-subscription-id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            ID abonnement
                        </label>
                        <input
                            id="filter-subscription-id"
                            v-model="filterForm.subscription_id"
                            type="number"
                            min="1"
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
                    v-if="!payments.data.length"
                    class="p-8 text-center text-sm text-gray-500"
                >
                    Aucun paiement ne correspond aux critères.
                </div>
                <div
                    v-else
                    class="overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Paiement
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Client
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Installation
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Abonnement
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Statut
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Montant
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Mode
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Payé le
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 xl:table-cell">
                                    Réf.
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 xl:table-cell">
                                    Crédit
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="payment in payments.data"
                                :key="payment.id"
                            >
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    #{{ payment.id }}
                                    <span class="block text-xs font-normal text-gray-500">
                                        {{ formatDateTimeUtc(payment.created_at) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-900">
                                    <Link
                                        v-if="payment.client_show_url"
                                        :href="payment.client_show_url"
                                        class="font-medium hover:underline"
                                    >
                                        {{ displayValue(payment.client?.name) }}
                                    </Link>
                                    <span v-else>{{ displayValue(payment.client?.name) }}</span>
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 md:table-cell">
                                    <Link
                                        v-if="payment.installation_show_url"
                                        :href="payment.installation_show_url"
                                        class="font-medium text-gray-900 hover:underline"
                                    >
                                        {{ displayValue(payment.installation?.name) }}
                                    </Link>
                                    <span v-else>{{ displayValue(payment.installation?.name) }}</span>
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 lg:table-cell">
                                    <Link
                                        v-if="payment.subscription_show_url"
                                        :href="payment.subscription_show_url"
                                        class="font-medium text-gray-900 hover:underline"
                                    >
                                        #{{ payment.subscription.id }}
                                    </Link>
                                    <span v-else-if="payment.subscription">#{{ payment.subscription.id }}</span>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="paymentStatusBadgeClass(payment.status)"
                                    >
                                        {{ paymentStatusLabel(payment.status) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-900">
                                    {{ formatAmount(payment.amount, payment.currency) }}
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 md:table-cell">
                                    {{ displayValue(payment.payment_method) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ formatDateTimeUtc(payment.paid_at) }}
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 xl:table-cell">
                                    {{ displayValue(payment.reference) }}
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 xl:table-cell">
                                    {{ creditSummary(payment) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                                        <Link
                                            v-if="payment.show_url"
                                            :href="payment.show_url"
                                            class="font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                        >
                                            Voir
                                        </Link>
                                        <Link
                                            :href="paymentEditUrl(payment)"
                                            class="font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline"
                                        >
                                            Modifier
                                        </Link>
                                        <button
                                            v-if="payment.can_delete !== false"
                                            type="button"
                                            class="font-medium text-red-700 underline-offset-2 hover:text-red-900 hover:underline disabled:opacity-60"
                                            :disabled="deletingId === payment.id"
                                            @click="openDeleteConfirm(payment)"
                                        >
                                            {{ deletingId === payment.id ? 'Suppression…' : 'Supprimer' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="payments.links && payments.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-gray-100 p-4"
                >
                    <button
                        v-for="(link, index) in payments.links"
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
