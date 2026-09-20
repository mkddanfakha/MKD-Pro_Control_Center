<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    company_name: props.client.company_name ?? '',
    contact_name: props.client.contact_name ?? '',
    phone: props.client.phone ?? '',
    email: props.client.email ?? '',
    address: props.client.address ?? '',
    city: props.client.city ?? '',
    country: props.client.country ?? '',
    status: props.client.status ?? 'active',
    notes: props.client.notes ?? '',
});

const inputClass =
    'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200';

const submit = () => {
    form.put(`/clients/${props.client.id}`, {
        onFinish: () => {},
    });
};
</script>

<template>
    <Head title="Modifier le client" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Modifier le client
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Modifiez les informations de ce client.
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        :href="`/clients/${client.id}`"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour au client
                    </Link>

                    <Link
                        href="/clients"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour aux clients
                    </Link>
                </div>
            </div>

            <form
                class="mt-8 space-y-8"
                @submit.prevent="submit"
            >
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Entreprise et contact
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Informations principales du client.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2 lg:col-span-1">
                            <label
                                for="company_name"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Nom de l'entreprise
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="company_name"
                                v-model="form.company_name"
                                type="text"
                                autocomplete="organization"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.company_name"
                                :aria-describedby="form.errors.company_name ? 'company_name-error' : undefined"
                            />

                            <p
                                v-if="form.errors.company_name"
                                id="company_name-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.company_name }}
                            </p>
                        </div>

                        <div class="sm:col-span-2 lg:col-span-1">
                            <label
                                for="contact_name"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Nom du contact
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="contact_name"
                                v-model="form.contact_name"
                                type="text"
                                autocomplete="name"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.contact_name"
                                :aria-describedby="form.errors.contact_name ? 'contact_name-error' : undefined"
                            />

                            <p
                                v-if="form.errors.contact_name"
                                id="contact_name-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.contact_name }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Coordonnées
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Téléphone, e-mail et adresse postale.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="phone"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Téléphone
                            </label>

                            <input
                                id="phone"
                                v-model="form.phone"
                                type="tel"
                                autocomplete="tel"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.phone"
                                :aria-describedby="form.errors.phone ? 'phone-error' : undefined"
                            />

                            <p
                                v-if="form.errors.phone"
                                id="phone-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.phone }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="email"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Adresse e-mail
                            </label>

                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.email"
                                :aria-describedby="form.errors.email ? 'email-error' : undefined"
                            />

                            <p
                                v-if="form.errors.email"
                                id="email-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.email }}
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                for="address"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Adresse
                            </label>

                            <textarea
                                id="address"
                                v-model="form.address"
                                rows="3"
                                autocomplete="street-address"
                                class="mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                                :aria-invalid="!!form.errors.address"
                                :aria-describedby="form.errors.address ? 'address-error' : undefined"
                            />

                            <p
                                v-if="form.errors.address"
                                id="address-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.address }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="city"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Ville
                            </label>

                            <input
                                id="city"
                                v-model="form.city"
                                type="text"
                                autocomplete="address-level2"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.city"
                                :aria-describedby="form.errors.city ? 'city-error' : undefined"
                            />

                            <p
                                v-if="form.errors.city"
                                id="city-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.city }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="country"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Pays
                            </label>

                            <input
                                id="country"
                                v-model="form.country"
                                type="text"
                                autocomplete="country-name"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.country"
                                :aria-describedby="form.errors.country ? 'country-error' : undefined"
                            />

                            <p
                                v-if="form.errors.country"
                                id="country-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.country }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Statut et notes
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        État du client et informations complémentaires.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
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
                                :aria-invalid="!!form.errors.status"
                                :aria-describedby="form.errors.status ? 'status-error' : undefined"
                            >
                                <option value="active">
                                    Actif
                                </option>
                                <option value="inactive">
                                    Inactif
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

                        <div class="sm:col-span-2">
                            <label
                                for="notes"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Notes
                            </label>

                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="4"
                                class="mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
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
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link
                        :href="`/clients/${client.id}`"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Annuler
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
