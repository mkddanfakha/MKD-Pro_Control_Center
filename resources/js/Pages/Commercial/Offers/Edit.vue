<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    offer: { type: Object, required: true },
    products: { type: Array, default: () => [] },
});

const form = useForm({
    product_id: String(props.offer.product_id),
    code: props.offer.code,
    name: props.offer.name,
    slug: props.offer.slug ?? '',
    description: props.offer.description ?? '',
    status: props.offer.status,
});

const inputClass = 'mt-2 block w-full rounded-lg border px-4 py-3 text-sm';

const submit = () => form.transform((d) => ({ ...d, product_id: Number(d.product_id) })).put(`/commercial/offers/${props.offer.id}`);
</script>

<template>
    <Head title="Modifier offre" />
    <AdminLayout>
        <div class="max-w-2xl">
            <h1 class="text-2xl font-bold">Modifier l'offre</h1>
            <form class="mt-8 space-y-6 rounded-xl bg-white p-6 ring-1 ring-gray-200" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium">Produit</label>
                    <select v-model="form.product_id" :class="inputClass">
                        <option v-for="p in products" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                    </select>
                </div>
                <div><label class="block text-sm font-medium">Code</label><input v-model="form.code" :class="inputClass" /></div>
                <div><label class="block text-sm font-medium">Nom</label><input v-model="form.name" :class="inputClass" /></div>
                <div><label class="block text-sm font-medium">Description</label><textarea v-model="form.description" rows="3" :class="inputClass" /></div>
                <div>
                    <select v-model="form.status" :class="inputClass">
                        <option value="active">Actif</option>
                        <option value="inactive">Inactif</option>
                    </select>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white">Enregistrer</button>
                    <Link :href="`/commercial/offers/${offer.id}`" class="rounded-lg border px-4 py-2 text-sm">Annuler</Link>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
