<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    offer: { type: Object, required: true },
    activeVersion: { type: Object, default: null },
    versionCounts: { type: Object, default: () => ({ draft: 0, active: 0, retired: 0 }) },
});

const page = usePage();

function formatPrice(price, currency) {
    return `${new Intl.NumberFormat('fr-FR').format(price)} ${currency}`;
}
</script>

<template>
    <Head :title="offer.name" />
    <AdminLayout>
        <div>
            <div class="flex flex-wrap justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">{{ offer.name }}</h1>
                    <p class="font-mono text-sm text-gray-500">{{ offer.code }}</p>
                    <p class="mt-1 text-sm text-gray-600">Produit : {{ offer.product?.name }}</p>
                </div>
                <div class="flex gap-2">
                    <Link :href="`/commercial/offers/${offer.id}/edit`" class="rounded-lg border px-4 py-2 text-sm">Modifier</Link>
                    <Link :href="`/commercial/offer-versions/create?offer_id=${offer.id}`" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white">Nouvelle version</Link>
                </div>
            </div>
            <div v-if="page.flash.success" class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ page.flash.success }}</div>
            <p class="mt-6 text-sm">Brouillons : {{ versionCounts.draft }} · Active : {{ versionCounts.active }} · Retirées : {{ versionCounts.retired }}</p>
            <div v-if="activeVersion" class="mt-4 rounded-lg bg-sky-50 px-4 py-3 text-sm ring-1 ring-sky-200">
                Version active :
                <Link :href="`/commercial/offer-versions/${activeVersion.id}`" class="font-medium underline">{{ activeVersion.code }}</Link>
            </div>
            <div class="mt-8 overflow-hidden rounded-xl bg-white ring-1 ring-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left">Code</th>
                            <th class="px-4 py-3 text-left">Version</th>
                            <th class="px-4 py-3 text-left">Prix catalogue</th>
                            <th class="px-4 py-3 text-left">Statut</th>
                            <th class="px-4 py-3 text-left">Effet</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="v in offer.versions" :key="v.id" :class="v.status === 'active' ? 'bg-sky-50/50' : ''">
                            <td class="px-4 py-3 font-mono text-xs">{{ v.code }}</td>
                            <td class="px-4 py-3">{{ v.version }}</td>
                            <td class="px-4 py-3">{{ formatPrice(v.price, v.currency) }}</td>
                            <td class="px-4 py-3">{{ v.status }}</td>
                            <td class="px-4 py-3 text-xs">{{ v.effective_from }} — {{ v.effective_until || '…' }}</td>
                            <td class="px-4 py-3 text-right"><Link :href="`/commercial/offer-versions/${v.id}`" class="underline">Voir</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
