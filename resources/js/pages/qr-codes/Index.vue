<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import QrCodeController from '@/actions/App/Http/Controllers/QrCodeController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Status = 'active' | 'paused' | 'blocked' | 'over_quota';

interface QrCodeRow {
    id: string;
    slug: string;
    type: string;
    status: Status;
    scan_count: number;
    destination: { dest_url?: string };
    last_scanned_at: string | null;
}

const props = defineProps<{
    codes: QrCodeRow[];
    quota: { used: number; limit: number | null };
    canCreate: boolean;
    canEdit: boolean;
    scanBaseUrl: string;
    statusLabels: Record<Status, string>;
    // upgrade_to is null on Business: there is nothing left to sell.
    quotaReached: { message: string; upgrade_to: string | null } | null;
    status: string | null;
}>();

// null means unlimited, which is a real plan value rather than a missing one.
const quotaLabel = computed(() =>
    props.quota.limit === null
        ? `${props.quota.used} kode`
        : `${props.quota.used} dari ${props.quota.limit} kode terpakai`,
);

// Only these two are the owner's to change. `blocked` is an abuse decision and
// `over_quota` a billing one — neither is undone from this screen.
const pausable = (status: Status) => status === 'active' || status === 'paused';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const statusVariant: Record<Status, BadgeVariant> = {
    active: 'default',
    paused: 'secondary',
    blocked: 'destructive',
    over_quota: 'destructive',
};

const scanUrl = (slug: string) => `${props.scanBaseUrl}/x/${slug}`;
</script>

<template>
    <Head title="QR saya" />

    <div class="flex flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <Heading title="QR saya" :description="quotaLabel" />
            <Button v-if="canCreate" as-child>
                <Link :href="QrCodeController.create.url()">Buat QR</Link>
            </Button>
        </div>

        <div
            v-if="status"
            class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
        >
            {{ status }}
        </div>

        <div
            v-if="quotaReached"
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"
        >
            <p>{{ quotaReached.message }}</p>
            <!-- The payload was being assembled server-side and dropped here, so the
                 plan-gated refusal showed a message with no way to act on it. -->
            <Button
                v-if="quotaReached.upgrade_to"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="`/harga?paket=${quotaReached.upgrade_to}`"
                    >Naikkan paket</Link
                >
            </Button>
        </div>

        <p v-if="codes.length === 0" class="text-sm text-muted-foreground">
            Belum ada QR. Buat satu, lalu cetak — tujuannya bisa diubah kapan
            saja tanpa mencetak ulang.
        </p>

        <ul v-else class="divide-y divide-border rounded-lg border">
            <li
                v-for="code in codes"
                :key="code.id"
                class="flex flex-wrap items-center gap-3 p-4"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <code class="text-sm font-semibold">{{
                            code.slug
                        }}</code>
                        <Badge :variant="statusVariant[code.status]">{{
                            statusLabels[code.status]
                        }}</Badge>
                    </div>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ code.destination.dest_url }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ scanUrl(code.slug) }}
                    </p>
                </div>

                <div class="text-right">
                    <p class="text-sm font-medium">{{ code.scan_count }}</p>
                    <p class="text-xs text-muted-foreground">pemindaian</p>
                </div>

                <div class="flex gap-2">
                    <Button v-if="canEdit" variant="outline" size="sm" as-child>
                        <Link :href="QrCodeController.edit.url(code.id)"
                            >Ubah</Link
                        >
                    </Button>
                    <Button
                        v-if="canEdit && pausable(code.status)"
                        variant="outline"
                        size="sm"
                        @click="
                            router.post(
                                QrCodeController.togglePause.url(code.id),
                            )
                        "
                    >
                        {{ code.status === 'active' ? 'Jeda' : 'Aktifkan' }}
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
