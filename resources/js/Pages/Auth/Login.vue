<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Connexion" />

    <div class="flex min-h-screen items-center justify-center bg-gray-100 px-4">
        <div class="w-full max-w-md">
            <div class="rounded-2xl bg-white p-8 shadow-lg">
                <div class="mb-8 text-center">
                    <h1 class="text-2xl font-bold text-gray-900">
                        MKD-Pro
                    </h1>

                    <p class="mt-1 text-sm text-gray-500">
                        Control Center
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-6">
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
                            autocomplete="username"
                            required
                            autofocus
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        />

                        <p
                            v-if="form.errors.email"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <div>
                        <label
                            for="password"
                            class="block text-sm font-medium text-gray-700"
                        >
                            Mot de passe
                        </label>

                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-200"
                        />

                        <p
                            v-if="form.errors.password"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <div class="flex items-center">
                        <input
                            id="remember"
                            v-model="form.remember"
                            type="checkbox"
                            class="h-4 w-4 rounded border-gray-300"
                        />

                        <label
                            for="remember"
                            class="ml-2 text-sm text-gray-600"
                        >
                            Se souvenir de moi
                        </label>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg bg-gray-900 px-4 py-3 font-semibold text-white transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{
                            form.processing
                                ? 'Connexion...'
                                : 'Se connecter'
                        }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>