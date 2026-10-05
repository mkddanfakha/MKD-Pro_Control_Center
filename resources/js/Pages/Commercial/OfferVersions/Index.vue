<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    offerVersions: { type: Object, required: true },
    offers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const filterForm = reactive({
    offer_id: props.filters.offer_id ? String(props.filters.offer_id) : '',
    status: props.filters.status ?? '',
});

function applyFilters() {
    const params = {};
    if (filterForm.offer_id) params.offer_id = filterForm.offer_id;
    if (filterForm.status) params.status = filterForm.status;
    router.get('/commercial/offer-versions', params, { preserveState: true, preserveScroll: true });
}

function formatPrice(price, currency) {
    return `${new Intl.NumberFormat('fr-FR').format(price)} ${currency}`;
}
</script>

<template>
    <Head title="Versions commerciales" />
    <AdminLayout>
        <div>
            <div class="flex justify-between gap-4">
                <h1 class="text-2xl font-bold">Versions commerciales</h1>
                <Link href="/commercial/offer-versions/create" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white">Nouvelle version</Link>
            </div>
            <div v-if="page.flash.success" class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ page.flash.success }}</div>
            <form class="mt-6 flex flex-wrap gap-3" @submit.prevent="applyFilters">
                <select v-model="filterForm.offer_id" class="rounded-lg border px-3 py-2 text-sm">
                    <option value="">Toutes offres</option>
                    <option v-for="o in offers" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
                </select>
                <select v-model="filterForm.status" class="rounded-lg border px-3 py-2 text-sm">
                    <option value="">Tous statuts</option>
                    <option value="draft">Brouillon</option>
                    <option value="active">Active</option>
                    <option value="retired">Retirée</option>
                </select>
                <button type="submit" class="rounded-lg border px-4 py-2 text-sm">Filtrer</button>
            </form>
            <div class="mt-6 overflow-hidden rounded-xl bg-white ring-1 ring-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left">Offre</th>
                            <th class="px-4 py-3 text-left">Code</th>
                            <th class="px-4 py-3 text-left">Version</th>
                            <th class="px-4 py-3 text-left">Prix catalogue</th>
                            <th class="px-4 py-3 text-left">Statut</th>
                            <th class="px-4 py-3 text-left">Début</th>
                            <th class="px-4 py-3 text-left">Fin</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="v in offerVersions.data" :key="v.id">
                            <td class="px-4 py-3">{{ v.offer?.name }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ v.code }}</td>
                            <td class="px-4 py-3">{{ v.version }}</td>
                            <td class="px-4 py-3">{{ formatPrice(v.price, v.currency) }}</td>
                            <td class="px-4 py-3">{{ v.status }}</td>
                            <td class="px-4 py-3 text-xs">{{ v.effective_from }}</td>
                            <td class="px-4 py-3 text-xs">{{ v.effective_until || '—' }}</td>
                            <td class="px-4 py-3 text-right"><Link :href="`/commercial/offer-versions/${v.id}`" class="underline">Voir</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
