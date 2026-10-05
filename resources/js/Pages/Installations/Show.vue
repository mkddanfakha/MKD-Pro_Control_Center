<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    displayAdminValue,
    formatAdminDateTimeUtc,
    provisioningRunStatusBadgeClass,
    provisioningRunStatusLabel,
} from '@/lib/adminPresentation.js';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    admin_urls: {
        type: Object,
        default: () => ({}),
    },
    navigation: {
        type: Object,
        default: () => ({}),
    },
    installation: {
        type: Object,
        required: true,
    },
    current_subscription: {
        type: Object,
        default: null,
    },
    payments: {
        type: Object,
        required: true,
    },
    reminders: {
        type: Object,
        required: true,
    },
    installation_modules: {
        type: Array,
        default: () => [],
    },
    administrative_readiness: {
        type: Object,
        default: null,
    },
    last_provisioning_run: {
        type: Object,
        default: null,
    },
    provisioning_actions: {
        type: Object,
        default: () => ({
            can_create_request: false,
            unavailable_reason: null,
            button_label: 'Créer une demande de provisioning',
            retry_basis_run_id: null,
            store_url: null,
        }),
    },
});

const page = usePage();
const deleting = ref(false);
const showDeleteConfirm = ref(false);
const creatingProvisioningRequest = ref(false);
const showProvisioningConfirm = ref(false);

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

    router.delete(`/installations/${props.installation.id}`, {
        onFinish: () => {
            deleting.value = false;
            showDeleteConfirm.value = false;
        },
    });
}

function cancelProvisioningConfirm() {
    if (creatingProvisioningRequest.value) {
        return;
    }

    showProvisioningConfirm.value = false;
}

function confirmCreateProvisioningRequest() {
    if (creatingProvisioningRequest.value || !props.provisioning_actions.can_create_request) {
        return;
    }

    if (!props.provisioning_actions.store_url) {
        return;
    }

    creatingProvisioningRequest.value = true;

    router.post(props.provisioning_actions.store_url, {}, {
        preserveScroll: true,
        onFinish: () => {
            creatingProvisioningRequest.value = false;
            showProvisioningConfirm.value = false;
        },
    });
}

function displayValue(value) {
    return value && String(value).trim() !== '' ? value : '—';
}

function formatDateTime(value) {
    if (!value) {
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

function formatPeriodEndUtc(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: 'UTC',
    }).format(new Date(value));
}

function installationStatusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
        suspended: 'Suspendu',
        terminated: 'Terminé',
    };

    return labels[status] ?? status;
}

function installationStatusBadgeClass(status) {
    const classes = {
        active: 'bg-sky-50 text-sky-800 ring-sky-200',
        inactive: 'bg-gray-100 text-gray-600 ring-gray-200',
        suspended: 'bg-amber-50 text-amber-900 ring-amber-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function subscriptionStatusLabel(status) {
    const labels = {
        active: 'Actif',
        grace_period: 'Période de grâce',
        suspended: 'Suspendu',
        terminated: 'Terminé',
    };

    return labels[status] ?? status;
}

function subscriptionStatusBadgeClass(status) {
    const classes = {
        active: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        grace_period: 'bg-amber-50 text-amber-900 ring-amber-200',
        suspended: 'bg-orange-50 text-orange-900 ring-orange-200',
        terminated: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function paymentStatusLabel(status) {
    const labels = {
        pending: 'En attente',
        paid: 'Payé',
        failed: 'Échoué',
        refunded: 'Remboursé',
    };

    return labels[status] ?? status;
}

function paymentStatusBadgeClass(status) {
    const classes = {
        pending: 'bg-amber-50 text-amber-900 ring-amber-200',
        paid: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        failed: 'bg-red-50 text-red-800 ring-red-200',
        refunded: 'bg-gray-100 text-gray-700 ring-gray-300',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function reminderStatusLabel(status) {
    const labels = {
        detected: 'Détecté',
        sent: 'Envoyé',
        failed: 'Échec',
    };

    return labels[status] ?? status;
}

function reminderStatusBadgeClass(status) {
    const classes = {
        detected: 'bg-amber-50 text-amber-900 ring-amber-200',
        sent: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        failed: 'bg-red-50 text-red-800 ring-red-200',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function thresholdLabel(days) {
    if (days === 0) {
        return 'J0';
    }

    return `J-${days}`;
}

function formatAmount(amount, currency) {
    const formatted = new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Number(amount ?? 0));

    return `${formatted} ${currency ?? 'XOF'}`;
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

function installationModuleStatusLabel(status) {
    const labels = {
        active: 'Actif',
        inactive: 'Inactif',
    };

    return labels[status] ?? status;
}

function installationModuleStatusBadgeClass(status) {
    const classes = {
        active: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        inactive: 'bg-gray-100 text-gray-600 ring-gray-200',
    };

    return classes[status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
}

function readinessStateSymbol(state) {
    if (state === 'complete') {
        return '✓';
    }

    if (state === 'empty') {
        return '—';
    }

    return '✗';
}

function readinessStateClass(state) {
    if (state === 'complete') {
        return 'text-emerald-700';
    }

    if (state === 'empty') {
        return 'text-gray-500';
    }

    return 'text-amber-700';
}
</script>

<template>
    <Head :title="installation.name" />

    <AdminLayout>
        <div class="space-y-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <Link
                        :href="navigation.installations_index ?? '/installations'"
                        class="text-sm font-medium text-gray-600 underline-offset-2 hover:text-gray-900 hover:underline"
                    >
                        ← Retour aux installations
                    </Link>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900">
                            {{ installation.name }}
                        </h1>
                        <span
                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                            :class="installationStatusBadgeClass(installation.status)"
                        >
                            {{ installationStatusLabel(installation.status) }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm text-gray-600">
                        {{ displayValue(installation.subdomain) }}
                        <span
                            v-if="installation.version"
                            class="text-gray-400"
                        > · v{{ installation.version }}</span>
                    </p>

                </div>

                <div class="flex flex-wrap gap-3">
                    <Link
                        v-if="navigation.edit"
                        :href="navigation.edit"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50"
                    >
                        Modifier
                    </Link>
                    <button
                        v-if="admin_urls.can_delete !== false"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-medium text-red-700 shadow-sm transition hover:bg-red-50 disabled:opacity-60"
                        :disabled="deleting"
                        @click="showDeleteConfirm = true"
                    >
                        Supprimer
                    </button>
                    <p
                        v-else-if="admin_urls.delete_unavailable_reason"
                        class="text-sm text-gray-600"
                    >
                        {{ admin_urls.delete_unavailable_reason }}
                    </p>
                </div>
            </div>

            <div
                v-if="showDeleteConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="dialog"
                aria-modal="true"
                @click.self="cancelDelete"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Supprimer l'installation
                    </h2>
                    <p class="mt-3 text-sm text-gray-600">
                        Voulez-vous vraiment supprimer cette installation ?
                    </p>
                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700"
                            :disabled="deleting"
                            @click="cancelDelete"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                            :disabled="deleting"
                            @click="confirmDelete"
                        >
                            {{ deleting ? 'Suppression…' : 'Supprimer' }}
                        </button>
                    </div>
                </div>
            </div>

            <section
                v-if="administrative_readiness"
                class="rounded-xl border border-sky-100 bg-sky-50/60 p-6 shadow-sm ring-1 ring-sky-100 sm:p-8"
            >
                <h2 class="text-lg font-semibold text-gray-900">
                    Enregistrement et déploiement
                </h2>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ administrative_readiness.control_center_registration?.label }}
                        </dt>
                        <dd class="mt-1 text-sm font-medium text-emerald-800">
                            {{ administrative_readiness.control_center_registration?.value }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ administrative_readiness.technical_deployment?.label }}
                        </dt>
                        <dd class="mt-1 text-sm font-medium text-gray-700">
                            {{ administrative_readiness.technical_deployment?.value }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm text-gray-600">
                    {{ administrative_readiness.status_administrative_note }}
                </p>
            </section>

            <section
                v-if="administrative_readiness?.checklist?.length"
                class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8"
            >
                <h2 class="text-lg font-semibold text-gray-900">
                    Synthèse administrative
                </h2>
                <p class="mt-1 text-xs text-gray-500">
                    Lecture documentaire — aucune vérification serveur distante.
                </p>
                <ul class="mt-6 space-y-3">
                    <li
                        v-for="item in administrative_readiness.checklist"
                        :key="item.key"
                        class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm"
                    >
                        <span
                            class="font-semibold tabular-nums"
                            :class="readinessStateClass(item.state)"
                        >{{ readinessStateSymbol(item.state) }}</span>
                        <span class="font-medium text-gray-900">{{ item.label }}</span>
                        <span class="text-gray-600">— {{ item.detail }}</span>
                    </li>
                </ul>
                <p class="mt-4 text-sm text-gray-600">
                    <span class="font-medium text-gray-800">Déploiement technique :</span>
                    non suivi actuellement (aucun ✓ déployé).
                </p>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">
                            Provisioning
                        </h2>
                        <p class="mt-1 text-sm text-gray-500">
                            Demande et suivi du dernier run — aucune exécution automatique depuis cette page.
                        </p>
                    </div>
                    <button
                        v-if="provisioning_actions.can_create_request"
                        type="button"
                        class="inline-flex shrink-0 items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:opacity-60"
                        :disabled="creatingProvisioningRequest"
                        @click="showProvisioningConfirm = true"
                    >
                        {{ provisioning_actions.button_label }}
                    </button>
                </div>

                <p
                    v-if="provisioning_actions.retry_basis_run_id"
                    class="mt-4 text-sm text-gray-600"
                >
                    Nouvelle demande basée sur le run #{{ provisioning_actions.retry_basis_run_id }}
                </p>

                <p
                    v-if="!provisioning_actions.can_create_request && provisioning_actions.unavailable_reason"
                    class="mt-4 text-sm text-amber-900"
                >
                    {{ provisioning_actions.unavailable_reason }}
                </p>

                <div
                    v-if="!last_provisioning_run"
                    class="mt-6 rounded-lg border border-dashed border-gray-200 bg-gray-50/80 px-4 py-6 text-sm text-gray-600"
                >
                    Aucune demande de provisioning enregistrée pour cette installation.
                </div>

                <dl
                    v-else
                    class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Statut
                        </dt>
                        <dd class="mt-1">
                            <span
                                class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="provisioningRunStatusBadgeClass(last_provisioning_run.status)"
                            >
                                {{ provisioningRunStatusLabel(last_provisioning_run.status) }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Run #
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900 tabular-nums">
                            {{ last_provisioning_run.id }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Étapes
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900 tabular-nums">
                            {{ last_provisioning_run.steps_completed ?? 0 }} / {{ last_provisioning_run.steps_total ?? 0 }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Créée le (UTC)
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatAdminDateTimeUtc(last_provisioning_run.created_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Début (UTC)
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatAdminDateTimeUtc(last_provisioning_run.started_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Fin (UTC)
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatAdminDateTimeUtc(last_provisioning_run.finished_at) }}
                        </dd>
                    </div>
                    <div
                        v-if="last_provisioning_run.error_message"
                        class="sm:col-span-2 lg:col-span-3"
                    >
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Message
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayAdminValue(last_provisioning_run.error_message) }}
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="last_provisioning_run?.show_url"
                    class="mt-6"
                >
                    <Link
                        :href="last_provisioning_run.show_url"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Voir le provisioning
                    </Link>
                </div>
            </section>

            <div
                v-if="showProvisioningConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="provisioning-confirm-title"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl ring-1 ring-gray-200">
                    <h3
                        id="provisioning-confirm-title"
                        class="text-lg font-semibold text-gray-900"
                    >
                        Confirmer la demande
                    </h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Une demande de provisioning sera enregistrée en état « en attente ». Aucune exécution
                        automatique du pipeline ne sera lancée.
                    </p>
                    <p
                        v-if="provisioning_actions.retry_basis_run_id"
                        class="mt-2 text-sm text-gray-600"
                    >
                        Nouvelle demande basée sur le run #{{ provisioning_actions.retry_basis_run_id }}.
                    </p>
                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                            :disabled="creatingProvisioningRequest"
                            @click="cancelProvisioningConfirm"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-60"
                            :disabled="creatingProvisioningRequest"
                            @click="confirmCreateProvisioningRequest"
                        >
                            {{ creatingProvisioningRequest ? 'Enregistrement…' : 'Confirmer' }}
                        </button>
                    </div>
                </div>
            </div>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Informations générales
                </h2>
                <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Domaine
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(installation.domain) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Date d'installation
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatDateTime(installation.installed_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Dernière activité
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatDateTime(installation.last_seen_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Suspendue le
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatDateTime(installation.suspended_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Terminée le
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatDateTime(installation.terminated_at) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Client
                    </h2>
                    <Link
                        v-if="navigation.client_show"
                        :href="navigation.client_show"
                        class="text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                    >
                        Voir le client
                    </Link>
                </div>
                <dl
                    v-if="installation.client"
                    class="mt-6 grid gap-4 sm:grid-cols-2"
                >
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Raison sociale
                        </dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">
                            {{ installation.client.company_name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Contact
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(installation.client.contact_name) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            E-mail
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(installation.client.email) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Téléphone
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(installation.client.phone) }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Adresse
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayValue(installation.client.address) }}
                            <span v-if="installation.client.city"> — {{ installation.client.city }}</span>
                            <span v-if="installation.client.country"> ({{ installation.client.country }})</span>
                        </dd>
                    </div>
                </dl>
                <p
                    v-else
                    class="mt-4 text-sm text-gray-500"
                >
                    Client introuvable.
                </p>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Modules affectés
                    </h2>
                    <Link
                        v-if="navigation.installation_modules_index"
                        :href="navigation.installation_modules_index"
                        class="text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                    >
                        Gérer les affectations
                    </Link>
                </div>
                <div
                    v-if="installation_modules.length"
                    class="mt-6 overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                <th class="pb-2 pr-4">
                                    Module
                                </th>
                                <th class="pb-2 pr-4">
                                    Statut
                                </th>
                                <th class="pb-2 pr-4">
                                    Version
                                </th>
                                <th class="pb-2 pr-4">
                                    Activé le
                                </th>
                                <th class="pb-2 pr-4">
                                    Désactivé le
                                </th>
                                <th class="pb-2 pr-4">
                                    Tarif catalogue
                                </th>
                                <th class="pb-2">
                                    Détail
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="row in installation_modules"
                                :key="row.id"
                            >
                                <td class="py-3 pr-4 font-medium text-gray-900">
                                    {{ row.module?.name ?? '—' }}
                                </td>
                                <td class="py-3 pr-4">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="installationModuleStatusBadgeClass(row.status)"
                                    >
                                        {{ installationModuleStatusLabel(row.status) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 text-gray-700">
                                    {{ displayValue(row.version) }}
                                </td>
                                <td class="py-3 pr-4 text-gray-700">
                                    {{ formatDateTime(row.activated_at) }}
                                </td>
                                <td class="py-3 pr-4 text-gray-700">
                                    {{ formatDateTime(row.deactivated_at) }}
                                </td>
                                <td class="py-3 pr-4 text-gray-700">
                                    <template v-if="row.module?.price != null">
                                        {{ formatAmount(row.module.price, row.module.currency) }}
                                    </template>
                                    <template v-else>
                                        —
                                    </template>
                                </td>
                                <td class="py-3">
                                    <Link
                                        v-if="row.show_url"
                                        :href="row.show_url"
                                        class="text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                                    >
                                        Voir
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-else
                    class="mt-4 text-sm text-gray-600"
                >
                    Aucun module affecté à cette installation.
                </p>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Abonnement courant
                </h2>
                <template v-if="current_subscription">
                    <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Statut
                            </dt>
                            <dd class="mt-1">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="subscriptionStatusBadgeClass(current_subscription.status)"
                                >
                                    {{ subscriptionStatusLabel(current_subscription.status) }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Tarif mensuel
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatAmount(current_subscription.amount, current_subscription.currency) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Fin de période (UTC)
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatPeriodEndUtc(current_subscription.current_period_end) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Début contrat
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(current_subscription.starts_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Période en cours
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(current_subscription.current_period_start) }}
                                →
                                {{ formatPeriodEndUtc(current_subscription.current_period_end) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Fin période de grâce
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ formatDateTime(current_subscription.grace_period_ends_at) }}
                            </dd>
                        </div>
                    </dl>
                    <Link
                        :href="current_subscription.show_url ?? navigation.subscription_show ?? `/subscriptions/${current_subscription.id}`"
                        class="mt-4 inline-block text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                    >
                        Voir l'abonnement
                    </Link>
                </template>
                <p
                    v-else
                    class="mt-4 text-sm text-gray-600"
                >
                    Aucun abonnement actif
                </p>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Paiements
                    </h2>
                    <Link
                        v-if="navigation.payments_index"
                        :href="navigation.payments_index"
                        class="text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                    >
                        Voir les paiements
                    </Link>
                </div>
                <div
                    v-if="!payments.data.length"
                    class="mt-4 text-sm text-gray-500"
                >
                    Aucun paiement enregistré pour cette installation.
                </div>
                <div
                    v-else
                    class="mt-6 overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Réf.
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Montant
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Statut
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Échéance
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Payé le
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 lg:table-cell">
                                    Crédit (mois)
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Créé le
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="payment in payments.data"
                                :key="payment.id"
                            >
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ displayValue(payment.reference ?? `#${payment.id}`) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-900">
                                    {{ formatAmount(payment.amount, payment.currency) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="paymentStatusBadgeClass(payment.status)"
                                    >
                                        {{ paymentStatusLabel(payment.status) }}
                                    </span>
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 md:table-cell">
                                    {{ formatDateTime(payment.due_at) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ formatDateTime(payment.paid_at) }}
                                </td>
                                <td class="hidden px-4 py-3 text-gray-600 lg:table-cell">
                                    {{ payment.credit_months_purchased ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-600">
                                    {{ formatDateTime(payment.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="payments.links && payments.links.length > 3"
                    class="mt-4 flex flex-wrap justify-center gap-1"
                >
                    <button
                        v-for="(link, index) in payments.links"
                        :key="`pay-${index}`"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm disabled:text-gray-400"
                        :class="link.active ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
                        :disabled="!link.url"
                        @click="visitPagination(link.url)"
                        v-html="link.label"
                    />
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Rappels d'abonnement
                    </h2>
                    <Link
                        v-if="navigation.reminders_index"
                        :href="navigation.reminders_index"
                        class="text-sm font-medium text-gray-700 underline-offset-2 hover:underline"
                    >
                        Voir les rappels
                    </Link>
                </div>
                <p class="mt-1 text-xs text-gray-500">
                    Lecture seule — aucun envoi ni retraitement depuis cette page.
                </p>
                <div
                    v-if="!reminders.data.length"
                    class="mt-4 text-sm text-gray-500"
                >
                    Aucun rappel enregistré pour cette installation.
                </div>
                <div
                    v-else
                    class="mt-6 overflow-x-auto"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Seuil
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Statut
                                </th>
                                <th class="px-4 py-3 font-semibold text-gray-700">
                                    Planifié (UTC)
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Détecté
                                </th>
                                <th class="hidden px-4 py-3 font-semibold text-gray-700 md:table-cell">
                                    Envoyé
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="reminder in reminders.data"
                                :key="reminder.id"
                            >
                                <td class="px-4 py-3 text-gray-900">
                                    {{ thresholdLabel(reminder.threshold_days) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="reminderStatusBadgeClass(reminder.status)"
                                    >
                                        {{ reminderStatusLabel(reminder.status) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-600">
                                    {{ formatPeriodEndUtc(reminder.scheduled_for) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 md:table-cell">
                                    {{ formatDateTime(reminder.detected_at) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-gray-600 md:table-cell">
                                    {{ formatDateTime(reminder.sent_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="reminders.links && reminders.links.length > 3"
                    class="mt-4 flex flex-wrap justify-center gap-1"
                >
                    <button
                        v-for="(link, index) in reminders.links"
                        :key="`rem-${index}`"
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
