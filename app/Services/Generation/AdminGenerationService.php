<?php

namespace App\Services\Generation;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminGenerationService
{
    public const PER_PAGE = 20;

    /**
     * @param array{status?: ?string, search?: ?string} $inputs
     * @return LengthAwarePaginator<int, Generation>
     */
    public function collection(array $inputs): LengthAwarePaginator
    {
        $status = GenerationStatus::tryFrom((string) ($inputs['status'] ?? ''));
        $search = trim((string) ($inputs['search'] ?? ''));

        return Generation::query()
            ->with('user:id,name,email')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->whereHas(
                'user',
                fn ($userQuery) => $userQuery->whereLike('email', "%{$search}%"),
            ))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Sums run in PostgreSQL on numeric columns, so totals stay exact.
     *
     * @return array{
     *     total_generations: int,
     *     succeeded: int,
     *     failed: int,
     *     in_progress: int,
     *     clients: int,
     *     provider_cost: string,
     *     revenue: string,
     *     margin: string
     * }
     */
    public function summary(): array
    {
        $totals = Generation::query()
            ->toBase()
            ->selectRaw('count(*) as total_generations')
            ->selectRaw('count(*) filter (where status = ?) as succeeded', [GenerationStatus::Succeeded->value])
            ->selectRaw('count(*) filter (where status = ?) as failed', [GenerationStatus::Failed->value])
            ->selectRaw('count(*) filter (where status not in (?, ?)) as in_progress', [
                GenerationStatus::Succeeded->value,
                GenerationStatus::Failed->value,
            ])
            ->selectRaw('count(distinct user_id) as clients')
            ->selectRaw('coalesce(sum(actual_provider_cost), 0) as provider_cost')
            ->selectRaw('coalesce(sum(actual_user_cost), 0) as revenue')
            ->selectRaw('coalesce(sum(actual_user_cost), 0) - coalesce(sum(actual_provider_cost), 0) as margin')
            ->first();

        return [
            'total_generations' => (int) $totals->total_generations,
            'succeeded' => (int) $totals->succeeded,
            'failed' => (int) $totals->failed,
            'in_progress' => (int) $totals->in_progress,
            'clients' => (int) $totals->clients,
            'provider_cost' => (string) $totals->provider_cost,
            'revenue' => (string) $totals->revenue,
            'margin' => (string) $totals->margin,
        ];
    }
}
