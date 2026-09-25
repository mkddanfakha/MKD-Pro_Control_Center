<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    subscriptions: {
        type: Array,
        required: true,
    },
    defaultMonthlyAmount: {
        type: Number,
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

const form = useForm({
    subscription_id: '',
    amount: props.defaultMonthlyAmount,
    currency: 'XOF',
    status: 'paid',
    due_at: '',
    paid_at: '',
    period_start: '',
    period_end: '',
    payment_method: 'wave',
    reference: '',
    notes: '',
});

const paidAtRequired = computed(() => form.status === 'paid' || form.status === 'refunded');

const selectedSubscription = computed(() =>
    props.subscriptions.find((subscription) => String(subscription.id) === String(form.subscription_id)) ?? null,
);

function parsePositiveIntegerAmount(value) {
    if (value === '' || value === null || value === undefined) {
        return null;
    }

    const numeric = Number(value);

    if (!Number.isFinite(numeric) || numeric <= 0 || !Number.isInteger(numeric)) {
        return null;
    }

    return numeric;
}

const amountCreditPreview = computed(() => {
    if (!selectedSubscription.value) {
        return null;
    }

    const monthlyAmount = parsePositiveIntegerAmount(selectedSubscription.value.amount);

    if (monthlyAmount === null) {
        return null;
    }

    const paymentAmount = parsePositiveIntegerAmount(form.amount);

    if (paymentAmount === null) {
        return null;
    }

    if (paymentAmount % monthlyAmount !== 0) {
        return { type: 'indivisible' };
    }

    const months = paymentAmount / monthlyAmount;
    const currency = form.currency || selectedSubscription.value.currency || 'XOF';

    return {
        type: 'match',
        formattedAmount: formatFcfaAmount(paymentAmount),
        formattedMonthly: formatFcfaAmount(monthlyAmount),
        currency,
        months,
        monthsLabel: formatCreditMonthsLabel(months),
    };
});

const amountFieldDescribedBy = computed(() => {
    const ids = [];

    if (selectedSubscription.value) {
        ids.push('amount-expected-help');
    }

    if (amountCreditPreview.value?.type === 'match') {
        ids.push('amount-credit-preview');
    }

    if (amountCreditPreview.value?.type === 'indivisible') {
        ids.push('amount-credit-indivisible');
    }

    if (form.errors.amount) {
        ids.push('amount-error');
    }

    return ids.length > 0 ? ids.join(' ') : undefined;
});

function formatFcfaAmount(value) {
    return new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(value);
}

function formatCreditMonthsLabel(months) {
    if (months <= 1) {
        return '1 mois de crédit';
    }

    return `${months} mois de crédit`;
}

watch(
    () => form.subscription_id,
    (subscriptionId) => {
        const subscription = props.subscriptions.find(
            (item) => String(item.id) === String(subscriptionId),
        );

        if (!subscription) {
            return;
        }

        form.amount = subscription.amount ?? form.amount;
        form.currency = subscription.currency ?? form.currency;
    },
);

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
    form.post('/payments', {
        onFinish: () => {},
    });
};
</script>

<template>
    <Head title="Enregistrer un paiement" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Enregistrer un paiement
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Saisissez un paiement reçu pour un abonnement MKD-Pro (Wave, espèces, virement, etc.).
                    </p>
                </div>

                <Link
                    href="/payments"
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
                            :class="inputClass"
                            :disabled="form.processing"
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

                            <p
                                v-if="selectedSubscription"
                                id="amount-expected-help"
                                class="mt-1 text-sm text-gray-500"
                            >
                                Montant attendu pour cet abonnement :
                                {{ selectedSubscription.amount }}
                                {{ selectedSubscription.currency }}
                            </p>

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
                                    :aria-describedby="amountFieldDescribedBy"
                                />

                                <span class="shrink-0 text-sm font-medium text-gray-600">
                                    XOF
                                </span>
                            </div>

                            <div
                                v-if="amountCreditPreview?.type === 'match'"
                                id="amount-credit-preview"
                                class="mt-3 rounded-lg border border-sky-100 bg-sky-50/90 px-4 py-3 text-sm text-sky-950"
                                role="status"
                            >
                                <p class="font-medium text-sky-950">
                                    {{ amountCreditPreview.formattedAmount }} {{ amountCreditPreview.currency }} = {{ amountCreditPreview.monthsLabel }}
                                </p>
                                <p class="mt-1 text-sky-900">
                                    Tarif mensuel : {{ amountCreditPreview.formattedMonthly }} {{ amountCreditPreview.currency }}/mois
                                </p>
                            </div>

                            <p
                                v-else-if="amountCreditPreview?.type === 'indivisible'"
                                id="amount-credit-indivisible"
                                class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                                role="status"
                            >
                                Le montant doit correspondre à un nombre entier de mois de crédit.
                            </p>

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
                                :class="inputClass"
                                :disabled="form.processing"
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
                                :class="inputClass"
                                :disabled="form.processing"
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
                        href="/payments"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Enregistrement...' : 'Enregistrer le paiement' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
