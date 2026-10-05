<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
    name: '',
    slug: '',
    description: '',
    status: 'active',
});

const inputClass = 'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm';

const submit = () => form.post('/commercial/products');
</script>

<template>
    <Head title="Nouveau produit" />
    <AdminLayout>
        <div class="max-w-2xl">
            <h1 class="text-2xl font-bold">Nouveau produit</h1>
            <form class="mt-8 space-y-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium">Code *</label>
                    <input v-model="form.code" type="text" :class="inputClass" />
                    <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">{{ form.errors.code }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Nom *</label>
                    <input v-model="form.name" type="text" :class="inputClass" />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Slug</label>
                    <input v-model="form.slug" type="text" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Description</label>
                    <textarea v-model="form.description" rows="4" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Statut *</label>
                    <select v-model="form.status" :class="inputClass">
                        <option value="active">Actif</option>
                        <option value="inactive">Inactif</option>
                    </select>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white" :disabled="form.processing">Enregistrer</button>
                    <Link href="/commercial/products" class="rounded-lg border px-4 py-2 text-sm">Annuler</Link>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
