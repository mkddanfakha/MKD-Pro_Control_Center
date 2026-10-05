<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    installations: {
        type: Array,
        required: true,
    },
    selectableOfferVersions: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    installation_id: '',
    offer_version_id: '',
    amount: null,
    currency: 'XOF',
    starts_at: '',
    notes: '',
    negotiated_rate_reason: '',
});

const amountTouched = ref(false);

const selectedOfferVersion = computed(() =>
    props.selectableOfferVersions.find(
        (version) => String(version.id) === String(form.offer_version_id),
    ) ?? null,
);

const cataloguePrice = computed(() => selectedOfferVersion.value?.catalogue_price ?? null);

const isCustomRate = computed(() => {
    if (cataloguePrice.value === null || form.amount === null || form.amount === '') {
        return false;
    }

    return Number(form.amount) !== Number(cataloguePrice.value);
});

watch(selectedOfferVersion, (version) => {
    if (!version) {
        return;
    }

    form.currency = version.currency;

    if (!amountTouched.value) {
        form.amount = version.catalogue_price;
    }
});

const inputClass =
    'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200';

function installationOptionLabel(installation) {
    const company = installation.client?.company_name ?? '—';

    return `${installation.name} — ${installation.subdomain} — ${company}`;
}

function offerVersionLabel(version) {
    const product = version.product?.name ?? '—';
    const offer = version.offer?.name ?? '—';

    return `${product} / ${offer} — ${version.code} (${version.version}) — ${version.catalogue_price} ${version.currency}`;
}

function onAmountInput() {
    amountTouched.value = true;
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
                        Rattacher l'abonnement à une version commerciale active du catalogue.
                    </p>
                </div>

                <Link
                    href="/subscriptions"
                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50"
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
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.installation_id }}
                        </p>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Offre commerciale
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Seules les versions actives et applicables à la date du jour sont proposées.
                    </p>

                    <div class="mt-6">
                        <label
                            for="offer_version_id"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Version commerciale
                            <span class="text-red-600" aria-hidden="true">*</span>
                        </label>

                        <select
                            id="offer_version_id"
                            v-model="form.offer_version_id"
                            :class="inputClass"
                            :disabled="form.processing"
                        >
                            <option value="">
                                Sélectionner une version active
                            </option>
                            <option
                                v-for="version in selectableOfferVersions"
                                :key="version.id"
                                :value="version.id"
                            >
                                {{ offerVersionLabel(version) }}
                            </option>
                        </select>

                        <p
                            v-if="form.errors.offer_version_id"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.offer_version_id }}
                        </p>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Tarification
                    </h2>

                    <div
                        v-if="selectedOfferVersion"
                        class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700 ring-1 ring-gray-200"
                    >
                        <p>
                            Prix catalogue :
                            <strong>{{ cataloguePrice?.toLocaleString('fr-FR') }} {{ selectedOfferVersion.currency }}</strong>
                            / {{ selectedOfferVersion.billing_cycle === 'monthly' ? 'mois' : selectedOfferVersion.billing_cycle }}
                        </p>
                    </div>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="amount"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Tarif appliqué à l'abonnement
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="amount"
                                v-model="form.amount"
                                type="number"
                                min="0"
                                step="1"
                                class="mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm"
                                :disabled="form.processing || !selectedOfferVersion"
                                @input="onAmountInput"
                            />

                            <p
                                v-if="isCustomRate"
                                class="mt-2 text-xs font-medium text-amber-800"
                            >
                                Tarif personnalisé (différent du prix catalogue).
                            </p>

                            <p
                                v-if="form.errors.amount"
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
                            </label>

                            <input
                                id="currency"
                                v-model="form.currency"
                                type="text"
                                maxlength="3"
                                readonly
                                class="mt-2 block w-full cursor-not-allowed rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-sm"
                            />

                            <p
                                v-if="form.errors.currency"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.currency }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="isCustomRate"
                        class="mt-6"
                    >
                        <label
                            for="negotiated_rate_reason"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Motif du tarif personnalisé
                        </label>

                        <textarea
                            id="negotiated_rate_reason"
                            v-model="form.negotiated_rate_reason"
                            rows="3"
                            :class="inputClass"
                            :disabled="form.processing"
                        />

                        <p
                            v-if="form.errors.negotiated_rate_reason"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.negotiated_rate_reason }}
                        </p>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Début commercial
                    </h2>

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
                            type="datetime-local"
                            required
                            :class="inputClass"
                            :disabled="form.processing"
                        />

                        <p
                            v-if="form.errors.starts_at"
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

                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="4"
                        :class="inputClass"
                        :disabled="form.processing"
                    />
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link
                        href="/subscriptions"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700"
                    >
                        Retour
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-60"
                    >
                        {{ form.processing ? 'Création...' : 'Créer l\'abonnement' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
