<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// The destination shape is per type, and the server is the authority on which
// fields it reads — `exclude_unless` drops the ones that do not belong to the
// chosen type, so leaving a stale value in the other field is harmless.
const model = defineModel<{
    type: string;
    url: string;
    phone: string;
    text: string;
}>({ required: true });

defineProps<{
    types: { value: string; label: string }[];
    errors: Record<string, string>;
}>();
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="grid gap-2">
            <Label for="type">Jenis QR</Label>
            <select
                id="type"
                v-model="model.type"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
                <option
                    v-for="type in types"
                    :key="type.value"
                    :value="type.value"
                >
                    {{ type.label }}
                </option>
            </select>
            <InputError :message="errors.type" />
        </div>

        <div v-if="model.type === 'url'" class="grid gap-2">
            <Label for="url">Tautan tujuan</Label>
            <Input
                id="url"
                v-model="model.url"
                type="url"
                inputmode="url"
                placeholder="https://instagram.com/warungmakanbahagia"
            />
            <InputError :message="errors.url" />
        </div>

        <template v-if="model.type === 'whatsapp'">
            <div class="grid gap-2">
                <Label for="phone">Nomor WhatsApp</Label>
                <Input
                    id="phone"
                    v-model="model.phone"
                    inputmode="tel"
                    placeholder="08123456789"
                />
                <p class="text-xs text-muted-foreground">
                    Boleh diawali 0 atau 62 — kami rapikan sendiri.
                </p>
                <InputError :message="errors.phone" />
            </div>
            <div class="grid gap-2">
                <Label for="text">Pesan otomatis (opsional)</Label>
                <Input
                    id="text"
                    v-model="model.text"
                    placeholder="Halo, saya mau pesan…"
                />
                <InputError :message="errors.text" />
            </div>
        </template>
    </div>
</template>
