<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    auditLogs: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});

const selectedLog = ref(null);

const filterForm = reactive({
    action: props.filters.action ?? '',
    result: props.filters.result ?? '',
    user_id: props.filters.user_id ?? '',
    auditable_type: props.filters.auditable_type ?? '',
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
    }).format(new Date(value));
}

function userLabel(user) {
    if (!user) {
        return 'Système';
    }

    if (user.name && String(user.name).trim() !== '') {
        return user.name;
    }

    if (user.email && String(user.email).trim() !== '') {
        return user.email;
    }

    return 'Système';
}

function formatAuditableType(type) {
    if (!type || String(type).trim() === '') {
        return '—';
    }

    const normalized = String(type);

    if (normalized.includes('\\')) {
        const parts = normalized.split('\\');

        return parts[parts.length - 1];
    }

    return normalized;
}

function auditableObjectLabel(log) {
    const typeLabel = formatAuditableType(log.auditable_type);

    if (log.auditable_id === null || log.auditable_id === undefined) {
        return typeLabel;
    }

    if (typeLabel === '—') {
        return `#${log.auditable_id}`;
    }

    return `${typeLabel} #${log.auditable_id}`;
}

function resultLabel(result) {
    if (result === 'success') {
        return 'Succès';
    }

    if (result === 'failure') {
        return 'Échec';
    }

    return result ?? '—';
}

function resultBadgeClass(result) {
    if (result === 'success') {
        return 'bg-green-50 text-green-800 ring-green-200';
    }

    if (result === 'failure') {
        return 'bg-red-50 text-red-800 ring-red-200';
    }

    return 'bg-gray-100 text-gray-600 ring-gray-200';
}

function formatJsonBlock(value) {
    if (value === null || value === undefined) {
        return 'Aucune valeur';
    }

    if (typeof value === 'object' && Object.keys(value).length === 0) {
        return 'Aucune valeur';
    }

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return 'Aucune valeur';
    }
}

function buildFilterParams() {
    const params = {};

    if (filterForm.action.trim() !== '') {
        params.action = filterForm.action.trim();
    }

    if (filterForm.result !== '') {
        params.result = filterForm.result;
    }

    if (filterForm.user_id !== '' && filterForm.user_id !== null) {
        params.user_id = filterForm.user_id;
    }

    if (filterForm.auditable_type.trim() !== '') {
        params.auditable_type = filterForm.auditable_type.trim();
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
    router.get('/audit-logs', buildFilterParams(), {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetFilters() {
    filterForm.action = '';
    filterForm.result = '';
    filterForm.user_id = '';
    filterForm.auditable_type = '';
    filterForm.date_from = '';
    filterForm.date_to = '';

    router.get('/audit-logs', {}, {
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

function openDetail(log) {
    selectedLog.value = log;
}

function closeDetail() {
    selectedLog.value = null;
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

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}
</script>

<template>
    <Head title="Journal d'audit" />

    <AdminLayout>
        <div>
            <div
                v-if="selectedLog"
                class="fixed inset-0 z-50 overflow-y-auto bg-black/40"
            >
                <div
                    class="flex min-h-full items-center justify-center p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="audit-detail-title"
                    @click.self="closeDetail"
                >
                    <div
                        class="flex w-full max-w-4xl max-h-[calc(100vh-2rem)] flex-col overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200"
                        @click.stop
                    >
                        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 px-6 py-4">
                            <h2
                                id="audit-detail-title"
                                class="text-lg font-semibold text-gray-900"
                            >
                                Détail de l'audit #{{ selectedLog.id }}
                            </h2>

                            <button
                                type="button"
                                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                aria-label="Fermer"
                                @click="closeDetail"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                            <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Identifiant
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ selectedLog.id }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Date
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(selectedLog.created_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Utilisateur
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ userLabel(selectedLog.user) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Action
                            </dt>
                            <dd class="mt-1 break-all text-sm text-gray-900">
                                {{ selectedLog.action }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Résultat
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="resultBadgeClass(selectedLog.result)"
                                >
                                    {{ resultLabel(selectedLog.result) }}
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Objet audité
                            </dt>
                            <dd class="mt-1 break-all text-sm text-gray-900">
                                {{ auditableObjectLabel(selectedLog) }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Adresse IP
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(selectedLog.ip_address) }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                User-Agent
                            </dt>
                            <dd class="mt-1 break-all text-sm text-gray-900">
                                {{ displayValue(selectedLog.user_agent) }}
                            </dd>
                        </div>

                        <div
                            v-if="selectedLog.error_message"
                            class="sm:col-span-2"
                        >
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Message d'erreur
                            </dt>
                            <dd class="mt-1 break-all text-sm text-red-800">
                                {{ selectedLog.error_message }}
                            </dd>
                        </div>
                            </dl>

                            <div class="mt-6 space-y-4">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        Anciennes valeurs
                                    </h3>
                                    <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-800 ring-1 ring-gray-200">{{ formatJsonBlock(selectedLog.old_values) }}</pre>
                                </div>

                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        Nouvelles valeurs
                                    </h3>
                                    <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-800 ring-1 ring-gray-200">{{ formatJsonBlock(selectedLog.new_values) }}</pre>
                                </div>
                            </div>
                        </div>

                        <div class="flex shrink-0 justify-end border-t border-gray-200 px-6 py-4">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                @click="closeDetail"
                            >
                                Fermer
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    Journal d'audit
                </h1>

                <p class="mt-1 text-sm text-gray-500">
                    Historique des opérations administratives et système.
                </p>
            </div>

            <form
                class="mt-8 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:p-6"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label
                            for="filter-action"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Action
                        </label>
                        <input
                            id="filter-action"
                            v-model="filterForm.action"
                            type="text"
                            autocomplete="off"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                            placeholder="client.created"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-result"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Résultat
                        </label>
                        <select
                            id="filter-result"
                            v-model="filterForm.result"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                            <option value="">
                                Tous
                            </option>
                            <option value="success">
                                Succès
                            </option>
                            <option value="failure">
                                Échec
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            for="filter-user-id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Utilisateur
                        </label>
                        <input
                            id="filter-user-id"
                            v-model="filterForm.user_id"
                            type="number"
                            min="1"
                            step="1"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                            placeholder="ID utilisateur"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-auditable-type"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Type d'objet
                        </label>
                        <input
                            id="filter-auditable-type"
                            v-model="filterForm.auditable_type"
                            type="text"
                            autocomplete="off"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                            placeholder="App\Models\Client"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-date-from"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Date de début
                        </label>
                        <input
                            id="filter-date-from"
                            v-model="filterForm.date_from"
                            type="date"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>

                    <div>
                        <label
                            for="filter-date-to"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Date de fin
                        </label>
                        <input
                            id="filter-date-to"
                            v-model="filterForm.date_to"
                            type="date"
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        >
                    </div>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
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
                v-if="!auditLogs.data.length"
                class="mt-8 rounded-xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center shadow-sm"
            >
                <h2 class="text-lg font-semibold text-gray-900">
                    Aucun enregistrement
                </h2>

                <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                    Aucune entrée d'audit ne correspond aux critères sélectionnés.
                </p>
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
                                        Date
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                    >
                                        Utilisateur
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                    >
                                        Action
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                    >
                                        Objet
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                    >
                                        Résultat
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
                                    v-for="log in auditLogs.data"
                                    :key="log.id"
                                >
                                    <td class="whitespace-nowrap px-4 py-4 text-gray-700 sm:px-6">
                                        {{ formatDateTime(log.created_at) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-gray-900 sm:px-6">
                                        {{ userLabel(log.user) }}
                                    </td>
                                    <td class="max-w-[12rem] truncate px-4 py-4 font-mono text-xs text-gray-800 sm:max-w-xs sm:px-6">
                                        {{ log.action }}
                                    </td>
                                    <td class="hidden max-w-xs truncate px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                        {{ auditableObjectLabel(log) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 sm:px-6">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            :class="resultBadgeClass(log.result)"
                                        >
                                            {{ resultLabel(log.result) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 sm:px-6">
                                        <button
                                            type="button"
                                            class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            @click="openDetail(log)"
                                        >
                                            Détail
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <nav
                    v-if="auditLogs.last_page > 1"
                    class="flex flex-wrap items-center justify-center gap-1"
                    aria-label="Pagination du journal d'audit"
                >
                    <template
                        v-for="(link, index) in auditLogs.links"
                        :key="`${link.label}-${index}`"
                    >
                        <span
                            v-if="!link.url"
                            class="inline-flex min-w-[2.25rem] items-center justify-center rounded-lg px-3 py-2 text-sm text-gray-400"
                            :class="{ 'font-medium': link.active }"
                            aria-disabled="true"
                        >
                            {{ paginationLabel(link) }}
                        </span>
                        <button
                            v-else
                            type="button"
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
                            @click="visitPagination(link.url)"
                        >
                            {{ paginationLabel(link) }}
                        </button>
                    </template>
                </nav>
            </div>
        </div>
    </AdminLayout>
</template>
