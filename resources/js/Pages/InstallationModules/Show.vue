<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    installationModule: {
        type: Object,
        required: true,
    },
});

const page = usePage();

const showDeleteConfirm = ref(false);
const deleting = ref(false);

const assignmentSubtitle = computed(() => {
    const installationName = props.installationModule.installation?.name?.trim();
    const moduleName = props.installationModule.module?.name?.trim();

    if (installationName && moduleName) {
        return `${installationName} — ${moduleName}`;
    }

    if (installationName) {
        return installationName;
    }

    if (moduleName) {
        return moduleName;
    }

    return '—';
});

const hasNotes = computed(() => {
    const notes = props.installationModule.notes;

    return notes && String(notes).trim() !== '';
});

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

    router.delete(`/installation-modules/${props.installationModule.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            showDeleteConfirm.value = false;
        },
    });
}
</script>

<template>
    <Head title="Détail de l'affectation" />

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
                v-if="showDeleteConfirm"
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

                    <p class="mt-2 text-sm font-medium text-gray-900">
                        {{ assignmentSubtitle }}
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
                        Détail de l'affectation
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Informations sur le module affecté à cette installation.
                    </p>

                    <p class="mt-2 text-sm font-medium text-gray-700">
                        {{ assignmentSubtitle }}
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        href="/installation-modules"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <Link
                        :href="`/installation-modules/${installationModule.id}/edit`"
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
                        Installation
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Installation
                            </dt>
                            <dd class="mt-1 text-xl font-semibold text-gray-900">
                                {{ displayValue(installationModule.installation?.name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Sous-domaine
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installationModule.installation?.subdomain) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Domaine
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installationModule.installation?.domain) }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">
                                Client
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(installationModule.installation?.client?.company_name) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Module (catalogue)
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Informations du module tel qu'enregistré dans le catalogue central.
                    </p>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Nom
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ displayValue(installationModule.module?.name) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Slug
                            </dt>
                            <dd class="mt-1 font-mono text-sm text-gray-900">
                                {{ displayValue(installationModule.module?.slug) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Version catalogue
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installationModule.module?.version) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Prix catalogue
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-gray-900">
                                {{ formatAmount(installationModule.module?.price, installationModule.module?.currency) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut catalogue
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="statusBadgeClass(installationModule.module?.status)"
                                >
                                    {{ statusLabel(installationModule.module?.status) }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Affectation
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Paramètres propres à cette installation (distincts du catalogue).
                    </p>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="statusBadgeClass(installationModule.status)"
                                >
                                    {{ statusLabel(installationModule.status) }}
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Version installée
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ displayValue(installationModule.version) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date d'activation
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installationModule.activated_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Date de désactivation
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(installationModule.deactivated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Notes
                    </h2>

                    <p
                        v-if="hasNotes"
                        class="mt-6 whitespace-pre-line text-sm text-gray-900"
                    >
                        {{ installationModule.notes }}
                    </p>

                    <p
                        v-else
                        class="mt-6 text-sm text-gray-500"
                    >
                        Aucune note.
                    </p>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Informations système
                    </h2>

                    <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                ID de l'affectation
                            </dt>
                            <dd class="mt-1 text-sm text-gray-600">
                                {{ installationModule.id }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Créée le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-600">
                                {{ formatDateTime(installationModule.created_at) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-gray-500">
                                Modifiée le
                            </dt>
                            <dd class="mt-1 text-sm text-gray-600">
                                {{ formatDateTime(installationModule.updated_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
