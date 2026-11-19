<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CostEstimate from '@/components/generations/CostEstimate.vue';
import LiveStatusNotice from '@/components/generations/LiveStatusNotice.vue';
import OptionSelect from '@/components/generations/OptionSelect.vue';
import ReferenceUrlList from '@/components/generations/ReferenceUrlList.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useCostEstimate } from '@/composables/useCostEstimate';
import { create, store } from '@/routes/generations';
import type { GenerationInput, GenerationOptions } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const props = defineProps<{
    options: GenerationOptions;
}>();

const form = useForm<GenerationInput>({
    prompt: '',
    size: props.options.default_size,
    quality: props.options.default_quality,
    reference_image_urls: [],
});

const { estimate, loading, invalid } = useCostEstimate(() => form.data());

/** Server says the user can't start this now: credits (ADR-0011) or a fairness limit. */
const blocked = computed(
    () =>
        estimate.value?.affordable === false ||
        Boolean(estimate.value?.limit_reason),
);

const page = usePage();

function submit(): void {
    form.submit(store());
}
</script>

<template>
    <Head title="Generate" />

    <div
        class="mx-auto grid w-full max-w-5xl gap-6 p-4 lg:grid-cols-[1fr_20rem]"
    >
        <form class="space-y-6" novalidate @submit.prevent="submit">
            <Heading
                title="Generate an image"
                description="Describe what you want. You'll see the price before you submit."
            />

            <LiveStatusNotice
                mode="idle"
                :degraded="page.props.background?.degraded ?? false"
            />

            <div class="grid gap-2">
                <div class="flex items-baseline justify-between">
                    <Label for="prompt">Prompt</Label>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ form.prompt.length }} /
                        {{ options.prompt_max_length }}
                    </span>
                </div>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    v-focus
                    rows="5"
                    required
                    :maxlength="options.prompt_max_length"
                    :aria-invalid="!!form.errors.prompt"
                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                />
                <InputError :message="form.errors.prompt" />
                <p class="text-xs text-muted-foreground">
                    Testing tip: add <code>[fail]</code>, <code>[slow]</code>,
                    <code>[duplicate]</code>, <code>[blocked]</code> or
                    <code>[overloaded]</code> to make Pixelforge misbehave on
                    purpose.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <OptionSelect
                    id="size"
                    v-model="form.size"
                    label="Size"
                    :options="options.sizes"
                    :error="form.errors.size"
                />
                <OptionSelect
                    id="quality"
                    v-model="form.quality"
                    label="Quality"
                    :options="options.qualities"
                    :error="form.errors.quality"
                />
            </div>

            <ReferenceUrlList
                v-model="form.reference_image_urls"
                :max="options.max_reference_images"
                :errors="form.errors"
            />

            <InputError :message="form.errors.credits" />
            <InputError
                :message="
                    form.errors.limit ?? estimate?.limit_reason ?? undefined
                "
            />

            <Button type="submit" :disabled="form.processing || blocked">
                <Spinner v-if="form.processing" />
                Generate
            </Button>
        </form>

        <aside class="lg:pt-16">
            <CostEstimate
                :estimate="estimate"
                :loading="loading"
                :invalid="invalid"
            />
        </aside>
    </div>
</template>
