<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    payment: {
        type: Object,
        required: true,
    },
    subscriptions: {
        type: Array,
        required: true,
    },
});

const knownPaymentMethods = ['wave', 'cash', 'bank_transfer', 'other'];

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

const form = useForm({
    subscription_id: props.payment.subscription_id ?? '',
    amount: props.payment.amount ?? 0,
    currency: props.payment.currency ?? 'XOF',
    status: props.payment.status ?? 'paid',
    due_at: toDatetimeLocalValue(props.payment.due_at),
    paid_at: toDatetimeLocalValue(props.payment.paid_at),
    period_start: toDatetimeLocalValue(props.payment.period_start),
    period_end: toDatetimeLocalValue(props.payment.period_end),
    payment_method: props.payment.payment_method ?? 'wave',
    reference: props.payment.reference ?? '',
    notes: props.payment.notes ?? '',
});

const showCustomPaymentMethodOption = computed(() => {
    const method = form.payment_method;

    return method && !knownPaymentMethods.includes(method);
});

const paidAtRequired = computed(() => form.status === 'paid' || form.status === 'refunded');

const creditConsumptionCount = computed(() => Number(props.payment.consumptions_count ?? 0));

const hasCreditConsumption = computed(() => creditConsumptionCount.value > 0);

const hasLegacyRenewalApplied = computed(
    () => ! hasCreditConsumption.value && props.payment.renewal_applied_at != null && props.payment.renewal_applied_at !== '',
);

const financialFieldsLocked = computed(() => hasCreditConsumption.value || hasLegacyRenewalApplied.value);

const statusLocked = computed(() => financialFieldsLocked.value);

const lockedFieldClass =
    'mt-2 block w-full cursor-not-allowed rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-700 outline-none';

watch(
    () => form.status,
    (status) => {
        if (status === 'pending' || status === 'failed') {
            form.paid_at = '';
        }
    },
);

const inputClass =
    'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200';

function subscriptionOptionLabel(subscription) {
    const installation = subscription.installation;
    const company = installation?.client?.company_name ?? '—';
    const name = installation?.name ?? '—';
    const subdomain = installation?.subdomain ?? '—';

    return `${name} — ${subdomain} — ${company}`;
}

const submit = () => {
    form.put(`/payments/${props.payment.id}`, {
        onFinish: () => {},
    });
};
</script>

<template>
    <Head title="Modifier le paiement" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Modifier le paiement
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Modification du paiement #{{ payment.id }}
                    </p>
                </div>

                <Link
                    :href="`/payments/${payment.id}`"
                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                    Retour
                </Link>
            </div>

            <form
                class="mt-8 space-y-8"
                @submit.prevent="submit"
            >
                <div
                    v-if="hasCreditConsumption"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    role="status"
                >
                    Ce paiement a déjà financé une ou plusieurs périodes. Les informations financières concernées ne peuvent plus être modifiées.
                </div>

                <div
                    v-else-if="hasLegacyRenewalApplied"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    role="status"
                >
                    Ce paiement a déjà servi à un renouvellement. Les informations financières concernées ne peuvent plus être modifiées.
                </div>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Abonnement
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Sélectionnez l'abonnement concerné par ce paiement.
                    </p>

                    <div class="mt-6">
                        <label
                            for="subscription_id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Abonnement
                            <span class="text-red-600" aria-hidden="true">*</span>
                        </label>

                        <select
                            id="subscription_id"
                            v-model="form.subscription_id"
                            :class="financialFieldsLocked ? lockedFieldClass : inputClass"
                            :disabled="form.processing || financialFieldsLocked"
                            :aria-invalid="!!form.errors.subscription_id"
                            :aria-describedby="form.errors.subscription_id ? 'subscription_id-error' : undefined"
                        >
                            <option value="">
                                Sélectionner un abonnement
                            </option>
                            <option
                                v-for="subscription in subscriptions"
                                :key="subscription.id"
                                :value="subscription.id"
                            >
                                {{ subscriptionOptionLabel(subscription) }}
                            </option>
                        </select>

                        <p
                            v-if="form.errors.subscription_id"
                            id="subscription_id-error"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.subscription_id }}
                        </p>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Paiement
                    </h2>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="amount"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Montant
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
                                    :class="financialFieldsLocked
                                        ? 'block w-full cursor-not-allowed rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-700 outline-none'
                                        : 'block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200'"
                                    :disabled="form.processing || financialFieldsLocked"
                                    :aria-invalid="!!form.errors.amount"
                                    :aria-describedby="form.errors.amount ? 'amount-error' : undefined"
                                />

                                <span class="shrink-0 text-sm font-medium text-gray-600">
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
                                :class="statusLocked ? lockedFieldClass : inputClass"
                                :disabled="form.processing || statusLocked"
                                :aria-invalid="!!form.errors.status"
                                :aria-describedby="form.errors.status ? 'status-error' : (statusLocked ? 'status-locked-help' : undefined)"
                            >
                                <option value="pending">
                                    En attente
                                </option>
                                <option value="paid">
                                    Payé
                                </option>
                                <option value="failed">
                                    Échec
                                </option>
                                <option value="refunded">
                                    Remboursé
                                </option>
                            </select>

                            <p
                                v-if="form.errors.status"
                                id="status-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.status }}
                            </p>

                            <p
                                v-else-if="statusLocked"
                                id="status-locked-help"
                                class="mt-2 text-xs text-gray-500"
                            >
                                Le statut doit rester « payé » après consommation de crédit ou renouvellement appliqué.
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Dates et période
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Dates optionnelles liées à l'échéance et à la période couverte.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="due_at"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Date d'échéance
                            </label>

                            <input
                                id="due_at"
                                v-model="form.due_at"
                                name="due_at"
                                type="datetime-local"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.due_at"
                                :aria-describedby="form.errors.due_at ? 'due_at-error' : undefined"
                            />

                            <p
                                v-if="form.errors.due_at"
                                id="due_at-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.due_at }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="paid_at"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Date de paiement
                                <span
                                    v-if="paidAtRequired"
                                    class="text-red-600"
                                    aria-hidden="true"
                                >*</span>
                            </label>

                            <input
                                id="paid_at"
                                v-model="form.paid_at"
                                name="paid_at"
                                type="datetime-local"
                                :class="inputClass"
                                :required="paidAtRequired"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.paid_at"
                                :aria-describedby="form.errors.paid_at ? 'paid_at-error' : undefined"
                            />

                            <p
                                v-if="form.errors.paid_at"
                                id="paid_at-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.paid_at }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="period_start"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Début de période
                            </label>

                            <input
                                id="period_start"
                                v-model="form.period_start"
                                name="period_start"
                                type="datetime-local"
                                :class="financialFieldsLocked ? lockedFieldClass : inputClass"
                                :disabled="form.processing || financialFieldsLocked"
                                :aria-invalid="!!form.errors.period_start"
                                :aria-describedby="form.errors.period_start ? 'period_start-error' : undefined"
                            />

                            <p
                                v-if="form.errors.period_start"
                                id="period_start-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.period_start }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="period_end"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Fin de période
                            </label>

                            <input
                                id="period_end"
                                v-model="form.period_end"
                                name="period_end"
                                type="datetime-local"
                                :class="financialFieldsLocked ? lockedFieldClass : inputClass"
                                :disabled="form.processing || financialFieldsLocked"
                                :aria-invalid="!!form.errors.period_end"
                                :aria-describedby="form.errors.period_end ? 'period_end-error' : undefined"
                            />

                            <p
                                v-if="form.errors.period_end"
                                id="period_end-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.period_end }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Mode de paiement
                    </h2>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="payment_method"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Mode de paiement
                            </label>

                            <select
                                id="payment_method"
                                v-model="form.payment_method"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.payment_method"
                                :aria-describedby="form.errors.payment_method ? 'payment_method-error' : undefined"
                            >
                                <option value="wave">
                                    Wave
                                </option>
                                <option value="cash">
                                    Espèces
                                </option>
                                <option value="bank_transfer">
                                    Virement bancaire
                                </option>
                                <option value="other">
                                    Autre
                                </option>
                                <option
                                    v-if="showCustomPaymentMethodOption"
                                    :value="form.payment_method"
                                >
                                    {{ form.payment_method }}
                                </option>
                            </select>

                            <p
                                v-if="form.errors.payment_method"
                                id="payment_method-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.payment_method }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="reference"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Référence
                            </label>

                            <input
                                id="reference"
                                v-model="form.reference"
                                name="reference"
                                type="text"
                                placeholder="Référence Wave ou référence interne"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.reference"
                                :aria-describedby="form.errors.reference ? 'reference-error' : undefined"
                            />

                            <p
                                v-if="form.errors.reference"
                                id="reference-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.reference }}
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
                        :href="`/payments/${payment.id}`"
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
