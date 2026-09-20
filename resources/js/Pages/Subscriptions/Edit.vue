<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    subscription: {
        type: Object,
        required: true,
    },
    installations: {
        type: Array,
        required: true,
    },
});

function toDatetimeLocalValue(value) {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'string' && value.trim() === '') {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (part) => String(part).padStart(2, '0');

    return [
        date.getFullYear(),
        pad(date.getMonth() + 1),
        pad(date.getDate()),
    ].join('-').concat('T', pad(date.getHours()), ':', pad(date.getMinutes()));
}

const installationSubtitle = computed(() => {
    const installation = props.subscription.installation;

    if (!installation) {
        return 'Installation MKD-Pro';
    }

    const name = installation.name?.trim() || '—';
    const subdomain = installation.subdomain?.trim() || '—';

    return `${name} — ${subdomain}`;
});

const form = useForm({
    installation_id: props.subscription.installation_id ?? '',
    amount: props.subscription.amount ?? 0,
    currency: props.subscription.currency ?? 'XOF',
    status: props.subscription.status ?? 'active',
    starts_at: toDatetimeLocalValue(props.subscription.starts_at),
    current_period_start: toDatetimeLocalValue(props.subscription.current_period_start),
    current_period_end: toDatetimeLocalValue(props.subscription.current_period_end),
    grace_period_ends_at: toDatetimeLocalValue(props.subscription.grace_period_ends_at),
    notes: props.subscription.notes ?? '',
});

const inputClass =
    'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200';

function installationOptionLabel(installation) {
    const company = installation.client?.company_name ?? '—';

    return `${installation.name} — ${installation.subdomain} — ${company}`;
}

const submit = () => {
    form.put(`/subscriptions/${props.subscription.id}`, {
        onFinish: () => {},
    });
};
</script>

<template>
    <Head title="Modifier l'abonnement" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Modifier l'abonnement
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ installationSubtitle }}
                    </p>
                </div>

                <Link
                    :href="`/subscriptions/${subscription.id}`"
                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Retour
                </Link>
            </div>

            <form
                class="mt-8 space-y-8"
                @submit.prevent="submit"
            >
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Installation
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Choisissez l'installation MKD-Pro concernée par cet abonnement.
                    </p>

                    <div class="mt-6">
                        <label
                            for="installation_id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Installation
                            <span class="text-red-600" aria-hidden="true">*</span>
                        </label>

                        <select
                            id="installation_id"
                            v-model="form.installation_id"
                            :class="inputClass"
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.installation_id"
                            :aria-describedby="form.errors.installation_id ? 'installation_id-error' : undefined"
                        >
                            <option value="">
                                Sélectionner une installation
                            </option>
                            <option
                                v-for="installation in installations"
                                :key="installation.id"
                                :value="installation.id"
                            >
                                {{ installationOptionLabel(installation) }}
                            </option>
                        </select>

                        <p
                            v-if="form.errors.installation_id"
                            id="installation_id-error"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.installation_id }}
                        </p>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Tarification et statut
                    </h2>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="amount"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Montant mensuel
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <div class="mt-2 flex items-center gap-3">
                                <input
                                    id="amount"
                                    v-model="form.amount"
                                    name="amount"
                                    type="number"
                                    min="0"
                                    step="1"
                                    class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                                    :disabled="form.processing"
                                    :aria-invalid="!!form.errors.amount"
                                    :aria-describedby="form.errors.amount ? 'amount-error' : 'amount-help'"
                                />

                                <span
                                    id="amount-help"
                                    class="shrink-0 text-sm font-medium text-gray-600"
                                >
                                    XOF
                                </span>
                            </div>

                            <p
                                v-if="form.errors.amount"
                                id="amount-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.amount }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="currency"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Devise
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="currency"
                                v-model="form.currency"
                                name="currency"
                                type="text"
                                maxlength="3"
                                readonly
                                class="mt-2 block w-full cursor-not-allowed rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-700 outline-none"
                                :aria-invalid="!!form.errors.currency"
                                :aria-describedby="form.errors.currency ? 'currency-error' : 'currency-help'"
                            />

                            <p
                                id="currency-help"
                                class="mt-2 text-xs text-gray-500"
                            >
                                Franc CFA BCEAO (XOF).
                            </p>

                            <p
                                v-if="form.errors.currency"
                                id="currency-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.currency }}
                            </p>
                        </div>

                        <div class="sm:col-span-2 lg:col-span-1">
                            <label
                                for="status"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Statut
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <select
                                id="status"
                                v-model="form.status"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.status"
                                :aria-describedby="form.errors.status ? 'status-error' : undefined"
                            >
                                <option value="active">
                                    Actif
                                </option>
                                <option value="grace_period">
                                    Période de grâce
                                </option>
                                <option value="suspended">
                                    Suspendu
                                </option>
                                <option value="terminated">
                                    Terminé
                                </option>
                            </select>

                            <p
                                v-if="form.errors.status"
                                id="status-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.status }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Périodes et dates
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Dates optionnelles du cycle d'abonnement.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="starts_at"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Date de début
                            </label>

                            <input
                                id="starts_at"
                                v-model="form.starts_at"
                                name="starts_at"
                                type="datetime-local"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.starts_at"
                                :aria-describedby="form.errors.starts_at ? 'starts_at-error' : undefined"
                            />

                            <p
                                v-if="form.errors.starts_at"
                                id="starts_at-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.starts_at }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="current_period_start"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Début de la période actuelle
                            </label>

                            <input
                                id="current_period_start"
                                v-model="form.current_period_start"
                                name="current_period_start"
                                type="datetime-local"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.current_period_start"
                                :aria-describedby="form.errors.current_period_start ? 'current_period_start-error' : undefined"
                            />

                            <p
                                v-if="form.errors.current_period_start"
                                id="current_period_start-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.current_period_start }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="current_period_end"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Fin de la période actuelle
                            </label>

                            <input
                                id="current_period_end"
                                v-model="form.current_period_end"
                                name="current_period_end"
                                type="datetime-local"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.current_period_end"
                                :aria-describedby="form.errors.current_period_end ? 'current_period_end-error' : 'current_period_end-help'"
                            />

                            <p
                                id="current_period_end-help"
                                class="mt-2 text-xs text-gray-500"
                            >
                                Date à laquelle la période mensuelle actuelle se termine.
                            </p>

                            <p
                                v-if="form.errors.current_period_end"
                                id="current_period_end-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.current_period_end }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="grace_period_ends_at"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Fin de la période de grâce
                            </label>

                            <input
                                id="grace_period_ends_at"
                                v-model="form.grace_period_ends_at"
                                name="grace_period_ends_at"
                                type="datetime-local"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.grace_period_ends_at"
                                :aria-describedby="form.errors.grace_period_ends_at ? 'grace_period_ends_at-error' : 'grace_period_ends_at-help'"
                            />

                            <p
                                id="grace_period_ends_at-help"
                                class="mt-2 text-xs text-gray-500"
                            >
                                À utiliser uniquement lorsqu'une période de grâce est prévue.
                            </p>

                            <p
                                v-if="form.errors.grace_period_ends_at"
                                id="grace_period_ends_at-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.grace_period_ends_at }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Notes
                    </h2>

                    <div class="mt-6">
                        <label
                            for="notes"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Notes
                        </label>

                        <textarea
                            id="notes"
                            v-model="form.notes"
                            name="notes"
                            rows="4"
                            :class="inputClass"
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.notes"
                            :aria-describedby="form.errors.notes ? 'notes-error' : undefined"
                        />

                        <p
                            v-if="form.errors.notes"
                            id="notes-error"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.notes }}
                        </p>
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link
                        :href="`/subscriptions/${subscription.id}`"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Enregistrement...' : 'Enregistrer les modifications' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
