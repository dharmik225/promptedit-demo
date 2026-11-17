<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminGenerationRequest;
use App\Http\Resources\Generation\Collection;
use App\Services\Generation\AdminGenerationService;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly AdminGenerationService $adminGenerationService) {}

    public function index(IndexAdminGenerationRequest $request): Response
    {
        $this->authorize('viewAdmin');

        return Inertia::render('admin/Generations', [
            'summary' => $this->adminGenerationService->summary(),
            'generations' => new Collection($this->adminGenerationService->collection($request->validated())),
            'filters' => [
                'status' => $request->validated('status'),
                'search' => $request->validated('search'),
            ],
        ]);
    }
}
