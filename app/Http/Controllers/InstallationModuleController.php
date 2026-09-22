<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Module;
use App\Services\AuditLogService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InstallationModuleController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $installationModules = InstallationModule::query()
            ->with([
                'installation.client',
                'module',
            ])
            ->orderByDesc('id')
            ->paginate(15);

        return Inertia::render('InstallationModules/Index', [
            'installationModules' => $installationModules,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('InstallationModules/Create', [
            'installations' => $this->installationsForForm(),
            'modules' => $this->modulesForForm(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->validationRules($request));

        $installationModule = InstallationModule::create($validated);

        $this->auditLogService->record(
            'installation_module.created',
            auditable: $installationModule,
            newValues: $this->installationModuleAuditSnapshot($installationModule),
        );

        return redirect()
            ->route('installation-modules.show', $installationModule)
            ->with('success', 'Module affecté à l\'installation avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(InstallationModule $installationModule): Response
    {
        $installationModule->load([
            'installation.client',
            'module',
        ]);

        return Inertia::render('InstallationModules/Show', [
            'installationModule' => $installationModule,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(InstallationModule $installationModule): Response
    {
        return Inertia::render('InstallationModules/Edit', [
            'installationModule' => $installationModule,
            'installations' => $this->installationsForForm(),
            'modules' => $this->modulesForForm(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InstallationModule $installationModule): RedirectResponse
    {
        $validated = $request->validate($this->validationRules($request, $installationModule));

        $oldValues = $this->installationModuleAuditSnapshot($installationModule);

        $installationModule->update($validated);

        $this->auditLogService->record(
            'installation_module.updated',
            auditable: $installationModule,
            oldValues: $oldValues,
            newValues: $this->installationModuleAuditSnapshot($installationModule->fresh()),
        );

        return redirect()
            ->route('installation-modules.show', $installationModule)
            ->with('success', 'Affectation du module modifiée avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InstallationModule $installationModule): RedirectResponse
    {
        $oldValues = $this->installationModuleAuditSnapshot($installationModule);

        $installationModule->delete();

        $this->auditLogService->record(
            'installation_module.deleted',
            oldValues: $oldValues,
        );

        return redirect()
            ->route('installation-modules.index')
            ->with('success', 'Affectation du module supprimée avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function installationModuleAuditSnapshot(InstallationModule $installationModule): array
    {
        $snapshot = ['id' => $installationModule->id];

        foreach ([
            'installation_id',
            'module_id',
            'status',
            'version',
            'activated_at',
            'deactivated_at',
            'notes',
        ] as $attribute) {
            $value = $installationModule->getAttribute($attribute);

            if ($value instanceof DateTimeInterface) {
                $snapshot[$attribute] = $value->format('Y-m-d H:i:s');
            } else {
                $snapshot[$attribute] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * @return Collection<int, Installation>
     */
    private function installationsForForm(): Collection
    {
        return Installation::query()
            ->with('client')
            ->join('clients', 'installations.client_id', '=', 'clients.id')
            ->orderBy('clients.company_name')
            ->orderBy('installations.name')
            ->select('installations.*')
            ->get();
    }

    /**
     * @return Collection<int, Module>
     */
    private function modulesForForm(): Collection
    {
        return Module::query()
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [Module::STATUS_ACTIVE])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(Request $request, ?InstallationModule $installationModule = null): array
    {
        $moduleUniqueRule = Rule::unique('installation_modules')
            ->where(fn ($query) => $query->where('installation_id', $request->installation_id));

        if ($installationModule !== null) {
            $moduleUniqueRule = $moduleUniqueRule->ignore($installationModule->id);
        }

        return [
            'installation_id' => 'required|integer|exists:installations,id',
            'module_id' => ['required', 'integer', 'exists:modules,id', $moduleUniqueRule],
            'status' => 'required|in:active,inactive',
            'version' => 'nullable|string|max:255',
            'activated_at' => 'nullable|date',
            'deactivated_at' => 'nullable|date|after_or_equal:activated_at',
            'notes' => 'nullable|string',
        ];
    }
}
