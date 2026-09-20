<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    subscriptions: {
        type: Object,
        required: true,
    },
});

const page = usePage();

const deletingId = ref(null);
const subscriptionPendingDelete = ref(null);

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

function formatAmount(amount, currency) {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount));

    return `${formatted} ${currency ?? 'XOF'}`;
}

function statusLabel(status) {
    const labels = {
        active: 'Actif',
        grace_period: 'Période de grâce',
        suspended: 'Suspendu',
        terminated: 'Terminé',
    };

    return labels[status] ?? status;
}

function statusBadgeClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        grace_period: 'bg-amber-50 text-amber-900 ring-amber-200',
        suspended: 'bg-orange-50 text-orange-900 ring-orange-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
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

    const subscription = subscriptionPendingDelete.value;
    deletingId.value = subscription.id;

    router.delete(`/subscriptions/${subscription.id}`, {
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
        <div>
            <div
                v-if="page.flash.success"
                class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800"
                role="status"
            >
                {{ page.flash.success }}
            </div>

            <div
                v-if="subscriptionPendingDelete"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-subscription-title"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2
                        id="delete-subscription-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Supprimer l'abonnement
                    </h2>

                    <p class="mt-3 text-sm text-gray-600">
                        Êtes-vous sûr de vouloir supprimer cet abonnement ?
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
                        Abonnements
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Gérez les abonnements de vos installations MKD-Pro.
                    </p>
                </div>

                <Link
                    href="/subscriptions/create"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Nouvel abonnement
                </Link>
            </div>

            <div
                v-if="!subscriptions.data.length"
                class="mt-8 rounded-xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center shadow-sm"
            >
                <h2 class="text-lg font-semibold text-gray-900">
                    Aucun abonnement
                </h2>

                <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                    Aucun abonnement n'a encore été créé pour vos installations.
                </p>

                <Link
                    href="/subscriptions/create"
                    class="mt-6 inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Créer le premier abonnement
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
                                        class="hidden px-4 py-3 font-semibold text-gray-700 sm:table-cell sm:px-6"
                                    >
                                        Montant
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                    >
                                        Statut
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                    >
                                        Début de période
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6"
                                    >
                                        Fin de période
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
                                    v-for="subscription in subscriptions.data"
                                    :key="subscription.id"
                                >
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="font-medium text-gray-900">
                                            {{ displayValue(subscription.installation?.name) }}
                                        </div>
                                        <div
                                            v-if="subscription.installation?.subdomain"
                                            class="mt-0.5 text-xs text-gray-500"
                                        >
                                            {{ subscription.installation.subdomain }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="font-medium text-gray-900">
                                            {{ displayValue(subscription.installation?.client?.company_name) }}
                                        </div>
                                        <div
                                            v-if="subscription.installation?.client?.contact_name"
                                            class="mt-0.5 text-xs text-gray-500"
                                        >
                                            {{ subscription.installation.client.contact_name }}
                                        </div>
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-900 sm:table-cell sm:px-6">
                                        {{ formatAmount(subscription.amount, subscription.currency) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 sm:px-6">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            :class="statusBadgeClass(subscription.status)"
                                        >
                                            {{ statusLabel(subscription.status) }}
                                        </span>
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                        {{ formatDate(subscription.current_period_start) }}
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 lg:table-cell sm:px-6">
                                        {{ formatDate(subscription.current_period_end) }}
                                    </td>
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <Link
                                                :href="`/subscriptions/${subscription.id}`"
                                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            >
                                                Voir
                                            </Link>
                                            <Link
                                                :href="`/subscriptions/${subscription.id}/edit`"
                                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            >
                                                Modifier
                                            </Link>
                                            <button
                                                type="button"
                                                class="text-sm font-medium text-red-700 underline-offset-2 hover:text-red-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="deletingId === subscription.id"
                                                :aria-busy="deletingId === subscription.id"
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
                </div>

                <nav
                    v-if="subscriptions.last_page > 1"
                    class="flex flex-wrap items-center justify-center gap-1"
                    aria-label="Pagination des abonnements"
                >
                    <template
                        v-for="(link, index) in subscriptions.links"
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
