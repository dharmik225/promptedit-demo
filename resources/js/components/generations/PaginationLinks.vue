<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { PaginationLink } from '@/types/generation';
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: PaginationLink[];
}>();

function cleanLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}
</script>

<template>
    <nav v-if="links.length > 3" class="flex flex-wrap items-center justify-center gap-1" aria-label="Pagination">
        <template v-for="link in links" :key="link.label">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                :aria-current="link.active ? 'page' : undefined"
                :class="
                    cn(
                        'inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        link.active && 'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
                    )
                "
            >
                {{ cleanLabel(link.label) }}
            </Link>
            <span v-else class="inline-flex h-9 min-w-9 items-center justify-center rounded-md px-3 text-sm text-muted-foreground opacity-50">
                {{ cleanLabel(link.label) }}
            </span>
        </template>
    </nav>
</template>
