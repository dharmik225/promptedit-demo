<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminGenerationRequest;
use App\Http\Resources\Generation\Resource;
use App\Services\Generation\AdminGenerationService;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly AdminGenerationService $adminGenerationService) {}

    public function index(IndexAdminGenerationRequest $request): Response
    {
        $generations = $this->adminGenerationService->collection(
            status: $request->enum('status', GenerationStatus::class),
            search: $request->string('search')->trim()->toString(),
        );

        return Inertia::render('admin/Generations', [
            'summary' => $this->adminGenerationService->summary(),
            'generations' => Resource::collection($generations),
            'filters' => [
                'status' => $request->validated('status'),
                'search' => $request->validated('search'),
            ],
        ]);
    }
}
