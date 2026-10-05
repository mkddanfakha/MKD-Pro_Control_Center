<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    products: { type: Array, default: () => [] },
    selectedProductId: { type: Number, default: null },
});

const form = useForm({
    product_id: props.selectedProductId ? String(props.selectedProductId) : '',
    code: '',
    name: '',
    slug: '',
    description: '',
    status: 'active',
});

const inputClass = 'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm';

const submit = () => form.transform((data) => ({ ...data, product_id: Number(data.product_id) })).post('/commercial/offers');
</script>

<template>
    <Head title="Nouvelle offre" />
    <AdminLayout>
        <div class="max-w-2xl">
            <h1 class="text-2xl font-bold">Nouvelle offre</h1>
            <form class="mt-8 space-y-6 rounded-xl bg-white p-6 ring-1 ring-gray-200" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium">Produit *</label>
                    <select v-model="form.product_id" :class="inputClass">
                        <option value="">Sélectionner</option>
                        <option v-for="p in products" :key="p.id" :value="String(p.id)">{{ p.name }} ({{ p.code }})</option>
                    </select>
                    <p v-if="form.errors.product_id" class="mt-1 text-sm text-red-600">{{ form.errors.product_id }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Code *</label>
                    <input v-model="form.code" type="text" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Nom *</label>
                    <input v-model="form.name" type="text" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Description</label>
                    <textarea v-model="form.description" rows="3" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Statut</label>
                    <select v-model="form.status" :class="inputClass">
                        <option value="active">Actif</option>
                        <option value="inactive">Inactif</option>
                    </select>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white">Enregistrer</button>
                    <Link href="/commercial/offers" class="rounded-lg border px-4 py-2 text-sm">Annuler</Link>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
