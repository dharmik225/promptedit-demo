import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

export function useIsAdmin(): ComputedRef<boolean> {
    const page = usePage<SharedData>();

    return computed(() => page.props.auth.user.role === 'admin');
}
