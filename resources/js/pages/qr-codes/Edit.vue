<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import QrCodeController from '@/actions/App/Http/Controllers/QrCodeController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import QrCodeForm from './Form.vue';

const props = defineProps<{
    code: {
        id: string;
        slug: string;
        type: string;
        status: string;
        scan_count: number;
        destination: { url?: string; phone?: string; text?: string };
    };
    types: { value: string; label: string }[];
}>();

const fields = ref({
    type: props.code.type,
    url: props.code.destination.url ?? '',
    phone: props.code.destination.phone ?? '',
    text: props.code.destination.text ?? '',
});
</script>

<template>
    <Head :title="`Ubah ${code.slug}`" />

    <div class="flex max-w-xl flex-col gap-6">
        <Heading
            :title="`Ubah ${code.slug}`"
            description="Perubahan berlaku pada pemindaian berikutnya. Kode yang sudah tercetak tetap berfungsi."
        />

        <!-- The slug is shown and never editable: it is on paper, and paper does not
             get a second draft. -->
        <div class="rounded-lg bg-muted p-4 text-sm">
            <p class="font-medium">
                Kode: <code>{{ code.slug }}</code>
            </p>
            <p class="text-muted-foreground">
                {{ code.scan_count }} pemindaian
            </p>
        </div>

        <Form
            v-bind="QrCodeController.update.form(code.id)"
            class="flex flex-col gap-6"
            #default="{ errors, processing }"
        >
            <QrCodeForm v-model="fields" :types="types" :errors="errors" />

            <input type="hidden" name="type" :value="fields.type" />
            <input type="hidden" name="url" :value="fields.url" />
            <input type="hidden" name="phone" :value="fields.phone" />
            <input type="hidden" name="text" :value="fields.text" />

            <div class="flex gap-2">
                <Button type="submit" :disabled="processing">Simpan</Button>
                <Button
                    type="button"
                    variant="outline"
                    @click="
                        router.delete(QrCodeController.destroy.url(code.id))
                    "
                >
                    Hapus
                </Button>
            </div>
        </Form>
    </div>
</template>
