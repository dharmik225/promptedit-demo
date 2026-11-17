<script setup lang="ts">
import GenerationImage from '@/components/generations/GenerationImage.vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import { formatDateTime, formatTokens, formatUsd } from '@/lib/format';
import type { Generation } from '@/types/generation';
import { Link } from '@inertiajs/vue3';

defineProps<{
    generation: Generation;
}>();
</script>

<template>
    <Link
        :href="route('generations.show', { generation: generation.id })"
        class="group flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm transition hover:border-foreground/20 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
        <GenerationImage :generation="generation" class="rounded-none" />

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <GenerationStatusBadge :status="generation.status" :label="generation.status_label" />
                <span class="text-xs text-muted-foreground">{{ generation.size }} · {{ generation.quality }}</span>
            </div>

            <p class="line-clamp-2 text-sm font-medium leading-snug">{{ generation.prompt }}</p>

            <p v-if="generation.error" class="line-clamp-2 text-xs text-red-600 dark:text-red-400">{{ generation.error.message }}</p>

            <dl class="mt-auto grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                <template v-if="generation.costs.actual_user_cost !== null">
                    <dt class="text-muted-foreground">Charged</dt>
                    <dd class="text-right font-semibold tabular-nums">{{ formatUsd(generation.costs.actual_user_cost) }}</dd>
                    <dt class="text-muted-foreground">Tokens</dt>
                    <dd class="text-right tabular-nums">{{ formatTokens(generation.tokens.actual?.total_tokens) }}</dd>
                </template>
                <template v-else>
                    <dt class="text-muted-foreground">Estimate (max)</dt>
                    <dd class="text-right font-semibold tabular-nums">{{ formatUsd(generation.costs.estimated_user_cost) }}</dd>
                    <dt class="text-muted-foreground">Est. tokens</dt>
                    <dd class="text-right tabular-nums">≤ {{ formatTokens(generation.tokens.estimated.total_tokens) }}</dd>
                </template>
                <dt class="text-muted-foreground">Created</dt>
                <dd class="text-right">{{ formatDateTime(generation.created_at) }}</dd>
            </dl>
        </div>
    </Link>
</template>
