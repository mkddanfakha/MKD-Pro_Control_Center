<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    offerVersion: { type: Object, required: true },
    offers: { type: Array, default: () => [] },
});

function linesToArray(text) {
    return text.split('\n').map((l) => l.trim()).filter(Boolean);
}

function arrayToLines(items) {
    return (items ?? []).join('\n');
}

function toDatetimeLocal(value) {
    if (!value) return '';
    return value.replace(' ', 'T').slice(0, 16);
}

const form = useForm({
    offer_id: String(props.offerVersion.offer_id),
    code: props.offerVersion.code,
    version: props.offerVersion.version,
    description: props.offerVersion.description,
    price: props.offerVersion.price,
    currency: props.offerVersion.currency,
    billing_cycle: props.offerVersion.billing_cycle,
    effective_from: toDatetimeLocal(props.offerVersion.effective_from),
    effective_until: toDatetimeLocal(props.offerVersion.effective_until),
    commercial_conditions: props.offerVersion.commercial_conditions ?? '',
    inclusions_text: arrayToLines(props.offerVersion.inclusions),
    limitations_text: arrayToLines(props.offerVersion.limitations),
    exclusions_text: arrayToLines(props.offerVersion.exclusions),
});

const inputClass = 'mt-2 block w-full rounded-lg border px-4 py-3 text-sm';

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
        .put(`/commercial/offer-versions/${props.offerVersion.id}`);
};
</script>

<template>
    <Head title="Modifier version" />
    <AdminLayout>
        <div class="max-w-3xl">
            <h1 class="text-2xl font-bold">Modifier le brouillon</h1>
            <form class="mt-8 space-y-6 rounded-xl bg-white p-6 ring-1 ring-gray-200" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium">Offre</label>
                    <select v-model="form.offer_id" :class="inputClass">
                        <option v-for="o in offers" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
                    </select>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="block text-sm font-medium">Code</label><input v-model="form.code" :class="inputClass" /></div>
                    <div><label class="block text-sm font-medium">Version</label><input v-model="form.version" :class="inputClass" /></div>
                </div>
                <div><label class="block text-sm font-medium">Description</label><textarea v-model="form.description" rows="3" :class="inputClass" /></div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><label class="block text-sm font-medium">Prix catalogue</label><input v-model="form.price" type="number" :class="inputClass" /></div>
                    <div><label class="block text-sm font-medium">Devise</label><input v-model="form.currency" :class="inputClass" /></div>
                    <div><label class="block text-sm font-medium">Cycle</label><input v-model="form.billing_cycle" readonly :class="inputClass" /></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="block text-sm font-medium">Début d'effet</label><input v-model="form.effective_from" type="datetime-local" :class="inputClass" /></div>
                    <div><label class="block text-sm font-medium">Fin d'effet</label><input v-model="form.effective_until" type="datetime-local" :class="inputClass" /></div>
                </div>
                <div v-for="field in ['inclusions', 'limitations', 'exclusions']" :key="field">
                    <label class="block text-sm font-medium capitalize">{{ field }}</label>
                    <textarea v-model="form[`${field}_text`]" rows="5" :class="inputClass" />
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white">Enregistrer</button>
                    <Link :href="`/commercial/offer-versions/${offerVersion.id}`" class="rounded-lg border px-4 py-2 text-sm">Annuler</Link>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
