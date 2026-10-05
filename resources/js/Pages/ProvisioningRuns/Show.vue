<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    displayAdminValue,
    formatAdminDateTimeUtc,
    provisioningRunStatusBadgeClass,
    provisioningRunStatusLabel,
    provisioningRunStepStatusBadgeClass,
    provisioningRunStepStatusLabel,
} from '@/lib/adminPresentation.js';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    provisioning_run: {
        type: Object,
        required: true,
    },
    step_statistics: {
        type: Object,
        required: true,
    },
    steps: {
        type: Array,
        required: true,
    },
    navigation: {
        type: Object,
        required: true,
    },
    execute_actions: {
        type: Object,
        default: () => ({
            can_execute: false,
            unavailable_reason: null,
            button_label: 'Exécuter le provisioning',
            store_url: null,
        }),
    },
});

const executingProvisioning = ref(false);
const showExecuteConfirm = ref(false);

function cancelExecuteConfirm() {
    if (executingProvisioning.value) {
        return;
    }

    showExecuteConfirm.value = false;
}

function confirmExecuteProvisioning() {
    if (executingProvisioning.value || !props.execute_actions.can_execute) {
        return;
    }

    if (!props.execute_actions.store_url) {
        return;
    }

    executingProvisioning.value = true;

    router.post(props.execute_actions.store_url, {}, {
        preserveScroll: true,
        onFinish: () => {
            executingProvisioning.value = false;
            showExecuteConfirm.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`Provisioning — run #${provisioning_run.id}`" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500">
                        Administration provisioning
                    </p>
                    <h1 class="mt-1 text-2xl font-bold text-gray-900">
                        Provisioning run #{{ provisioning_run.id }}
                    </h1>
                    <p class="mt-2 text-sm text-gray-600">
                        Consultation et exécution manuelle contrôlée du run (orchestration interne uniquement).
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="execute_actions.can_execute"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="executingProvisioning"
                        @click="showExecuteConfirm = true"
                    >
                        {{ executingProvisioning ? 'Exécution…' : execute_actions.button_label }}
                    </button>
                    <Link
                        v-if="navigation.installation_show"
                        :href="navigation.installation_show"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour à l'installation
                    </Link>
                    <Link
                        v-if="navigation.client_show"
                        :href="navigation.client_show"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Voir le client
                    </Link>
                    <Link
                        v-if="navigation.retry_parent_show"
                        :href="navigation.retry_parent_show"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Run parent #{{ provisioning_run.retry_of_run_id }}
                    </Link>
                </div>
            </div>

            <p
                v-if="!execute_actions.can_execute && execute_actions.unavailable_reason"
                class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700 ring-1 ring-gray-200"
            >
                {{ execute_actions.unavailable_reason }}
            </p>

            <div
                v-if="showExecuteConfirm"
                class="mt-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:p-6"
            >
                <h2 class="text-base font-semibold text-gray-900">
                    Confirmer l'exécution
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    Cette action lance l'orchestration des étapes de provisioning pour le run #{{ provisioning_run.id }}.
                    Aucune confirmation supplémentaire ne sera demandée.
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="executingProvisioning"
                        @click="confirmExecuteProvisioning"
                    >
                        {{ executingProvisioning ? 'Exécution…' : 'Confirmer et exécuter' }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="executingProvisioning"
                        @click="cancelExecuteConfirm"
                    >
                        Annuler
                    </button>
                </div>
            </div>

            <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Run
                </h2>
                <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Statut
                        </dt>
                        <dd class="mt-1">
                            <span
                                class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="provisioningRunStatusBadgeClass(provisioning_run.status)"
                            >
                                {{ provisioningRunStatusLabel(provisioning_run.status) }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Déclencheur
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayAdminValue(provisioning_run.trigger) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Retry de
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900 tabular-nums">
                            {{ provisioning_run.retry_of_run_id ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Demandé le (UTC)
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatAdminDateTimeUtc(provisioning_run.requested_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Début (UTC)
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatAdminDateTimeUtc(provisioning_run.started_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Fin (UTC)
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatAdminDateTimeUtc(provisioning_run.finished_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Créé le (UTC)
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatAdminDateTimeUtc(provisioning_run.created_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Mis à jour (UTC)
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ formatAdminDateTimeUtc(provisioning_run.updated_at) }}
                        </dd>
                    </div>
                    <div
                        v-if="provisioning_run.error_code || provisioning_run.error_message"
                        class="sm:col-span-2 lg:col-span-3"
                    >
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Message run
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            <span
                                v-if="provisioning_run.error_code"
                                class="font-mono text-xs text-gray-600"
                            >{{ provisioning_run.error_code }}</span>
                            <span v-if="provisioning_run.error_code && provisioning_run.error_message"> — </span>
                            {{ displayAdminValue(provisioning_run.error_message) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Installation et client
                </h2>
                <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Installation
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayAdminValue(provisioning_run.installation?.name) }}
                            <span
                                v-if="provisioning_run.installation?.subdomain"
                                class="text-gray-500"
                            > — {{ provisioning_run.installation.subdomain }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Client
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ displayAdminValue(provisioning_run.client?.company_name) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Statistiques des étapes
                </h2>
                <dl class="mt-6 grid gap-4 grid-cols-2 sm:grid-cols-4 lg:grid-cols-7">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Total
                        </dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                            {{ step_statistics.total }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            En attente
                        </dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                            {{ step_statistics.pending }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            En cours
                        </dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                            {{ step_statistics.running }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Réussies
                        </dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                            {{ step_statistics.succeeded }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Ignorées
                        </dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                            {{ step_statistics.skipped }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Échouées
                        </dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                            {{ step_statistics.failed }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Intervention
                        </dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                            {{ step_statistics.manual_intervention_required }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                <h2 class="text-lg font-semibold text-gray-900">
                    Étapes
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    Ordre du registre — metadata non affichées (filtrage sécurité).
                </p>
                <div class="mt-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                <th class="py-3 pr-4">
                                    Ordre
                                </th>
                                <th class="py-3 pr-4">
                                    Clé
                                </th>
                                <th class="py-3 pr-4">
                                    Statut
                                </th>
                                <th class="py-3 pr-4">
                                    Début (UTC)
                                </th>
                                <th class="py-3 pr-4">
                                    Fin (UTC)
                                </th>
                                <th class="py-3 pr-4">
                                    Code
                                </th>
                                <th class="py-3">
                                    Message / résumé
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="step in steps"
                                :key="step.id"
                            >
                                <td class="py-3 pr-4 tabular-nums text-gray-900">
                                    {{ step.step_order }}
                                </td>
                                <td class="py-3 pr-4 font-mono text-xs text-gray-800">
                                    {{ step.step_key }}
                                </td>
                                <td class="py-3 pr-4">
                                    <span
                                        class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="provisioningRunStepStatusBadgeClass(step.status)"
                                    >
                                        {{ provisioningRunStepStatusLabel(step.status) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 whitespace-nowrap text-gray-700">
                                    {{ formatAdminDateTimeUtc(step.started_at) }}
                                </td>
                                <td class="py-3 pr-4 whitespace-nowrap text-gray-700">
                                    {{ formatAdminDateTimeUtc(step.finished_at) }}
                                </td>
                                <td class="py-3 pr-4 font-mono text-xs text-gray-600">
                                    {{ displayAdminValue(step.error_code) }}
                                </td>
                                <td class="py-3 text-gray-700">
                                    <span v-if="step.message">{{ step.message }}</span>
                                    <span
                                        v-else-if="step.output_summary"
                                        class="font-mono text-xs break-all"
                                    >{{ step.output_summary }}</span>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
