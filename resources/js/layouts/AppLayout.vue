<script setup lang="ts">
import AppNavbarLayout from '@/layouts/app/AppNavbarLayout.vue';
import type { BreadcrumbItem } from '@/types';

/**
 * `app.ts` assigns this to every authenticated page, so the shell is chosen in one
 * place. It used to point at AppSidebarLayout — the starter kit's sidebar — which is
 * why every screen looked like a Laravel boilerplate rather than like this product.
 *
 * `breadcrumbs` is declared and unused on purpose: four starter-kit pages still pass
 * it through `defineOptions({ layout: ... })`, and an undeclared prop would fall
 * through onto the shell's root element as a literal DOM attribute. Declaring it
 * absorbs that until those pages are rewritten — see docs/BACKLOG.md.
 */
defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppNavbarLayout>
        <slot />
    </AppNavbarLayout>
</template>
