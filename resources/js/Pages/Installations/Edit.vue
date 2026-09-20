<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    installation: {
        type: Object,
        required: true,
    },
    clients: {
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

const form = useForm({
    client_id: props.installation.client_id ?? '',
    name: props.installation.name ?? '',
    subdomain: props.installation.subdomain ?? '',
    domain: props.installation.domain ?? '',
    status: props.installation.status ?? 'active',
    version: props.installation.version ?? '',
    database_name: props.installation.database_name ?? '',
    database_host: props.installation.database_host ?? '',
    installed_at: toDatetimeLocalValue(props.installation.installed_at),
    last_seen_at: toDatetimeLocalValue(props.installation.last_seen_at),
});

const inputClass =
    'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200';

const submit = () => {
    form.put(`/installations/${props.installation.id}`, {
        onFinish: () => {},
    });
};
</script>

<template>
    <Head title="Modifier l'installation" />

    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Modifier l'installation
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ installation.name }}
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <Link
                        :href="`/installations/${installation.id}`"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Retour
                    </Link>

                    <Link
                        href="/installations"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                    >
                        Liste des installations
                    </Link>
                </div>
            </div>

            <form
                class="mt-8 space-y-8"
                @submit.prevent="submit"
            >
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Client et installation
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Associez l'installation à un client et définissez son identité.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2 lg:col-span-1">
                            <label
                                for="client_id"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Client
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <select
                                id="client_id"
                                v-model="form.client_id"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.client_id"
                                :aria-describedby="form.errors.client_id ? 'client_id-error' : undefined"
                            >
                                <option value="">
                                    Sélectionner un client
                                </option>
                                <option
                                    v-for="client in clients"
                                    :key="client.id"
                                    :value="client.id"
                                >
                                    {{ client.company_name }}
                                </option>
                            </select>

                            <p
                                v-if="form.errors.client_id"
                                id="client_id-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.client_id }}
                            </p>
                        </div>

                        <div class="sm:col-span-2 lg:col-span-1">
                            <label
                                for="name"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Nom de l'installation
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="name"
                                v-model="form.name"
                                name="name"
                                type="text"
                                placeholder="MKD-Pro Gestion"
                                :class="inputClass"
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
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Accès et domaine
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Sous-domaine et domaine public de l'installation.
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="subdomain"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Sous-domaine
                                <span class="text-red-600" aria-hidden="true">*</span>
                            </label>

                            <input
                                id="subdomain"
                                v-model="form.subdomain"
                                name="subdomain"
                                type="text"
                                placeholder="client1.mkd-pro.com"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.subdomain"
                                :aria-describedby="form.errors.subdomain ? 'subdomain-error' : 'subdomain-help'"
                            />

                            <p
                                id="subdomain-help"
                                class="mt-2 text-xs text-gray-500"
                            >
                                Sous-domaine utilisé pour accéder à cette installation.
                            </p>

                            <p
                                v-if="form.errors.subdomain"
                                id="subdomain-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.subdomain }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="domain"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Domaine
                            </label>

                            <input
                                id="domain"
                                v-model="form.domain"
                                name="domain"
                                type="text"
                                placeholder="client1.mkd-pro.com"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.domain"
                                :aria-describedby="form.errors.domain ? 'domain-error' : undefined"
                            />

                            <p
                                v-if="form.errors.domain"
                                id="domain-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.domain }}
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
                                :aria-invalid="!!form.errors.status"
                                :aria-describedby="form.errors.status ? 'status-error' : undefined"
                            >
                                <option value="active">
                                    Actif
                                </option>
                                <option value="inactive">
                                    Inactif
                                </option>
                                <option value="suspended">
                                    Suspendue
                                </option>
                                <option value="terminated">
                                    Terminée
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
                                placeholder="1.0.0"
                                :class="inputClass"
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
                        Base de données
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        Informations de connexion (référence uniquement, sans provisioning).
                    </p>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="database_name"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Nom de la base de données
                            </label>

                            <input
                                id="database_name"
                                v-model="form.database_name"
                                name="database_name"
                                type="text"
                                placeholder="mkdpro_client1"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.database_name"
                                :aria-describedby="form.errors.database_name ? 'database_name-error' : undefined"
                            />

                            <p
                                v-if="form.errors.database_name"
                                id="database_name-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.database_name }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="database_host"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Hôte de la base de données
                            </label>

                            <input
                                id="database_host"
                                v-model="form.database_host"
                                name="database_host"
                                type="text"
                                placeholder="127.0.0.1"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.database_host"
                                :aria-describedby="form.errors.database_host ? 'database_host-error' : undefined"
                            />

                            <p
                                v-if="form.errors.database_host"
                                id="database_host-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.database_host }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Dates
                    </h2>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <label
                                for="installed_at"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Date d'installation
                            </label>

                            <input
                                id="installed_at"
                                v-model="form.installed_at"
                                name="installed_at"
                                type="datetime-local"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.installed_at"
                                :aria-describedby="form.errors.installed_at ? 'installed_at-error' : undefined"
                            />

                            <p
                                v-if="form.errors.installed_at"
                                id="installed_at-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.installed_at }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="last_seen_at"
                                class="block text-sm font-medium text-gray-700"
                            >
                                Dernière activité
                            </label>

                            <input
                                id="last_seen_at"
                                v-model="form.last_seen_at"
                                name="last_seen_at"
                                type="datetime-local"
                                :class="inputClass"
                                :aria-invalid="!!form.errors.last_seen_at"
                                :aria-describedby="form.errors.last_seen_at ? 'last_seen_at-error' : undefined"
                            />

                            <p
                                v-if="form.errors.last_seen_at"
                                id="last_seen_at-error"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ form.errors.last_seen_at }}
                            </p>
                        </div>
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link
                        :href="`/installations/${installation.id}`"
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
