<script setup lang="ts">
import { aspectRatioFromSize } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Generation } from '@/types/generation';
import { ImageOff, LoaderCircle, TriangleAlert } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

defineProps<{
    generation: Generation;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div
        :class="cn('relative w-full overflow-hidden rounded-lg bg-muted', $props.class)"
        :style="{ aspectRatio: aspectRatioFromSize(generation.size) }"
    >
        <img v-if="generation.image_url" :src="generation.image_url" :alt="generation.prompt" loading="lazy" class="h-full w-full object-cover" />

        <div
            v-else-if="generation.is_in_progress"
            class="absolute inset-0 flex animate-pulse flex-col items-center justify-center gap-2 text-muted-foreground"
            role="status"
        >
            <LoaderCircle class="size-6 animate-spin" aria-hidden="true" />
            <span class="text-xs font-medium">{{ generation.status_label }}…</span>
        </div>

        <div
            v-else-if="generation.status === 'failed'"
            class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center text-red-600 dark:text-red-400"
        >
            <TriangleAlert class="size-6" aria-hidden="true" />
            <span class="text-xs font-medium">Generation failed</span>
        </div>

        <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-muted-foreground">
            <ImageOff class="size-6" aria-hidden="true" />
            <span class="text-xs">Image unavailable</span>
        </div>
    </div>
</template>
