<script setup lang="ts">
import AppNavbar from '@/components/AppNavbar.vue';
import { Toaster } from '@/components/ui/sonner';

/**
 * The application shell: one navbar, one centred column.
 *
 * Replaces the starter kit's sidebar, which was rejected by the owner. A sidebar
 * spends a fixed slice of every screen on navigation that has two destinations, and
 * on the builder — the screen this product is actually about — it competes for width
 * with the live preview that has to sit beside the form.
 *
 * Breadcrumbs are gone with it. Two levels deep is not a trail worth drawing.
 */
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <AppNavbar />

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>

        <Toaster />
    </div>
</template>
