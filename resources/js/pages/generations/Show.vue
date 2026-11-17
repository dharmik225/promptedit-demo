<script setup lang="ts">
import GenerationImage from '@/components/generations/GenerationImage.vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatTokens, formatUsd } from '@/lib/format';
import { type BreadcrumbItem, type User } from '@/types';
import type { Generation } from '@/types/generation';
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, TriangleAlert } from 'lucide-vue-next';
import { computed, watch } from 'vue';

const props = defineProps<{
    generation: { data: Generation };
}>();

const generation = computed(() => props.generation.data);

const page = usePage();

const isAdmin = computed(() => (page.props.auth as { user: User }).user.role === 'admin');

const backLink = computed(() =>
    isAdmin.value
        ? { title: 'Admin analytics', href: '/admin/generations', label: 'Back to analytics' }
        : { title: 'History', href: '/generations', label: 'Back to history' },
);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: backLink.value.title, href: backLink.value.href },
    { title: 'Details', href: `/generations/${generation.value.id}` },
]);

const { start: startPolling, stop: stopPolling } = usePoll(3000, { only: ['generation'] }, { autoStart: false });

watch(
    () => generation.value.is_in_progress,
    (inProgress) => (inProgress ? startPolling() : stopPolling()),
    { immediate: true },
);

const tokenRows = computed(() => [
    { label: 'Input text', key: 'input_text_tokens' as const },
    { label: 'Input image', key: 'input_image_tokens' as const },
    { label: 'Output', key: 'output_tokens' as const },
    { label: 'Reasoning', key: 'reasoning_tokens' as const },
    { label: 'Total', key: 'total_tokens' as const },
]);

const timeline = computed(() => [
    { label: 'Created', value: generation.value.created_at },
    { label: 'Submitted', value: generation.value.submitted_at },
    { label: 'Started', value: generation.value.started_at },
    { label: 'Completed', value: generation.value.completed_at },
]);
</script>

<template>
    <Head title="Generation details" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <Button variant="ghost" size="sm" as-child>
                    <Link :href="backLink.href"><ArrowLeft /> {{ backLink.label }}</Link>
                </Button>
                <GenerationStatusBadge :status="generation.status" :label="generation.status_label" />
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div class="space-y-4">
                    <GenerationImage :generation="generation" class="border" />

                    <div class="flex flex-wrap gap-2">
                        <Button v-if="generation.image_url" variant="outline" size="sm" as-child>
                            <a :href="generation.image_url" target="_blank" rel="noopener noreferrer"><ExternalLink /> Open full size</a>
                        </Button>
                    </div>

                    <div
                        v-if="generation.error"
                        class="flex items-start gap-3 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"
                        role="alert"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <div>
                            <p class="font-medium">Generation failed ({{ generation.error.code }})</p>
                            <p>{{ generation.error.message }}</p>
                            <p class="mt-1 text-xs opacity-80">You were not charged for this generation.</p>
                        </div>
                    </div>

                    <Card>
                        <CardHeader class="pb-3"><CardTitle class="text-base">Prompt</CardTitle></CardHeader>
                        <CardContent>
                            <p class="whitespace-pre-wrap text-sm leading-relaxed">{{ generation.prompt }}</p>
                        </CardContent>
                    </Card>
                </div>

                <div class="space-y-4">
                    <Card>
                        <CardHeader class="pb-3"><CardTitle class="text-base">Pricing</CardTitle></CardHeader>
                        <CardContent>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs text-muted-foreground">
                                        <th scope="col" class="pb-2 text-left font-medium"><span class="sr-only">Cost type</span></th>
                                        <th scope="col" class="pb-2 pl-4 text-right font-medium">Estimate (max)</th>
                                        <th scope="col" class="pb-2 pl-4 text-right font-medium">Actual</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th scope="row" class="py-1 text-left font-normal text-muted-foreground">Provider cost</th>
                                        <td class="py-1 pl-4 text-right tabular-nums">{{ formatUsd(generation.costs.estimated_provider_cost) }}</td>
                                        <td class="py-1 pl-4 text-right tabular-nums">{{ formatUsd(generation.costs.actual_provider_cost) }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="py-1 text-left font-medium">Your price</th>
                                        <td class="py-1 pl-4 text-right tabular-nums">{{ formatUsd(generation.costs.estimated_user_cost) }}</td>
                                        <td class="py-1 pl-4 text-right font-semibold tabular-nums">
                                            {{ formatUsd(generation.costs.actual_user_cost) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="mt-3 text-xs text-muted-foreground">
                                <template v-if="generation.is_in_progress"
                                    >Final price is calculated from actual token usage when the image is ready.</template
                                >
                                <template v-else-if="generation.status === 'failed'">Failed generations cost $0.</template>
                                <template v-else>Charged at actual usage plus a {{ Number(generation.markup_percentage) }}% service fee.</template>
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="pb-3"><CardTitle class="text-base">Token usage</CardTitle></CardHeader>
                        <CardContent>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs text-muted-foreground">
                                        <th scope="col" class="pb-2 text-left font-medium">Type</th>
                                        <th scope="col" class="pb-2 pl-4 text-right font-medium">Estimate (max)</th>
                                        <th scope="col" class="pb-2 pl-4 text-right font-medium">Actual</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in tokenRows" :key="row.key" :class="{ 'border-t font-medium': row.key === 'total_tokens' }">
                                        <th scope="row" class="py-1 text-left font-normal text-muted-foreground">{{ row.label }}</th>
                                        <td class="py-1 pl-4 text-right tabular-nums">{{ formatTokens(generation.tokens.estimated[row.key]) }}</td>
                                        <td class="py-1 pl-4 text-right tabular-nums">{{ formatTokens(generation.tokens.actual?.[row.key]) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="pb-3"><CardTitle class="text-base">Parameters</CardTitle></CardHeader>
                        <CardContent>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                                <dt class="text-muted-foreground">Generation ID</dt>
                                <dd class="break-all text-right font-mono text-xs">{{ generation.id }}</dd>
                                <dt class="text-muted-foreground">Provider ID</dt>
                                <dd class="break-all text-right font-mono text-xs">{{ generation.provider_generation_id ?? '—' }}</dd>
                                <dt class="text-muted-foreground">Size</dt>
                                <dd class="text-right">{{ generation.size }}</dd>
                                <dt class="text-muted-foreground">Quality</dt>
                                <dd class="text-right capitalize">{{ generation.quality }}</dd>
                                <dt class="text-muted-foreground">Reference images</dt>
                                <dd class="text-right">
                                    <template v-if="generation.reference_image_urls.length">
                                        <a
                                            v-for="(url, index) in generation.reference_image_urls"
                                            :key="url"
                                            :href="url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="block truncate text-xs underline-offset-4 hover:underline"
                                        >
                                            Reference {{ index + 1 }}
                                        </a>
                                    </template>
                                    <template v-else>None</template>
                                </dd>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="pb-3"><CardTitle class="text-base">Timeline</CardTitle></CardHeader>
                        <CardContent>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                                <template v-for="entry in timeline" :key="entry.label">
                                    <dt class="text-muted-foreground">{{ entry.label }}</dt>
                                    <dd class="text-right">{{ formatDateTime(entry.value) }}</dd>
                                </template>
                            </dl>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
