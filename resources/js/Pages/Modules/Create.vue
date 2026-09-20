<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    slug: '',
    description: '',
    version: '',
    price: null,
    currency: 'XOF',
    status: 'active',
    sort_order: 0,
});

const inputClass =
    'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200';

const submit = () => {
    form.post('/modules', {
        onFinish: () => {},
    });
};
</script>

<template>
    <Head title="Nouveau module" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Nouveau module
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Ajouter un module au catalogue MKD-Pro.
                    </p>
                </div>

                <Link
                    href="/modules"
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
                        Identité
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Nom, identifiant et description du module dans le catalogue.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2 lg:col-span-1">
                            <label
                                for="name"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Nom
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="name"
                                v-model="form.name"
                                name="name"
                                type="text"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.name"
                                :aria-describedby="form.errors.name ? 'name-error' : undefined"
                            />

                            <p
                                v-if="form.errors.name"
                                id="name-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div class="sm:col-span-2 lg:col-span-1">
                            <label
                                for="slug"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Slug
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="slug"
                                v-model="form.slug"
                                name="slug"
                                type="text"
                                placeholder="ex. gestion-stock"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.slug"
                                :aria-describedby="form.errors.slug ? 'slug-error' : 'slug-help'"
                            />

                            <p
                                id="slug-help"
                                class="mt-2 text-xs text-gray-500"
                            >
                                Identifiant unique utilisé dans le catalogue (sans espaces).
                            </p>

                            <p
                                v-if="form.errors.slug"
                                id="slug-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.slug }}
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                for="description"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Description
                            </label>

                            <textarea
                                id="description"
                                v-model="form.description"
                                name="description"
                                rows="4"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.description"
                                :aria-describedby="form.errors.description ? 'description-error' : undefined"
                            />

                            <p
                                v-if="form.errors.description"
                                id="description-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.description }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Version et tarification
                    </h2>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="version"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Version
                            </label>

                            <input
                                id="version"
                                v-model="form.version"
                                name="version"
                                type="text"
                                placeholder="ex. 1.0.0"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.version"
                                :aria-describedby="form.errors.version ? 'version-error' : undefined"
                            />

                            <p
                                v-if="form.errors.version"
                                id="version-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.version }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="price"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Prix
                            </label>

                            <div class="mt-2 flex items-center gap-3">
                                <input
                                    id="price"
                                    v-model="form.price"
                                    name="price"
                                    type="number"
                                    min="0"
                                    step="1"
                                    class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                                    :disabled="form.processing"
                                    :aria-invalid="!!form.errors.price"
                                    :aria-describedby="form.errors.price ? 'price-error' : undefined"
                                />

                                <span class="shrink-0 text-sm font-medium text-gray-600">
                                    XOF
                                </span>
                            </div>

                            <p
                                v-if="form.errors.price"
                                id="price-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.price }}
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
                        Publication
                    </h2>

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
                                :disabled="form.processing"
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

                        <div>
                            <label
                                for="sort_order"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Ordre d'affichage
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="sort_order"
                                v-model="form.sort_order"
                                name="sort_order"
                                type="number"
                                min="0"
                                step="1"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.sort_order"
                                :aria-describedby="form.errors.sort_order ? 'sort_order-error' : undefined"
                            />

                            <p
                                v-if="form.errors.sort_order"
                                id="sort_order-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.sort_order }}
                            </p>
                        </div>
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link
                        href="/modules"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Création...' : 'Créer le module' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
