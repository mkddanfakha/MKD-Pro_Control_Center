<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    offerVersion: { type: Object, required: true },
});

const page = usePage();
const showPublishConfirm = ref(false);
const showRetireConfirm = ref(false);

const publishForm = useForm({});
const retireForm = useForm({});

function formatPrice(price, currency) {
    return `${new Intl.NumberFormat('fr-FR').format(price)} ${currency}`;
}

function confirmPublish() {
    publishForm.post(`/commercial/offer-versions/${props.offerVersion.id}/publish`, {
        onFinish: () => { showPublishConfirm.value = false; },
    });
}

function confirmRetire() {
    retireForm.post(`/commercial/offer-versions/${props.offerVersion.id}/retire`, {
        onFinish: () => { showRetireConfirm.value = false; },
    });
}
</script>

<template>
    <Head :title="offerVersion.code" />
    <AdminLayout>
        <div>
            <div class="flex flex-wrap justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">{{ offerVersion.code }}</h1>
                    <p class="text-sm text-gray-500">Version {{ offerVersion.version }} · {{ offerVersion.status }}</p>
                    <p class="mt-1 text-sm">{{ offerVersion.offer?.product?.name }} → {{ offerVersion.offer?.name }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link v-if="offerVersion.status === 'draft'" :href="`/commercial/offer-versions/${offerVersion.id}/edit`" class="rounded-lg border px-4 py-2 text-sm">Modifier</Link>
                    <button v-if="offerVersion.status === 'draft'" type="button" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white" @click="showPublishConfirm = true">Publier</button>
                    <button v-if="offerVersion.status === 'active'" type="button" class="rounded-lg bg-amber-700 px-4 py-2 text-sm text-white" @click="showRetireConfirm = true">Retirer</button>
                    <Link href="/commercial/offer-versions" class="rounded-lg border px-4 py-2 text-sm">Retour</Link>
                </div>
            </div>
            <div v-if="page.flash.success" class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ page.flash.success }}</div>
            <div v-if="page.flash.error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ page.flash.error }}</div>
            <p v-if="offerVersion.status === 'active' || offerVersion.status === 'retired'" class="mt-4 text-sm text-amber-800">Cette version est immuable (publiée ou historique).</p>
            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl bg-white p-6 ring-1 ring-gray-200">
                    <h2 class="font-semibold">Commercial</h2>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div><dt class="text-gray-500">Prix catalogue</dt><dd>{{ formatPrice(offerVersion.price, offerVersion.currency) }} / {{ offerVersion.billing_cycle }}</dd></div>
                        <div><dt class="text-gray-500">Début d'effet</dt><dd>{{ offerVersion.effective_from }}</dd></div>
                        <div><dt class="text-gray-500">Fin d'effet</dt><dd>{{ offerVersion.effective_until || '—' }}</dd></div>
                    </dl>
                    <p class="mt-4 text-sm">{{ offerVersion.description }}</p>
                </div>
                <div class="rounded-xl bg-white p-6 ring-1 ring-gray-200">
                    <h2 class="font-semibold">Conditions</h2>
                    <p class="mt-2 text-sm text-gray-600">{{ offerVersion.commercial_conditions || '—' }}</p>
                </div>
            </section>
            <section v-for="block in [{ key: 'inclusions', label: 'Inclusions' }, { key: 'limitations', label: 'Limitations' }, { key: 'exclusions', label: 'Exclusions' }]" :key="block.key" class="mt-6 rounded-xl bg-white p-6 ring-1 ring-gray-200">
                <h2 class="font-semibold">{{ block.label }}</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                    <li v-for="(item, i) in offerVersion[block.key]" :key="i">{{ item }}</li>
                </ul>
            </section>
        </div>

        <div v-if="showPublishConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="max-w-lg rounded-xl bg-white p-6 shadow-lg">
                <h3 class="text-lg font-semibold">Confirmer la publication</h3>
                <p class="mt-2 text-sm text-gray-600">Cette action rend la version <strong>active</strong> et <strong>immuable</strong>. Le contenu commercial ne pourra plus être modifié.</p>
                <ul class="mt-4 space-y-1 text-sm">
                    <li>Offre : {{ offerVersion.offer?.name }}</li>
                    <li>Code : {{ offerVersion.code }}</li>
                    <li>Version : {{ offerVersion.version }}</li>
                    <li>Prix catalogue : {{ formatPrice(offerVersion.price, offerVersion.currency) }}</li>
                    <li>Cycle : {{ offerVersion.billing_cycle }}</li>
                    <li>Début d'effet : {{ offerVersion.effective_from }}</li>
                </ul>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="showPublishConfirm = false">Annuler</button>
                    <button type="button" class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white" :disabled="publishForm.processing" @click="confirmPublish">Publier</button>
                </div>
            </div>
        </div>

        <div v-if="showRetireConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="max-w-lg rounded-xl bg-white p-6 shadow-lg">
                <h3 class="text-lg font-semibold">Confirmer le retrait</h3>
                <p class="mt-2 text-sm text-gray-600">Le retrait termine la version dans le catalogue actif tout en la conservant dans l'historique (immuable).</p>
                <ul class="mt-4 space-y-1 text-sm">
                    <li>Code : {{ offerVersion.code }}</li>
                    <li>Prix catalogue : {{ formatPrice(offerVersion.price, offerVersion.currency) }}</li>
                    <li>Statut actuel : {{ offerVersion.status }}</li>
                    <li>Début d'effet : {{ offerVersion.effective_from }}</li>
                </ul>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="showRetireConfirm = false">Annuler</button>
                    <button type="button" class="rounded-lg bg-amber-700 px-4 py-2 text-sm text-white" :disabled="retireForm.processing" @click="confirmRetire">Retirer</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
