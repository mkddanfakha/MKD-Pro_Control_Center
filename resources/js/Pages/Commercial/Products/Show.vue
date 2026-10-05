<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({ product: { type: Object, required: true } });
const page = usePage();
</script>

<template>
    <Head :title="product.name" />
    <AdminLayout>
        <div>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">{{ product.name }}</h1>
                    <p class="mt-1 font-mono text-sm text-gray-500">{{ product.code }}</p>
                </div>
                <div class="flex gap-2">
                    <Link :href="`/commercial/products/${product.id}/edit`" class="rounded-lg border px-4 py-2 text-sm">Modifier</Link>
                    <Link href="/commercial/products" class="rounded-lg border px-4 py-2 text-sm">Retour</Link>
                </div>
            </div>
            <div v-if="page.flash.success" class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ page.flash.success }}</div>
            <div v-if="page.flash.error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ page.flash.error }}</div>
            <section class="mt-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                <p class="text-sm text-gray-600">{{ product.description || '—' }}</p>
                <p class="mt-4 text-sm">Statut : <strong>{{ product.status }}</strong></p>
            </section>
            <section class="mt-8">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Offres associées</h2>
                    <Link :href="`/commercial/offers/create?product_id=${product.id}`" class="text-sm underline">Nouvelle offre</Link>
                </div>
                <ul class="divide-y rounded-xl bg-white ring-1 ring-gray-200">
                    <li v-for="offer in product.offers" :key="offer.id" class="flex items-center justify-between px-4 py-3 text-sm">
                        <div>
                            <span class="font-medium">{{ offer.name }}</span>
                            <span class="ml-2 font-mono text-xs text-gray-500">{{ offer.code }}</span>
                        </div>
                        <Link :href="`/commercial/offers/${offer.id}`" class="underline">Voir</Link>
                    </li>
                    <li v-if="!product.offers?.length" class="px-4 py-6 text-sm text-gray-500">Aucune offre.</li>
                </ul>
            </section>
        </div>
    </AdminLayout>
</template>
