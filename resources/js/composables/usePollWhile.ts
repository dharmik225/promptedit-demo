import { usePoll } from '@inertiajs/vue3';
import { watch, type WatchSource } from 'vue';

export function usePollWhile(condition: WatchSource<boolean>, only: string[], intervalMs = 3000): void {
    const { start, stop } = usePoll(intervalMs, { only }, { autoStart: false });

    watch(condition, (active) => (active ? start() : stop()), { immediate: true });
}
