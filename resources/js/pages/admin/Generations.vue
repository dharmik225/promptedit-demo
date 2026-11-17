<script setup lang="ts">
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import PaginationLinks from '@/components/generations/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatTokens, formatUsd } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type { Generation, GenerationStatus, Paginated } from '@/types/generation';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    summary: {
        total_generations: number;
        succeeded: number;
        failed: number;
        in_progress: number;
        clients: number;
        provider_cost: string;
        revenue: string;
        margin: string;
    };
    generations: Paginated<Generation>;
    filters: { status: GenerationStatus | null; search: string | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin analytics', href: '/admin/generations' }];

const search = ref(props.filters.search ?? '');

const statusFilters: { value: GenerationStatus | null; label: string }[] = [
    { value: null, label: 'All' },
    { value: 'queued', label: 'Queued' },
    { value: 'processing', label: 'Processing' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
];

const summaryCards = computed(() => [
    { label: 'Revenue', value: formatUsd(props.summary.revenue), hint: 'Charged to clients' },
    { label: 'Provider cost', value: formatUsd(props.summary.provider_cost), hint: 'Billed by Pixelforge' },
    { label: 'Margin', value: formatUsd(props.summary.margin), hint: 'Revenue minus provider cost' },
    {
        label: 'Generations',
        value: String(props.summary.total_generations),
        hint: `${props.summary.succeeded} succeeded · ${props.summary.failed} failed · ${props.summary.in_progress} in progress`,
    },
]);

function applyFilters(status: GenerationStatus | null = props.filters.status): void {
    router.get(
        route('admin.generations.index'),
        { status: status ?? undefined, search: search.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const hasInProgress = computed(() => props.generations.data.some((generation) => generation.is_in_progress));

const { start: startPolling, stop: stopPolling } = usePoll(5000, { only: ['generations', 'summary'] }, { autoStart: false });

watch(hasInProgress, (inProgress) => (inProgress ? startPolling() : stopPolling()), { immediate: true });
</script>

<template>
    <Head title="Admin analytics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Admin analytics</h1>
                <p class="text-sm text-muted-foreground">
                    All clients' generations and billing. {{ summary.clients }} clients have generated images.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card v-for="card in summaryCards" :key="card.label">
                    <CardHeader class="pb-2">
                        <CardDescription>{{ card.label }}</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ card.value }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-xs text-muted-foreground">{{ card.hint }}</p>
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <nav class="flex flex-wrap gap-2" aria-label="Filter by status">
                    <button
                        v-for="filter in statusFilters"
                        :key="filter.label"
                        type="button"
                        :aria-pressed="filters.status === filter.value"
                        :class="
                            cn(
                                'rounded-full border px-3 py-1 text-sm transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                filters.status === filter.value && 'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
                            )
                        "
                        @click="applyFilters(filter.value)"
                    >
                        {{ filter.label }}
                    </button>
                </nav>

                <form class="flex gap-2" @submit.prevent="applyFilters()">
                    <Input v-model="search" type="search" placeholder="Client email" aria-label="Search by client email" class="w-56" />
                    <Button type="submit" variant="outline" size="icon" aria-label="Search"><Search /></Button>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-medium">Client</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium">Prompt</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Tokens</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Provider cost</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Client price</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="generation in generations.data" :key="generation.id" class="border-t hover:bg-muted/30">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ generation.user?.name }}</p>
                                <p class="text-xs text-muted-foreground">{{ generation.user?.email }}</p>
                            </td>
                            <td class="max-w-xs px-4 py-3">
                                <Link
                                    :href="route('generations.show', { generation: generation.id })"
                                    class="line-clamp-2 underline-offset-4 hover:underline"
                                >
                                    {{ generation.prompt }}
                                </Link>
                                <p class="text-xs text-muted-foreground">{{ generation.size }} · {{ generation.quality }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <GenerationStatusBadge :status="generation.status" :label="generation.status_label" />
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ generation.tokens.actual ? formatTokens(generation.tokens.actual.total_tokens) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <template v-if="generation.costs.actual_provider_cost !== null">{{
                                    formatUsd(generation.costs.actual_provider_cost)
                                }}</template>
                                <span v-else class="text-muted-foreground">≤ {{ formatUsd(generation.costs.estimated_provider_cost) }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums">
                                <template v-if="generation.costs.actual_user_cost !== null">{{
                                    formatUsd(generation.costs.actual_user_cost)
                                }}</template>
                                <span v-else class="font-normal text-muted-foreground">≤ {{ formatUsd(generation.costs.estimated_user_cost) }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">{{ formatDateTime(generation.created_at) }}</td>
                        </tr>
                        <tr v-if="!generations.data.length">
                            <td colspan="7" class="px-4 py-10 text-center text-muted-foreground">No generations match these filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PaginationLinks :links="generations.meta.links" />
        </div>
    </AppLayout>
</template>
