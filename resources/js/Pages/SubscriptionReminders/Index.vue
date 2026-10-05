<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { reminderStatusBadgeClass, reminderStatusLabel } from '@/lib/adminPresentation.js';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    reminders: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

const selectedReminder = ref(null);

const filterForm = reactive({
    installation_id: props.filters.installation_id ?? '',
    subscription_id: props.filters.subscription_id ?? '',
    status: props.filters.status ?? '',
    threshold_days: props.filters.threshold_days ?? '',
    search: props.filters.search ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
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

const statusLabel = reminderStatusLabel;
const statusBadgeClass = reminderStatusBadgeClass;

function thresholdLabel(days) {
    if (days === 0) {
        return 'J0';
    }

    return `J-${days}`;
}

function buildFilterParams() {
    const params = {};

    if (filterForm.installation_id !== '' && filterForm.installation_id != null) {
        params.installation_id = filterForm.installation_id;
    }

    if (filterForm.subscription_id !== '' && filterForm.subscription_id != null) {
        params.subscription_id = filterForm.subscription_id;
    }

    if (filterForm.status !== '') {
        params.status = filterForm.status;
    }

    if (filterForm.threshold_days !== '') {
        params.threshold_days = filterForm.threshold_days;
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
    router.get('/subscription-reminders', buildFilterParams(), {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetFilters() {
    filterForm.installation_id = '';
    filterForm.subscription_id = '';
    filterForm.status = '';
    filterForm.threshold_days = '';
    filterForm.search = '';
    filterForm.date_from = '';
    filterForm.date_to = '';

    router.get('/subscription-reminders', {}, {
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

function openDetail(reminder) {
    selectedReminder.value = reminder;
}

function closeDetail() {
    selectedReminder.value = null;
}

function installationLabel(reminder) {
    if (!reminder.installation_name) {
        return reminder.installation_id ? `#${reminder.installation_id}` : '—';
    }

    return reminder.installation_name;
}
</script>

<template>
    <Head title="Rappels d'abonnement" />

    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    Rappels d'abonnement
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    Consultation read-only des rappels d'échéance (aucun envoi depuis cette page).
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Total
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">
                        {{ stats.total }}
                    </div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Détectés
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-amber-900">
                        {{ stats.detected }}
                    </div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Envoyés
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-green-800">
                        {{ stats.sent }}
                    </div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        Échecs
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-red-800">
                        {{ stats.failed }}
                    </div>
                </div>
            </div>

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <form
                    class="grid gap-4 md:grid-cols-2 lg:grid-cols-4"
                    @submit.prevent="applyFilters"
                >
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Recherche installation</label>
                        <input
                            v-model="filterForm.search"
                            type="search"
                            placeholder="Nom ou sous-domaine"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Installation (ID)</label>
                        <input
                            v-model="filterForm.installation_id"
                            type="number"
                            min="1"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Abonnement (ID)</label>
                        <input
                            v-model="filterForm.subscription_id"
                            type="number"
                            min="1"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Statut</label>
                        <select
                            v-model="filterForm.status"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                            <option value="">
                                Tous
                            </option>
                            <option value="detected">
                                Détecté
                            </option>
                            <option value="sent">
                                Envoyé
                            </option>
                            <option value="failed">
                                Échec
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Seuil (jours)</label>
                        <select
                            v-model="filterForm.threshold_days"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                            <option value="">
                                Tous
                            </option>
                            <option value="7">
                                J-7
                            </option>
                            <option value="3">
                                J-3
                            </option>
                            <option value="1">
                                J-1
                            </option>
                            <option value="0">
                                J0
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Créé du</label>
                        <input
                            v-model="filterForm.date_from"
                            type="date"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Créé au</label>
                        <input
                            v-model="filterForm.date_to"
                            type="date"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                        >
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            type="submit"
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                        >
                            Filtrer
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="resetFilters"
                        >
                            Réinitialiser
                        </button>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">
                                    Installation
                                </th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">
                                    Échéance
                                </th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">
                                    Seuil
                                </th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">
                                    Statut
                                </th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">
                                    Détecté le
                                </th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">
                                    Envoyé le
                                </th>
                                <th class="px-4 py-3 text-right font-medium text-gray-600">
                                    Détail
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="reminder in reminders.data"
                                :key="reminder.id"
                                class="hover:bg-gray-50"
                            >
                                <td class="px-4 py-3 text-gray-900">
                                    <Link
                                        v-if="reminder.installation_show_url"
                                        :href="reminder.installation_show_url"
                                        class="font-medium hover:underline"
                                    >
                                        {{ installationLabel(reminder) }}
                                    </Link>
                                    <span v-else>{{ installationLabel(reminder) }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{ formatPeriodEnd(reminder.period_end) }}
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{ thresholdLabel(reminder.threshold_days) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="statusBadgeClass(reminder.status)"
                                    >
                                        {{ statusLabel(reminder.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{ formatDateTime(reminder.detected_at) }}
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{ formatDateTime(reminder.sent_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex flex-col items-end gap-1">
                                        <Link
                                            v-if="reminder.show_url"
                                            :href="reminder.show_url"
                                            class="text-sm font-medium text-gray-900 underline-offset-2 hover:underline"
                                        >
                                            Ouvrir la fiche
                                        </Link>
                                        <button
                                            type="button"
                                            class="text-sm font-medium text-gray-600 hover:underline"
                                            @click="openDetail(reminder)"
                                        >
                                            Aperçu rapide
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="reminders.data.length === 0">
                                <td
                                    colspan="7"
                                    class="px-4 py-8 text-center text-gray-500"
                                >
                                    Aucun rappel ne correspond aux critères.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="reminders.links && reminders.links.length > 3"
                    class="flex flex-wrap items-center justify-center gap-1 border-t border-gray-200 px-4 py-3"
                >
                    <button
                        v-for="(link, index) in reminders.links"
                        :key="index"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>

        <div
            v-if="selectedReminder"
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
                            Rappel #{{ selectedReminder.id }}
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
                                Installation
                            </dt>
                            <dd class="text-gray-900">
                                <Link
                                    v-if="selectedReminder.installation_show_url"
                                    :href="selectedReminder.installation_show_url"
                                    class="font-medium hover:underline"
                                >
                                    {{ installationLabel(selectedReminder) }}
                                </Link>
                                <span v-else>{{ installationLabel(selectedReminder) }}</span>
                                <span
                                    v-if="selectedReminder.installation_subdomain"
                                    class="text-gray-500"
                                >({{ selectedReminder.installation_subdomain }})</span>
                            </dd>
                        </div>
                        <div v-if="selectedReminder.client_show_url">
                            <dt class="text-xs uppercase text-gray-500">
                                Client
                            </dt>
                            <dd>
                                <Link
                                    :href="selectedReminder.client_show_url"
                                    class="font-medium text-gray-900 hover:underline"
                                >
                                    {{ selectedReminder.client_name ?? 'Voir le client' }}
                                </Link>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Abonnement
                            </dt>
                            <dd>
                                <Link
                                    :href="selectedReminder.subscription_show_url ?? `/subscriptions/${selectedReminder.subscription_id}`"
                                    class="font-medium text-gray-900 hover:underline"
                                >
                                    Voir l'abonnement (#{{ selectedReminder.subscription_id }})
                                </Link>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Seuil
                            </dt>
                            <dd>{{ thresholdLabel(selectedReminder.threshold_days) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Planifié pour (UTC)
                            </dt>
                            <dd>{{ formatDateTime(selectedReminder.scheduled_for) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Échéance période (UTC)
                            </dt>
                            <dd>{{ formatPeriodEnd(selectedReminder.period_end) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Statut
                            </dt>
                            <dd>
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="statusBadgeClass(selectedReminder.status)"
                                >
                                    {{ statusLabel(selectedReminder.status) }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Détecté le (UTC)
                            </dt>
                            <dd>{{ formatDateTime(selectedReminder.detected_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">
                                Envoyé le (UTC)
                            </dt>
                            <dd>{{ formatDateTime(selectedReminder.sent_at) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
