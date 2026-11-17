<script setup lang="ts">
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationImage from '@/components/generations/GenerationImage.vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatTokens, formatUsd, promptCharacterCount } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import type { CostEstimate, Generation, GenerationOptions } from '@/types/generation';
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ImagePlus, Info, LoaderCircle, Plus, Sparkles, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    options: GenerationOptions;
    recentGenerations: { data: Generation[] };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/dashboard' }];

const preferredOption = (available: string[], preferred: string): string => (available.includes(preferred) ? preferred : (available[0] ?? ''));

const form = useForm({
    prompt: '',
    size: preferredOption(props.options.sizes, '1024x1024'),
    quality: preferredOption(props.options.qualities, 'medium'),
    reference_image_urls: [] as string[],
});

const formErrors = computed(() => form.errors as Record<string, string | undefined>);
const promptCharacters = computed(() => promptCharacterCount(form.prompt));
const filledReferenceUrls = computed(() => form.reference_image_urls.filter((url) => url.trim() !== ''));
const canAddReference = computed(() => form.reference_image_urls.length < props.options.max_reference_images);

const estimate = ref<CostEstimate | null>(null);
const estimateLoading = ref(false);
const estimateError = ref<string | null>(null);
let estimateRequest: AbortController | null = null;

async function loadEstimate(): Promise<void> {
    if (!props.options.available) {
        return;
    }

    estimateRequest?.abort();
    estimateRequest = new AbortController();
    estimateLoading.value = true;

    const query = new URLSearchParams({
        prompt_characters: String(promptCharacters.value),
        size: form.size,
        quality: form.quality,
        reference_image_count: String(filledReferenceUrls.value.length),
    });

    try {
        const response = await fetch(`${route('generations.pricing_estimate')}?${query}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: estimateRequest.signal,
        });

        if (!response.ok) {
            throw new Error('Could not calculate an estimate.');
        }

        estimate.value = (await response.json()).data as CostEstimate;
        estimateError.value = null;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        estimateError.value = 'Could not calculate an estimate right now.';
    } finally {
        estimateLoading.value = false;
    }
}

const debouncedLoadEstimate = useDebounceFn(loadEstimate, 300);

watch([promptCharacters, () => form.size, () => form.quality, () => filledReferenceUrls.value.length], () => debouncedLoadEstimate());

onMounted(loadEstimate);

function addReferenceUrl(): void {
    if (canAddReference.value) {
        form.reference_image_urls.push('');
    }
}

function removeReferenceUrl(index: number): void {
    form.reference_image_urls.splice(index, 1);
}

function submit(): void {
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_image_urls'),
    });
}

const activeGeneration = computed(() => props.recentGenerations.data.find((generation) => generation.is_in_progress) ?? null);

const { start: startPolling, stop: stopPolling } = usePoll(3000, { only: ['recentGenerations'] }, { autoStart: false });

watch(activeGeneration, (generation) => (generation ? startPolling() : stopPolling()), { immediate: true });

const lifecycleSteps = [
    { status: 'pending', label: 'Submitted' },
    { status: 'queued', label: 'Queued' },
    { status: 'processing', label: 'Generating' },
];

function stepReached(stepStatus: string, current: string): boolean {
    const order = ['pending', 'queued', 'processing', 'succeeded', 'failed'];

    return order.indexOf(current) >= order.indexOf(stepStatus);
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Generate an image</h1>
                <p class="text-sm text-muted-foreground">Describe what you want to see. Generation runs in the background and appears below.</p>
            </div>

            <div
                v-if="!options.available"
                class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                role="alert"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                The image provider's pricing could not be loaded, so generation is unavailable right now. Please refresh in a moment.
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-lg"><Sparkles class="size-5" aria-hidden="true" /> Prompt</CardTitle>
                        <CardDescription>Be specific about subject, style, lighting and composition.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="space-y-6" @submit.prevent="submit">
                            <div class="grid gap-2">
                                <div class="flex items-center justify-between">
                                    <Label for="prompt">Prompt</Label>
                                    <span
                                        class="text-xs tabular-nums text-muted-foreground"
                                        :class="{ 'text-red-600': promptCharacters > options.max_prompt_length }"
                                    >
                                        {{ promptCharacters }} / {{ options.max_prompt_length }}
                                    </span>
                                </div>
                                <Textarea
                                    id="prompt"
                                    v-model="form.prompt"
                                    rows="5"
                                    required
                                    :maxlength="options.max_prompt_length"
                                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                                    aria-describedby="prompt-error"
                                />
                                <InputError id="prompt-error" :message="form.errors.prompt" />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="size">Size</Label>
                                    <select
                                        id="size"
                                        v-model="form.size"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    >
                                        <option v-for="size in options.sizes" :key="size" :value="size">{{ size.replace('x', ' × ') }}</option>
                                    </select>
                                    <InputError :message="form.errors.size" />
                                </div>

                                <div class="grid gap-2">
                                    <Label id="quality-label">Quality</Label>
                                    <div class="grid grid-cols-3 gap-1 rounded-md border p-1" role="radiogroup" aria-labelledby="quality-label">
                                        <button
                                            v-for="quality in options.qualities"
                                            :key="quality"
                                            type="button"
                                            role="radio"
                                            :aria-checked="form.quality === quality"
                                            class="rounded px-2 py-1.5 text-sm capitalize transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            :class="form.quality === quality ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                                            @click="form.quality = quality"
                                        >
                                            {{ quality }}
                                        </button>
                                    </div>
                                    <InputError :message="form.errors.quality" />
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <div class="flex items-center justify-between">
                                    <Label>Reference images <span class="font-normal text-muted-foreground">(optional)</span></Label>
                                    <span class="text-xs text-muted-foreground"
                                        >{{ form.reference_image_urls.length }} / {{ options.max_reference_images }}</span
                                    >
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Public http(s) image URLs that guide the style. Each one adds a fixed number of input tokens.
                                </p>

                                <div v-for="(_, index) in form.reference_image_urls" :key="index" class="grid gap-1">
                                    <div class="flex gap-2">
                                        <Input
                                            v-model="form.reference_image_urls[index]"
                                            type="url"
                                            inputmode="url"
                                            placeholder="https://example.com/style.png"
                                            :aria-label="`Reference image URL ${index + 1}`"
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            :aria-label="`Remove reference image ${index + 1}`"
                                            @click="removeReferenceUrl(index)"
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    <InputError :message="formErrors[`reference_image_urls.${index}`]" />
                                </div>

                                <InputError :message="form.errors.reference_image_urls" />

                                <div>
                                    <Button type="button" variant="outline" size="sm" :disabled="!canAddReference" @click="addReferenceUrl">
                                        <Plus /> Add reference URL
                                    </Button>
                                </div>
                            </div>

                            <InputError :message="formErrors.generation" />

                            <Button
                                type="submit"
                                size="lg"
                                class="w-full sm:w-auto"
                                :disabled="form.processing || !options.available || promptCharacters === 0"
                            >
                                <LoaderCircle v-if="form.processing" class="animate-spin" />
                                <ImagePlus v-else />
                                Generate image
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-6">
                    <Card>
                        <CardHeader class="pb-3">
                            <CardTitle class="text-lg">Estimated price</CardTitle>
                            <CardDescription>Updates as you type.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div v-if="!estimate && estimateLoading" class="space-y-2">
                                <Skeleton class="h-8 w-40" />
                                <Skeleton class="h-4 w-full" />
                                <Skeleton class="h-4 w-3/4" />
                            </div>

                            <p v-else-if="estimateError" class="text-sm text-red-600 dark:text-red-400" role="alert">{{ estimateError }}</p>

                            <template v-else-if="estimate">
                                <div :class="{ 'opacity-60': estimateLoading }" aria-live="polite">
                                    <p class="text-3xl font-semibold tabular-nums">
                                        {{ formatUsd(estimate.minimum.user_cost) }} – {{ formatUsd(estimate.maximum.user_cost) }}
                                    </p>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        You pay the actual cost once the image is ready, never more than the upper bound.
                                    </p>
                                </div>

                                <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                                    <dt class="text-muted-foreground">Input text tokens</dt>
                                    <dd class="text-right tabular-nums">
                                        {{ formatTokens(estimate.minimum.tokens.input_text_tokens) }}–{{
                                            formatTokens(estimate.maximum.tokens.input_text_tokens)
                                        }}
                                    </dd>
                                    <dt class="text-muted-foreground">Input image tokens</dt>
                                    <dd class="text-right tabular-nums">{{ formatTokens(estimate.maximum.tokens.input_image_tokens) }}</dd>
                                    <dt class="text-muted-foreground">Output tokens</dt>
                                    <dd class="text-right tabular-nums">{{ formatTokens(estimate.maximum.tokens.output_tokens) }}</dd>
                                    <dt class="text-muted-foreground">Reasoning tokens</dt>
                                    <dd class="text-right tabular-nums">
                                        {{ formatTokens(estimate.minimum.tokens.reasoning_tokens) }}–{{
                                            formatTokens(estimate.maximum.tokens.reasoning_tokens)
                                        }}
                                    </dd>
                                    <dt class="border-t pt-1.5 text-muted-foreground">Provider cost</dt>
                                    <dd class="border-t pt-1.5 text-right tabular-nums">
                                        {{ formatUsd(estimate.minimum.provider_cost) }} – {{ formatUsd(estimate.maximum.provider_cost) }}
                                    </dd>
                                    <dt class="text-muted-foreground">Service fee</dt>
                                    <dd class="text-right tabular-nums">{{ Number(estimate.markup_percentage) }}%</dd>
                                </dl>

                                <p class="flex gap-2 rounded-md bg-muted p-3 text-xs text-muted-foreground">
                                    <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                    This is an estimate. Text and reasoning token counts are only known once the image is generated. Failed
                                    generations are free.
                                </p>
                            </template>
                        </CardContent>
                    </Card>

                    <Card v-if="activeGeneration">
                        <CardHeader class="pb-3">
                            <div class="flex items-center justify-between gap-2">
                                <CardTitle class="text-lg">In progress</CardTitle>
                                <GenerationStatusBadge :status="activeGeneration.status" :label="activeGeneration.status_label" />
                            </div>
                            <CardDescription class="line-clamp-2">{{ activeGeneration.prompt }}</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <ol class="flex items-center gap-2 text-xs" aria-label="Generation progress">
                                <li v-for="step in lifecycleSteps" :key="step.status" class="flex flex-1 flex-col gap-1">
                                    <span
                                        class="h-1.5 rounded-full"
                                        :class="stepReached(step.status, activeGeneration.status) ? 'bg-primary' : 'bg-muted'"
                                    />
                                    <span :class="stepReached(step.status, activeGeneration.status) ? 'font-medium' : 'text-muted-foreground'">{{
                                        step.label
                                    }}</span>
                                </li>
                            </ol>
                            <GenerationImage :generation="activeGeneration" />
                            <Link
                                :href="route('generations.show', { generation: activeGeneration.id })"
                                class="text-sm font-medium underline-offset-4 hover:underline"
                            >
                                View details
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <section class="space-y-4" aria-labelledby="recent-heading">
                <div class="flex items-center justify-between">
                    <h2 id="recent-heading" class="text-lg font-semibold">Recent generations</h2>
                    <Link
                        v-if="recentGenerations.data.length"
                        :href="route('generations.index')"
                        class="text-sm font-medium underline-offset-4 hover:underline"
                    >
                        View all
                    </Link>
                </div>

                <div v-if="recentGenerations.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <GenerationCard v-for="generation in recentGenerations.data" :key="generation.id" :generation="generation" />
                </div>

                <div v-else class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-10 text-center">
                    <ImagePlus class="size-8 text-muted-foreground" aria-hidden="true" />
                    <p class="font-medium">No generations yet</p>
                    <p class="text-sm text-muted-foreground">Your images will appear here once you generate them.</p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
