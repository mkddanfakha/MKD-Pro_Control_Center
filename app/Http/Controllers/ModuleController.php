<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ModuleController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $modules = Module::query()
            ->orderByDesc('id')
            ->paginate(15);

        return Inertia::render('Modules/Index', [
            'modules' => $modules,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Modules/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->validationRules());

        $module = Module::create($validated);

        $this->auditLogService->record(
            'module.created',
            auditable: $module,
            newValues: $this->moduleAuditSnapshot($module),
        );

        return redirect()
            ->route('modules.show', $module)
            ->with('success', 'Module créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Module $module): Response
    {
        return Inertia::render('Modules/Show', [
            'module' => $module,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Module $module): Response
    {
        return Inertia::render('Modules/Edit', [
            'module' => $module,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Module $module): RedirectResponse
    {
        $validated = $request->validate($this->validationRules($module));

        $oldValues = $this->moduleAuditSnapshot($module);

        $module->update($validated);

        $this->auditLogService->record(
            'module.updated',
            auditable: $module,
            oldValues: $oldValues,
            newValues: $this->moduleAuditSnapshot($module->fresh()),
        );

        return redirect()
            ->route('modules.show', $module)
            ->with('success', 'Module modifié avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Module $module): RedirectResponse
    {
        $oldValues = $this->moduleAuditSnapshot($module);

        $module->delete();

        $this->auditLogService->record(
            'module.deleted',
            oldValues: $oldValues,
        );

        return redirect()
            ->route('modules.index')
            ->with('success', 'Module supprimé avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleAuditSnapshot(Module $module): array
    {
        return array_merge(
            ['id' => $module->id],
            $module->only([
                'name',
                'slug',
                'description',
                'version',
                'price',
                'currency',
                'status',
                'sort_order',
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(?Module $module = null): array
    {
        $slugRule = Rule::unique('modules', 'slug');

        if ($module !== null) {
            $slugRule = $slugRule->ignore($module->id);
        }

        return [
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', $slugRule],
            'description' => 'nullable|string',
            'version' => 'nullable|string|max:255',
            'price' => 'nullable|integer|min:0',
            'currency' => 'required|string|size:3',
            'status' => 'required|in:active,inactive',
            'sort_order' => 'required|integer|min:0',
        ];
    }
}
