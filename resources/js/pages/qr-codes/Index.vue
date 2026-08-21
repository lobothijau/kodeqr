<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Pause,
    Pencil,
    Play,
    Plus,
    QrCode as QrCodeIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import QrCodeController from '@/actions/App/Http/Controllers/QrCodeController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { image as qrImage } from '@/routes/qr-codes';

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

const quotaFraction = computed(() => {
    if (props.quota.limit === null) {
        return 0;
    }

    // A lapsed plan has max_codes 0 while the owner still holds codes that keep
    // redirecting (constraint 8). An empty bar next to "5 dari 0" reads as a bug.
    if (props.quota.limit === 0) {
        return props.quota.used > 0 ? 100 : 0;
    }

    return Math.min(100, (props.quota.used / props.quota.limit) * 100);
});

// Only these two are the owner's to change. `blocked` is an abuse decision and
// `over_quota` a billing one — neither is undone from this screen.
const pausable = (status: Status) => status === 'active' || status === 'paused';

const statusClass: Record<Status, string> = {
    active: 'border-emerald-600/20 bg-emerald-50 text-emerald-700 dark:border-emerald-400/20 dark:bg-emerald-950 dark:text-emerald-300',
    paused: 'border-amber-600/20 bg-amber-50 text-amber-700 dark:border-amber-400/20 dark:bg-amber-950 dark:text-amber-300',
    blocked:
        'border-red-600/20 bg-red-50 text-red-700 dark:border-red-400/20 dark:bg-red-950 dark:text-red-300',
    over_quota:
        'border-red-600/20 bg-red-50 text-red-700 dark:border-red-400/20 dark:bg-red-950 dark:text-red-300',
};

const scanUrl = (slug: string) => `${props.scanBaseUrl}/x/${slug}`;

const copiedSlug = ref<string | null>(null);

/**
 * `navigator.clipboard` is undefined outside a secure context and in several in-app
 * browsers — WhatsApp and Instagram webviews among them, which is exactly where an
 * Indonesian owner is most likely to be. Swallowing that leaves a button that does
 * nothing, with the full URL nowhere on screen to copy by hand, so there is a
 * fallback and, failing that, a visible refusal.
 */
const copy = async (slug: string) => {
    const url = scanUrl(slug);

    const markCopied = () => {
        copiedSlug.value = slug;
        setTimeout(() => {
            if (copiedSlug.value === slug) {
                copiedSlug.value = null;
            }
        }, 2000);
    };

    try {
        await navigator.clipboard.writeText(url);
        markCopied();

        return;
    } catch {
        // Falls through to the legacy path below.
    }

    try {
        const field = document.createElement('textarea');
        field.value = url;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        const copied = document.execCommand('copy');
        document.body.removeChild(field);

        if (copied) {
            markCopied();

            return;
        }
    } catch {
        // Falls through to the message below.
    }

    toast.error(`Tidak bisa menyalin otomatis. Alamatnya: ${url}`);
};

const numberFormat = new Intl.NumberFormat('id-ID');
</script>

<template>
    <Head title="QR saya" />

    <div class="flex flex-col gap-8">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="space-y-1">
                <h1
                    class="text-2xl font-semibold tracking-tight text-foreground"
                >
                    QR saya
                </h1>
                <p class="text-sm text-muted-foreground">
                    Ubah tujuan kapan saja — kode yang sudah dicetak tetap
                    berlaku.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <div v-if="quota.limit !== null" class="hidden sm:block">
                    <p class="text-right text-xs text-muted-foreground">
                        {{ quotaLabel }}
                    </p>
                    <div
                        class="mt-1.5 h-1.5 w-32 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full bg-foreground transition-all"
                            :style="{ width: `${quotaFraction}%` }"
                        />
                    </div>
                </div>

                <Button v-if="canCreate" as-child>
                    <Link :href="QrCodeController.create.url()">
                        <Plus class="size-4" />
                        Buat QR
                    </Link>
                </Button>
            </div>
        </div>

        <div
            v-if="status"
            class="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-foreground"
        >
            {{ status }}
        </div>

        <div
            v-if="quotaReached"
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-600/20 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-400/20 dark:bg-amber-950 dark:text-amber-100"
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

        <!-- The empty state teaches the one thing that makes this product worth
             paying for, because a first-time owner has no reason to know it. -->
        <div
            v-if="codes.length === 0"
            class="flex flex-col items-center rounded-xl border border-dashed border-border px-6 py-16 text-center"
        >
            <div
                class="flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground"
            >
                <QrCodeIcon class="size-6" />
            </div>
            <h2 class="mt-4 text-base font-semibold text-foreground">
                Belum ada QR
            </h2>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Buat satu, lalu cetak. Tujuannya bisa diubah kapan saja tanpa
                mencetak ulang, dan setiap pemindaian tercatat.
            </p>
            <Button v-if="canCreate" class="mt-6" as-child>
                <Link :href="QrCodeController.create.url()">
                    <Plus class="size-4" />
                    Buat QR pertama
                </Link>
            </Button>
        </div>

        <ul v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="code in codes"
                :key="code.id"
                class="flex flex-col overflow-hidden rounded-xl border border-border bg-card transition-shadow hover:shadow-sm"
            >
                <div class="flex gap-4 p-4">
                    <!-- The picture is the point. A row of slugs was the whole of
                         the owner's complaint about this screen. -->
                    <img
                        :src="qrImage.url(code.id, { query: { size: 160 } })"
                        :alt="`QR ${code.slug}`"
                        width="80"
                        height="80"
                        loading="lazy"
                        class="size-20 shrink-0 rounded-lg border border-border bg-white"
                    />

                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <code
                                class="font-mono text-sm font-semibold text-foreground"
                                >{{ code.slug }}</code
                            >
                            <Badge
                                variant="outline"
                                :class="statusClass[code.status]"
                                >{{ statusLabels[code.status] }}</Badge
                            >
                        </div>

                        <p
                            class="mt-1 truncate text-sm text-muted-foreground"
                            :title="code.destination.dest_url"
                        >
                            {{ code.destination.dest_url }}
                        </p>

                        <p class="mt-2 text-xs text-muted-foreground">
                            <span class="font-medium text-foreground">{{
                                numberFormat.format(code.scan_count)
                            }}</span>
                            pemindaian
                        </p>
                    </div>
                </div>

                <div
                    class="mt-auto flex items-center justify-between gap-2 border-t border-border bg-muted/30 px-4 py-2.5"
                >
                    <button
                        type="button"
                        class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
                        @click="copy(code.slug)"
                    >
                        <Check
                            v-if="copiedSlug === code.slug"
                            class="size-3.5 shrink-0 text-emerald-600"
                        />
                        <Copy v-else class="size-3.5 shrink-0" />
                        <span class="truncate" :title="scanUrl(code.slug)">{{
                            copiedSlug === code.slug
                                ? 'Tersalin'
                                : `/x/${code.slug}`
                        }}</span>
                    </button>

                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            v-if="canEdit && pausable(code.status)"
                            variant="ghost"
                            size="icon"
                            :title="
                                code.status === 'active' ? 'Jeda' : 'Aktifkan'
                            "
                            @click="
                                router.post(
                                    QrCodeController.togglePause.url(code.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            "
                        >
                            <Pause
                                v-if="code.status === 'active'"
                                class="size-4"
                            />
                            <Play v-else class="size-4" />
                            <span class="sr-only">{{
                                code.status === 'active' ? 'Jeda' : 'Aktifkan'
                            }}</span>
                        </Button>
                        <Button
                            v-if="canEdit"
                            variant="ghost"
                            size="icon"
                            title="Ubah"
                            as-child
                        >
                            <Link :href="QrCodeController.edit.url(code.id)">
                                <Pencil class="size-4" />
                                <span class="sr-only">Ubah</span>
                            </Link>
                        </Button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
