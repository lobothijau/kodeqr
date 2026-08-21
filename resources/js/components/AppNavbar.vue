<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { dashboard } from '@/routes';
import { index as qrCodeIndex } from '@/routes/qr-codes';
import type { User } from '@/types';

const page = usePage();

const user = computed(() => page.props.auth.user as User);

const navigation = computed(() => [
    { label: 'Beranda', href: dashboard.url(), pattern: /^\/dashboard/ },
    { label: 'QR saya', href: qrCodeIndex.url(), pattern: /^\/kode/ },
]);

const isCurrent = (pattern: RegExp) => pattern.test(page.url);

const mobileOpen = ref(false);

// Inertia keeps the component mounted across visits, so a menu opened to navigate
// would still be open on arrival.
watch(
    () => page.url,
    () => {
        mobileOpen.value = false;
    },
);

const initials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join(''),
);
</script>

<template>
    <header class="bg-nav text-nav-foreground">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between gap-4">
                <div class="flex items-center gap-8">
                    <Link
                        :href="dashboard.url()"
                        class="flex items-center gap-2"
                    >
                        <AppLogoIcon
                            class="size-6 fill-current text-nav-foreground"
                        />
                        <span
                            class="text-base font-semibold tracking-tight text-nav-foreground"
                            >kodeqr</span
                        >
                    </Link>

                    <nav class="hidden items-center gap-1 sm:flex">
                        <Link
                            v-for="item in navigation"
                            :key="item.href"
                            :href="item.href"
                            :aria-current="
                                isCurrent(item.pattern) ? 'page' : undefined
                            "
                            :class="[
                                'rounded-md px-3 py-2 text-sm font-medium transition-colors',
                                'focus-visible:ring-2 focus-visible:ring-nav-ring focus-visible:outline-none',
                                isCurrent(item.pattern)
                                    ? 'bg-nav-overlay-strong text-nav-foreground'
                                    : 'text-nav-foreground/70 hover:bg-nav-overlay hover:text-nav-foreground',
                            ]"
                        >
                            {{ item.label }}
                        </Link>
                    </nav>
                </div>

                <div class="flex items-center gap-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="flex items-center rounded-full focus-visible:ring-2 focus-visible:ring-nav-ring focus-visible:outline-none"
                            >
                                <span class="sr-only">Buka menu akun</span>
                                <Avatar class="size-8 bg-nav-overlay-strong">
                                    <AvatarFallback
                                        class="bg-transparent text-xs text-nav-foreground"
                                        >{{ initials }}</AvatarFallback
                                    >
                                </Avatar>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent class="w-56" align="end">
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-nav-foreground hover:bg-nav-overlay-strong hover:text-nav-foreground focus-visible:ring-nav-ring sm:hidden"
                        :aria-expanded="mobileOpen"
                        aria-controls="mobile-navigation"
                        @click="mobileOpen = !mobileOpen"
                    >
                        <!-- The icon flips but the name must too, or a screen reader
                             announces "open menu" on the button that closes it. -->
                        <span class="sr-only">{{
                            mobileOpen
                                ? 'Tutup menu navigasi'
                                : 'Buka menu navigasi'
                        }}</span>
                        <X v-if="mobileOpen" class="size-5" />
                        <Menu v-else class="size-5" />
                    </Button>
                </div>
            </div>
        </div>

        <nav
            v-if="mobileOpen"
            id="mobile-navigation"
            class="border-t border-nav-overlay-strong sm:hidden"
        >
            <div class="space-y-1 px-4 py-3">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    :aria-current="isCurrent(item.pattern) ? 'page' : undefined"
                    :class="[
                        'block rounded-md px-3 py-2 text-base font-medium',
                        'focus-visible:ring-2 focus-visible:ring-nav-ring focus-visible:outline-none',
                        isCurrent(item.pattern)
                            ? 'bg-nav-overlay-strong text-nav-foreground'
                            : 'text-nav-foreground/70 hover:bg-nav-overlay hover:text-nav-foreground',
                    ]"
                >
                    {{ item.label }}
                </Link>
            </div>
        </nav>
    </header>
</template>
