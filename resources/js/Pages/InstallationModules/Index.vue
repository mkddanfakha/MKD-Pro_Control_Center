<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    installationModules: {
        type: Object,
        required: true,
    },
});

const page = usePage();

const deletingId = ref(null);
const assignmentPendingDelete = ref(null);

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

function statusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
    };

    return labels[status] ?? status;
}

function statusBadgeClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        inactive: 'bg-gray-100 text-gray-600 ring-gray-200',
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

function openDeleteConfirm(assignment) {
    assignmentPendingDelete.value = assignment;
}

function cancelDelete() {
    if (deletingId.value !== null) {
        return;
    }

    assignmentPendingDelete.value = null;
}

function confirmDelete() {
    if (!assignmentPendingDelete.value || deletingId.value !== null) {
        return;
    }

    const assignment = assignmentPendingDelete.value;
    deletingId.value = assignment.id;

    router.delete(`/installation-modules/${assignment.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
            assignmentPendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Affectations des modules" />

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
                v-if="page.flash.error"
                class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"
                role="alert"
            >
                {{ page.flash.error }}
            </div>

            <div
                v-if="assignmentPendingDelete"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-assignment-title"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2
                        id="delete-assignment-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Supprimer l'affectation
                    </h2>

                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer cette affectation de module ?
                    </p>

                    <p
                        v-if="assignmentPendingDelete.installation?.name && assignmentPendingDelete.module?.name"
                        class="mt-2 text-sm font-medium text-gray-900"
                    >
                        {{ assignmentPendingDelete.installation.name }}
                        —
                        {{ assignmentPendingDelete.module.name }}
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
                        Affectations des modules
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Gérez les modules activés sur chaque installation MKD-Pro.
                    </p>
                </div>

                <Link
                    href="/installation-modules/create"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    + Affecter un module
                </Link>
            </div>

            <div
                v-if="!installationModules.data.length"
                class="mt-8 rounded-xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center shadow-sm"
            >
                <h2 class="text-lg font-semibold text-gray-900">
                    Aucune affectation de module pour le moment.
                </h2>

                <Link
                    href="/installation-modules/create"
                    class="mt-6 inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    + Affecter un module
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
                                        class="hidden px-4 py-3 font-semibold text-gray-700 sm:table-cell sm:px-6"
                                    >
                                        Client
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 font-semibold text-gray-700 sm:px-6"
                                    >
                                        Module
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                    >
                                        Version
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
                                        Activation
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
                                    v-for="assignment in installationModules.data"
                                    :key="assignment.id"
                                >
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="font-medium text-gray-900">
                                            {{ displayValue(assignment.installation?.name) }}
                                        </div>
                                        <div
                                            v-if="assignment.installation?.subdomain"
                                            class="mt-0.5 text-xs text-gray-500"
                                        >
                                            {{ assignment.installation.subdomain }}
                                        </div>
                                        <div
                                            class="mt-1 text-xs text-gray-500 sm:hidden"
                                        >
                                            {{ displayValue(assignment.installation?.client?.company_name) }}
                                        </div>
                                    </td>
                                    <td class="hidden px-4 py-4 sm:table-cell sm:px-6">
                                        <div class="font-medium text-gray-900">
                                            {{ displayValue(assignment.installation?.client?.company_name) }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="font-medium text-gray-900">
                                            {{ displayValue(assignment.module?.name) }}
                                        </div>
                                        <div
                                            v-if="assignment.module?.slug"
                                            class="mt-0.5 font-mono text-xs text-gray-500"
                                        >
                                            {{ assignment.module.slug }}
                                        </div>
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                        {{ displayValue(assignment.version) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 sm:px-6">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            :class="statusBadgeClass(assignment.status)"
                                        >
                                            {{ statusLabel(assignment.status) }}
                                        </span>
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 lg:table-cell sm:px-6">
                                        {{ formatDate(assignment.activated_at) }}
                                    </td>
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <Link
                                                :href="`/installation-modules/${assignment.id}`"
                                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            >
                                                Voir
                                            </Link>
                                            <Link
                                                :href="`/installation-modules/${assignment.id}/edit`"
                                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            >
                                                Modifier
                                            </Link>
                                            <button
                                                type="button"
                                                class="text-sm font-medium text-red-700 underline-offset-2 hover:text-red-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="deletingId === assignment.id"
                                                :aria-busy="deletingId === assignment.id"
                                                @click="openDeleteConfirm(assignment)"
                                            >
                                                {{ deletingId === assignment.id ? 'Suppression…' : 'Supprimer' }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <nav
                    v-if="installationModules.last_page > 1"
                    class="flex flex-wrap items-center justify-center gap-1"
                    aria-label="Pagination des affectations de modules"
                >
                    <template
                        v-for="(link, index) in installationModules.links"
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
