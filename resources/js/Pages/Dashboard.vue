<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    title: {
        type: String,
        default: 'MKD-Pro Control Center',
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <div>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    Dashboard
                </h1>

                <p class="mt-1 text-sm text-gray-500">
                    Vue d’ensemble de votre écosystème MKD-Pro.
                </p>
            </div>

            <div class="mt-8 grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <p class="text-sm text-gray-500">
                        Clients
                    </p>

                    <p class="mt-2 text-3xl font-bold text-gray-900">
                        0
                    </p>
                </div>

                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <p class="text-sm text-gray-500">
                        Installations
                    </p>

                    <p class="mt-2 text-3xl font-bold text-gray-900">
                        0
                    </p>
                </div>

                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <p class="text-sm text-gray-500">
                        Abonnements actifs
                    </p>

                    <p class="mt-2 text-3xl font-bold text-gray-900">
                        0
                    </p>
                </div>

                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <p class="text-sm text-gray-500">
                        Paiements du mois
                    </p>

                    <p class="mt-2 text-3xl font-bold text-gray-900">
                        0 FCFA
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>