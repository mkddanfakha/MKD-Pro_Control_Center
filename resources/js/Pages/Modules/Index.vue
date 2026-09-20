<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    modules: {
        type: Object,
        required: true,
    },
});

const page = usePage();

const deletingId = ref(null);
const modulePendingDelete = ref(null);

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

function openDeleteConfirm(module) {
    modulePendingDelete.value = module;
}

function cancelDelete() {
    if (deletingId.value !== null) {
        return;
    }

    modulePendingDelete.value = null;
}

function confirmDelete() {
    if (!modulePendingDelete.value || deletingId.value !== null) {
        return;
    }

    const moduleItem = modulePendingDelete.value;
    deletingId.value = moduleItem.id;

    router.delete(`/modules/${moduleItem.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
            modulePendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Modules" />

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
                v-if="modulePendingDelete"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-module-title"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2
                        id="delete-module-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Supprimer le module
                    </h2>

                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer ce module ?
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
                        Modules
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Catalogue des modules disponibles dans MKD-Pro.
                    </p>
                </div>

                <Link
                    href="/modules/create"
                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Nouveau module
                </Link>
            </div>

            <div
                v-if="!modules.data.length"
                class="mt-8 rounded-xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center shadow-sm"
            >
                <h2 class="text-lg font-semibold text-gray-900">
                    Aucun module enregistré.
                </h2>

                <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                    Ajoutez les modules du catalogue MKD-Pro pour les proposer aux installations.
                </p>

                <Link
                    href="/modules/create"
                    class="mt-6 inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Créer le premier module
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
                                        Module
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell sm:px-6"
                                    >
                                        Slug
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 sm:table-cell sm:px-6"
                                    >
                                        Version
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell sm:px-6"
                                    >
                                        Prix
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
                                        Ordre
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
                                    v-for="module in modules.data"
                                    :key="module.id"
                                >
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="font-medium text-gray-900">
                                            {{ displayValue(module.name) }}
                                        </div>
                                        <div
                                            v-if="module.description && String(module.description).trim() !== ''"
                                            class="mt-0.5 line-clamp-2 text-xs text-gray-500"
                                        >
                                            {{ module.description }}
                                        </div>
                                    </td>
                                    <td class="hidden px-4 py-4 font-mono text-xs text-gray-600 md:table-cell sm:px-6">
                                        {{ displayValue(module.slug) }}
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 sm:table-cell sm:px-6">
                                        {{ displayValue(module.version) }}
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-900 lg:table-cell sm:px-6">
                                        {{ formatAmount(module.price, module.currency) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 sm:px-6">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            :class="statusBadgeClass(module.status)"
                                        >
                                            {{ statusLabel(module.status) }}
                                        </span>
                                    </td>
                                    <td class="hidden whitespace-nowrap px-4 py-4 text-gray-600 md:table-cell sm:px-6">
                                        {{ module.sort_order ?? '—' }}
                                    </td>
                                    <td class="px-4 py-4 sm:px-6">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <Link
                                                :href="`/modules/${module.id}`"
                                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            >
                                                Voir
                                            </Link>
                                            <Link
                                                :href="`/modules/${module.id}/edit`"
                                                class="text-sm font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                                            >
                                                Modifier
                                            </Link>
                                            <button
                                                type="button"
                                                class="text-sm font-medium text-red-700 underline-offset-2 hover:text-red-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="deletingId === module.id"
                                                :aria-busy="deletingId === module.id"
                                                @click="openDeleteConfirm(module)"
                                            >
                                                {{ deletingId === module.id ? 'Suppression…' : 'Supprimer' }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <nav
                    v-if="modules.last_page > 1"
                    class="flex flex-wrap items-center justify-center gap-1"
                    aria-label="Pagination des modules"
                >
                    <template
                        v-for="(link, index) in modules.links"
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
