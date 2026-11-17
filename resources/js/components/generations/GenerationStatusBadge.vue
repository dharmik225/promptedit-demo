<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { GenerationStatus } from '@/types/generation';
import { CheckCircle2, Clock, LoaderCircle, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    status: GenerationStatus;
    label: string;
}>();

const appearance = computed(() => {
    switch (props.status) {
        case 'succeeded':
            return {
                icon: CheckCircle2,
                classes: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
                spin: false,
            };
        case 'failed':
            return { icon: XCircle, classes: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400', spin: false };
        case 'processing':
            return { icon: LoaderCircle, classes: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400', spin: true };
        default:
            return { icon: Clock, classes: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400', spin: false };
    }
});
</script>

<template>
    <span :class="cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', appearance.classes)">
        <component :is="appearance.icon" class="size-3.5" :class="{ 'animate-spin': appearance.spin }" aria-hidden="true" />
        {{ label }}
    </span>
</template>
