<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    offers: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const filterForm = reactive({
    product_id: props.filters.product_id ? String(props.filters.product_id) : '',
    status: props.filters.status ?? '',
});

function applyFilters() {
    const params = {};
    if (filterForm.product_id) params.product_id = filterForm.product_id;
    if (filterForm.status) params.status = filterForm.status;
    router.get('/commercial/offers', params, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Offres commerciales" />
    <AdminLayout>
        <div>
            <div class="flex justify-between gap-4">
                <h1 class="text-2xl font-bold">Offres commerciales</h1>
                <Link href="/commercial/offers/create" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white">Nouvelle offre</Link>
            </div>
            <div v-if="page.flash.success" class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ page.flash.success }}</div>
            <form class="mt-6 flex flex-wrap gap-3" @submit.prevent="applyFilters">
                <select v-model="filterForm.product_id" class="rounded-lg border px-3 py-2 text-sm">
                    <option value="">Tous produits</option>
                    <option v-for="p in products" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                </select>
                <select v-model="filterForm.status" class="rounded-lg border px-3 py-2 text-sm">
                    <option value="">Tous statuts</option>
                    <option value="active">Actif</option>
                    <option value="inactive">Inactif</option>
                </select>
                <button type="submit" class="rounded-lg border px-4 py-2 text-sm">Filtrer</button>
            </form>
            <div class="mt-6 overflow-hidden rounded-xl bg-white ring-1 ring-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left">Code</th>
                            <th class="px-4 py-3 text-left">Nom</th>
                            <th class="px-4 py-3 text-left">Produit</th>
                            <th class="px-4 py-3 text-left">Versions</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="offer in offers.data" :key="offer.id">
                            <td class="px-4 py-3 font-mono text-xs">{{ offer.code }}</td>
                            <td class="px-4 py-3">{{ offer.name }}</td>
                            <td class="px-4 py-3">{{ offer.product?.name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ offer.versions_count ?? 0 }}</td>
                            <td class="px-4 py-3 text-right"><Link :href="`/commercial/offers/${offer.id}`" class="underline">Voir</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
