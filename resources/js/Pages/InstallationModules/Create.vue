<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    installations: {
        type: Array,
        required: true,
    },
    modules: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    installation_id: '',
    module_id: '',
    status: 'active',
    version: '',
    activated_at: '',
    deactivated_at: '',
    notes: '',
});

const inputClass =
    'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200';

function installationOptionLabel(installation) {
    const company = installation.client?.company_name ?? '—';
    const subdomain = installation.subdomain ?? '—';

    return `${installation.name} — ${company} — ${subdomain}`;
}

function moduleOptionLabel(module) {
    const inactiveSuffix = module.status === 'inactive' ? ' (Inactif)' : '';

    return `${module.name} — ${module.slug}${inactiveSuffix}`;
}

const submit = () => {
    form.post('/installation-modules');
};
</script>

<template>
    <Head title="Affecter un module" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Affecter un module
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Associez un module à une installation MKD-Pro.
                    </p>
                </div>

                <Link
                    href="/installation-modules"
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
                        Affectation
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Choisissez l'installation et le module à associer.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2 lg:col-span-1">
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

                        <div class="sm:col-span-2 lg:col-span-1">
                            <label
                                for="module_id"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Module
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <select
                                id="module_id"
                                v-model="form.module_id"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.module_id"
                                :aria-describedby="form.errors.module_id ? 'module_id-error' : undefined"
                            >
                                <option value="">
                                    Sélectionner un module
                                </option>
                                <option
                                    v-for="module in modules"
                                    :key="module.id"
                                    :value="module.id"
                                >
                                    {{ moduleOptionLabel(module) }}
                                </option>
                            </select>

                            <p
                                v-if="form.errors.module_id"
                                id="module_id-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.module_id }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Statut et version
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
                                placeholder="Ex. 1.0.0"
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
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Dates
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Dates optionnelles d'activation et de désactivation sur l'installation.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="activated_at"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Date d'activation
                            </label>

                            <input
                                id="activated_at"
                                v-model="form.activated_at"
                                name="activated_at"
                                type="datetime-local"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.activated_at"
                                :aria-describedby="form.errors.activated_at ? 'activated_at-error' : undefined"
                            />

                            <p
                                v-if="form.errors.activated_at"
                                id="activated_at-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.activated_at }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="deactivated_at"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Date de désactivation
                            </label>

                            <input
                                id="deactivated_at"
                                v-model="form.deactivated_at"
                                name="deactivated_at"
                                type="datetime-local"
                                :class="inputClass"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.deactivated_at"
                                :aria-describedby="form.errors.deactivated_at ? 'deactivated_at-error' : 'deactivated_at-help'"
                            />

                            <p
                                id="deactivated_at-help"
                                class="mt-2 text-xs text-gray-500"
                            >
                                Doit être postérieure ou égale à la date d'activation.
                            </p>

                            <p
                                v-if="form.errors.deactivated_at"
                                id="deactivated_at-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.deactivated_at }}
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
                        href="/installation-modules"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Annuler
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Affectation…' : 'Affecter le module' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
