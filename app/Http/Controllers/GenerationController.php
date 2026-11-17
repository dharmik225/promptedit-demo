<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generation\IndexGenerationRequest;
use App\Http\Requests\Generation\StoreGenerationRequest;
use App\Http\Resources\Generation\Collection;
use App\Http\Resources\Generation\Resource;
use App\Models\Generation;
use App\Services\Generation\GenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly GenerationService $generationService) {}

    public function index(IndexGenerationRequest $request): Response
    {
        $generations = $this->generationService->collection($request->user(), $request->validated());

        return Inertia::render('generations/Index', [
            'generations' => new Collection($generations),
            'filters' => ['status' => $request->validated('status')],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'options' => $this->generationService->formOptions(),
            'recentGenerations' => new Collection($this->generationService->recent($request->user())),
        ]);
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        $this->generationService->create($request->user(), $request->validated());

        return to_route('dashboard');
    }

    public function show(Generation $generation): Response
    {
        $this->authorize('view', $generation);

        return Inertia::render('generations/Show', [
            'generation' => new Resource($generation),
        ]);
    }
}
