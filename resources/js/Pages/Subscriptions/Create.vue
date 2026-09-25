<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    installations: {
        type: Array,
        required: true,
    },
    defaultMonthlyAmount: {
        type: Number,
        required: true,
    },
});

const form = useForm({
    installation_id: '',
    amount: props.defaultMonthlyAmount,
    currency: 'XOF',
    starts_at: '',
    notes: '',
});

const inputClass =
    'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200';

function installationOptionLabel(installation) {
    const company = installation.client?.company_name ?? '—';

    return `${installation.name} — ${installation.subdomain} — ${company}`;
}

const submit = () => {
    form.post('/subscriptions', {
        onFinish: () => {},
    });
};
</script>

<template>
    <Head title="Nouvel abonnement" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Nouvel abonnement
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Créer un abonnement pour une installation MKD-Pro.
                    </p>
                </div>

                <Link
                    href="/subscriptions"
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
                        Tarification
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Le nouvel abonnement est créé à l'état actif. Son cycle est calculé automatiquement.
                    </p>

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
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Début commercial
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        La période mensuelle est calculée automatiquement à partir du début commercial.
                    </p>

                    <div class="mt-6">
                        <label
                            for="starts_at"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Début commercial de l'abonnement
                            <span class="text-red-600" aria-hidden="true">*</span>
                        </label>

                        <input
                            id="starts_at"
                            v-model="form.starts_at"
                            name="starts_at"
                            type="datetime-local"
                            required
                            :class="inputClass"
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.starts_at"
                            :aria-describedby="form.errors.starts_at ? 'starts_at-error' : 'starts_at-help'"
                        />

                        <p
                            id="starts_at-help"
                            class="mt-2 text-xs text-gray-500"
                        >
                            Date et heure auxquelles le contrat commercial démarre.
                        </p>

                        <p
                            v-if="form.errors.starts_at"
                            id="starts_at-error"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.starts_at }}
                        </p>
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
                        href="/subscriptions"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Création...' : 'Créer l\'abonnement' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
