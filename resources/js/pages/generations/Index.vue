<script setup lang="ts">
import GenerationCard from '@/components/generations/GenerationCard.vue';
import PaginationLinks from '@/components/generations/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { usePollWhile } from '@/composables/usePollWhile';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type { Generation, GenerationStatus, Paginated } from '@/types/generation';
import { Head, Link } from '@inertiajs/vue3';
import { ImagePlus } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    generations: Paginated<Generation>;
    filters: { status: GenerationStatus | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/generations' }];

const statusFilters: { value: GenerationStatus | null; label: string }[] = [
    { value: null, label: 'All' },
    { value: 'queued', label: 'Queued' },
    { value: 'processing', label: 'Processing' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
];

const hasInProgress = computed(() => props.generations.data.some((generation) => generation.is_in_progress));

usePollWhile(hasInProgress, ['generations']);

function filterUrl(status: GenerationStatus | null): string {
    return status ? route('generations.index', { status }) : route('generations.index');
}
</script>

<template>
    <Head title="Generation history" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Generation history</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ generations.meta.total }} {{ generations.meta.total === 1 ? 'generation' : 'generations' }}
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('dashboard')"><ImagePlus /> New generation</Link>
                </Button>
            </div>

            <nav class="flex flex-wrap gap-2" aria-label="Filter by status">
                <Link
                    v-for="filter in statusFilters"
                    :key="filter.label"
                    :href="filterUrl(filter.value)"
                    preserve-state
                    :aria-current="filters.status === filter.value ? 'page' : undefined"
                    :class="
                        cn(
                            'rounded-full border px-3 py-1 text-sm transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            filters.status === filter.value && 'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
                        )
                    "
                >
                    {{ filter.label }}
                </Link>
            </nav>

            <div v-if="generations.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <GenerationCard v-for="generation in generations.data" :key="generation.id" :generation="generation" />
            </div>

            <div v-else class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center">
                <ImagePlus class="size-8 text-muted-foreground" aria-hidden="true" />
                <p class="font-medium">{{ filters.status ? 'No generations with this status' : 'No generations yet' }}</p>
                <p class="text-sm text-muted-foreground">Generated images will show up here.</p>
            </div>

            <PaginationLinks :links="generations.meta.links" />
        </div>
    </AppLayout>
</template>
