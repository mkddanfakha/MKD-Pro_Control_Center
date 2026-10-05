<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';

defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, default: () => ({ status: null }) },
});

const page = usePage();
const filterForm = reactive({ status: '' });

function applyFilters() {
    const params = {};
    if (filterForm.status) params.status = filterForm.status;
    router.get('/commercial/products', params, { preserveState: true, preserveScroll: true });
}

function statusLabel(status) {
    return { active: 'Actif', inactive: 'Inactif' }[status] ?? status;
}
</script>

<template>
    <Head title="Produits commerciaux" />
    <AdminLayout>
        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Produits commerciaux</h1>
                    <p class="mt-1 text-sm text-gray-500">Catalogue — identité produit logiciel.</p>
                </div>
                <Link href="/commercial/products/create" class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white">Nouveau produit</Link>
            </div>
            <div v-if="page.flash.success" class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200">{{ page.flash.success }}</div>
            <form class="mt-6 flex flex-wrap gap-3" @submit.prevent="applyFilters">
                <select v-model="filterForm.status" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="">Tous statuts</option>
                    <option value="active">Actif</option>
                    <option value="inactive">Inactif</option>
                </select>
                <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm">Filtrer</button>
            </form>
            <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Code</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Nom</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Statut</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Offres</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="product in products.data" :key="product.id">
                            <td class="px-4 py-3 font-mono text-xs">{{ product.code }}</td>
                            <td class="px-4 py-3">{{ product.name }}</td>
                            <td class="px-4 py-3">{{ statusLabel(product.status) }}</td>
                            <td class="px-4 py-3">{{ product.offers_count ?? 0 }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="`/commercial/products/${product.id}`" class="text-gray-900 underline">Voir</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
