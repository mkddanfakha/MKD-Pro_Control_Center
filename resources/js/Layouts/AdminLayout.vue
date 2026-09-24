<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const mobileMenuOpen = ref(false);

const user = computed(() => page.props.auth?.user);

function isNavItemActive(item) {
    if (item.activePath) {
        return page.url.split('?')[0] === item.activePath;
    }

    return page.url === item.href;
}

const navigation = [
    {
        label: 'Dashboard',
        href: '/dashboard',
    },
    {
        label: 'Clients',
        href: '/clients',
    },
    {
        label: 'Installations',
        href: '/installations',
    },
    {
        label: 'Abonnements',
        href: '/subscriptions',
    },
    {
        label: 'Paiements',
        href: '/payments',
    },
    {
        label: 'Modules',
        href: '/modules',
    },
    {
        label: 'Affectations des modules',
        href: '/installation-modules',
    },
    {
        label: "Journal d'audit",
        href: '/audit-logs',
        activePath: '/audit-logs',
    },
    {
        label: 'Sauvegardes',
        href: '/backups',
    },
];


</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <!-- Mobile overlay -->
        <div
            v-if="mobileMenuOpen"
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            @click="mobileMenuOpen = false"
        />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-gray-950 text-white transition-transform duration-200 lg:translate-x-0"
            :class="mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center border-b border-white/10 px-6">
                <div>
                    <div class="text-lg font-bold">
                        MKD-Pro
                    </div>

                    <div class="text-xs text-gray-400">
                        Control Center
                    </div>
                </div>

                <button
                    type="button"
                    class="ml-auto rounded-lg p-2 text-gray-400 hover:bg-white/10 hover:text-white lg:hidden"
                    @click="mobileMenuOpen = false"
                >
                    ✕
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto p-4">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    class="block rounded-lg px-4 py-3 text-sm font-medium transition"
                    :class="
                        isNavItemActive(item)
                            ? 'bg-white text-gray-950'
                            : 'text-gray-300 hover:bg-white/10 hover:text-white'
                    "
                    @click="mobileMenuOpen = false"
                >
                    {{ item.label }}
                </Link>
            </nav>

            <div class="border-t border-white/10 p-4">
                <div class="mb-3 truncate text-sm font-medium">
                    {{ user?.name }}
                </div>

                <div class="mb-4 truncate text-xs text-gray-400">
                    {{ user?.email }}
                </div>

                <form method="POST" action="/logout">
    <input
        type="hidden"
        name="_token"
        :value="page.props.csrf_token"
    />

    <button
        type="submit"
        class="w-full rounded-lg bg-white/5 px-4 py-2.5 text-left text-sm font-medium text-gray-300 transition hover:bg-white/10 hover:text-white"
    >
        Se déconnecter
    </button>
</form>
            </div>
        </aside>

        <!-- Main -->
        <div class="lg:pl-72">
            <!-- Top bar -->
            <header
                class="sticky top-0 z-30 flex h-16 items-center border-b border-gray-200 bg-white px-4 shadow-sm sm:px-6"
            >
                <button
                    type="button"
                    class="rounded-lg p-2 text-gray-600 hover:bg-gray-100 lg:hidden"
                    @click="mobileMenuOpen = true"
                >
                    ☰
                </button>

                <div class="ml-3 lg:ml-0">
                    <div class="text-sm font-semibold text-gray-900">
                        MKD-Pro Control Center
                    </div>

                    <div class="text-xs text-gray-500">
                        Administration
                    </div>
                </div>

                <div class="ml-auto hidden text-right sm:block">
                    <div class="text-sm font-medium text-gray-900">
                        {{ user?.name }}
                    </div>

                    <div class="text-xs text-gray-500">
                        Administrateur
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>