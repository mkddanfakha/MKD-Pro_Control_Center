<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    offers: { type: Array, default: () => [] },
    selectedOfferId: { type: Number, default: null },
    defaults: { type: Object, default: () => ({}) },
});

function linesToArray(text) {
    return text.split('\n').map((l) => l.trim()).filter(Boolean);
}

const form = useForm({
    offer_id: props.selectedOfferId ? String(props.selectedOfferId) : '',
    code: '',
    version: '',
    description: '',
    price: props.defaults.price ?? 15000,
    currency: props.defaults.currency ?? 'XOF',
    billing_cycle: props.defaults.billing_cycle ?? 'monthly',
    effective_from: '',
    effective_until: '',
    commercial_conditions: '',
    inclusions_text: '',
    limitations_text: '',
    exclusions_text: '',
});

const inputClass = 'mt-2 block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm';

const submit = () => {
    form
        .transform((data) => ({
            offer_id: Number(data.offer_id),
            code: data.code,
            version: data.version,
            description: data.description,
            price: Number(data.price),
            currency: data.currency,
            billing_cycle: data.billing_cycle,
            effective_from: data.effective_from,
            effective_until: data.effective_until || null,
            commercial_conditions: data.commercial_conditions || null,
            inclusions: linesToArray(data.inclusions_text),
            limitations: linesToArray(data.limitations_text),
            exclusions: linesToArray(data.exclusions_text),
        }))
        .post('/commercial/offer-versions');
};
</script>

<template>
    <Head title="Nouvelle version commerciale" />
    <AdminLayout>
        <div class="max-w-3xl">
            <h1 class="text-2xl font-bold">Nouvelle version (brouillon)</h1>
            <p class="mt-1 text-sm text-gray-500">La version sera créée en brouillon. La publication se fait depuis la fiche version.</p>
            <form class="mt-8 space-y-6 rounded-xl bg-white p-6 ring-1 ring-gray-200" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium">Offre *</label>
                    <select v-model="form.offer_id" :class="inputClass">
                        <option value="">Sélectionner</option>
                        <option v-for="o in offers" :key="o.id" :value="String(o.id)">{{ o.name }} ({{ o.code }})</option>
                    </select>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="block text-sm font-medium">Code *</label><input v-model="form.code" :class="inputClass" /></div>
                    <div><label class="block text-sm font-medium">Version *</label><input v-model="form.version" :class="inputClass" /></div>
                </div>
                <div><label class="block text-sm font-medium">Description *</label><textarea v-model="form.description" rows="3" :class="inputClass" /></div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><label class="block text-sm font-medium">Prix catalogue *</label><input v-model="form.price" type="number" min="0" :class="inputClass" /></div>
                    <div><label class="block text-sm font-medium">Devise</label><input v-model="form.currency" maxlength="3" :class="inputClass" /></div>
                    <div><label class="block text-sm font-medium">Cycle</label><input v-model="form.billing_cycle" readonly :class="inputClass" /></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="block text-sm font-medium">Début d'effet *</label><input v-model="form.effective_from" type="datetime-local" :class="inputClass" /></div>
                    <div><label class="block text-sm font-medium">Fin d'effet</label><input v-model="form.effective_until" type="datetime-local" :class="inputClass" /></div>
                </div>
                <div v-for="field in ['inclusions', 'limitations', 'exclusions']" :key="field">
                    <label class="block text-sm font-medium capitalize">{{ field }} (une ligne par élément) *</label>
                    <textarea v-model="form[`${field}_text`]" rows="5" :class="inputClass" />
                </div>
                <div><label class="block text-sm font-medium">Conditions commerciales</label><textarea v-model="form.commercial_conditions" rows="2" :class="inputClass" /></div>
                <div class="flex gap-3">
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white">Créer le brouillon</button>
                    <Link href="/commercial/offer-versions" class="rounded-lg border px-4 py-2 text-sm">Annuler</Link>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
