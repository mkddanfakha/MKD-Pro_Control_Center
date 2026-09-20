<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    module: {
        type: Object,
        required: true,
    },
});

const page = usePage();

const showDeleteConfirm = ref(false);
const deleting = ref(false);

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function formatDateTime(value) {
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

function openDeleteConfirm() {
    showDeleteConfirm.value = true;
}

function cancelDelete() {
    if (deleting.value) {
        return;
    }

    showDeleteConfirm.value = false;
}

function confirmDelete() {
    if (deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(`/modules/${props.module.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            showDeleteConfirm.value = false;
        },
    });
}
</script>

<template>
    <Head :title="module.name ? `Module — ${module.name}` : 'Module'" />

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
                v-if="showDeleteConfirm"
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
                            :disabled="deleting"
                            @click="cancelDelete"
                        >
                            Annuler
                        </button>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="deleting"
                            :aria-busy="deleting"
                            @click="confirmDelete"
                        >
                            {{ deleting ? 'Suppression…' : 'Supprimer' }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        {{ displayValue(module.name) }}
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Détail du module
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        href="/modules"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <Link
                        :href="`/modules/${module.id}/edit`"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Modifier
                    </Link>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-medium text-red-700 shadow-sm transition hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="deleting"
                        @click="openDeleteConfirm"
                    >
                        Supprimer
                    </button>
                </div>
            </div>

            <div class="mt-8 space-y-8">
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Informations générales
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Nom
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(module.name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Slug
                            </dt>
                            <dd class="mt-1 font-mono text-sm text-gray-900">
                                {{ displayValue(module.slug) }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Description
                            </dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-gray-900">
                                {{ displayValue(module.description) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Version
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(module.version) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="statusBadgeClass(module.status)"
                                >
                                    {{ statusLabel(module.status) }}
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Ordre d'affichage
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ module.sort_order ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Tarification
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Prix
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ formatAmount(module.price, module.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Devise
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(module.currency) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Informations système
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                ID
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ module.id }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Créé le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(module.created_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Modifié le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(module.updated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
