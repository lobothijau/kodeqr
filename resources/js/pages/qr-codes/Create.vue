<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import QrCodeController from '@/actions/App/Http/Controllers/QrCodeController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import QrCodeForm from './Form.vue';

defineProps<{ types: { value: string; label: string }[] }>();

const fields = ref({ type: 'url', url: '', phone: '', text: '' });
</script>

<template>
    <Head title="Buat QR" />

    <div class="flex max-w-xl flex-col gap-6">
        <Heading
            title="Buat QR"
            description="Kode yang dicetak tidak pernah berubah. Tujuannya bisa Anda ubah kapan saja."
        />

        <Form
            v-bind="QrCodeController.store.form()"
            class="flex flex-col gap-6"
            #default="{ errors, processing }"
        >
            <QrCodeForm v-model="fields" :types="types" :errors="errors" />

            <input type="hidden" name="type" :value="fields.type" />
            <input type="hidden" name="url" :value="fields.url" />
            <input type="hidden" name="phone" :value="fields.phone" />
            <input type="hidden" name="text" :value="fields.text" />

            <div>
                <Button type="submit" :disabled="processing">Buat QR</Button>
            </div>
        </Form>
    </div>
</template>
