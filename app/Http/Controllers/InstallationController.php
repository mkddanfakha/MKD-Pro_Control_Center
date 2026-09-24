<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Subscription;
use App\Services\AuditLogService;
use App\Services\InstallationAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InstallationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly InstallationAccessService $installationAccessService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $installations = Installation::query()
            ->with([
                'client',
                'subscriptions' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->orderByDesc('id')
            ->paginate(15)
            ->through(fn (Installation $installation) => array_merge(
                $this->serializeInstallationForIndex($installation),
                [
                    'access' => $this->installationAccessService->accessSummary($installation),
                ],
            ));

        return Inertia::render('Installations/Index', [
            'installations' => $installations,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Installations/Create', [
            'clients' => Client::query()
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'contact_name']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());

        $installation = Installation::create($validated);

        $this->auditLogService->record(
            'installation.created',
            auditable: $installation,
            newValues: $this->installationAuditSnapshot($installation),
        );

        return redirect()
            ->route('installations.show', $installation)
            ->with('success', 'Installation créée avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Installation $installation)
    {
        $installation->load([
            'client',
            'subscriptions' => fn ($query) => $query->orderByDesc('id'),
        ]);

        $lastSubscription = $this->installationAccessService->latestSubscription($installation);

        return Inertia::render('Installations/Show', [
            'installation' => $this->serializeInstallationForShow($installation),
            'access' => $this->installationAccessService->accessSummary($installation),
            'lastSubscription' => $this->serializeLastSubscription($lastSubscription),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Installation $installation)
    {
        $installation->load('client');

        $clients = Client::query()
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'contact_name']);

        return Inertia::render('Installations/Edit', [
            'installation' => $installation,
            'clients' => $clients,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Installation $installation)
    {
        $validated = $request->validate($this->validationRules($installation));

        $oldValues = $this->installationAuditSnapshot($installation);

        $installation->update($validated);

        $this->auditLogService->record(
            'installation.updated',
            auditable: $installation,
            oldValues: $oldValues,
            newValues: $this->installationAuditSnapshot($installation->fresh()),
        );

        return redirect()
            ->route('installations.show', $installation)
            ->with('success', 'Installation modifiée avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Installation $installation)
    {
        $oldValues = $this->installationAuditSnapshot($installation);

        $installation->delete();

        $this->auditLogService->record(
            'installation.deleted',
            oldValues: $oldValues,
        );

        return redirect()
            ->route('installations.index')
            ->with('success', 'Installation supprimée avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInstallationForIndex(Installation $installation): array
    {
        return array_merge(
            $installation->only([
                'id',
                'client_id',
                'name',
                'subdomain',
                'domain',
                'status',
                'version',
                'database_name',
                'database_host',
                'installed_at',
                'last_seen_at',
                'suspended_at',
                'terminated_at',
                'created_at',
                'updated_at',
            ]),
            [
                'client' => $installation->client?->only([
                    'id',
                    'company_name',
                    'contact_name',
                ]),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInstallationForShow(Installation $installation): array
    {
        return array_merge(
            $installation->only([
                'id',
                'client_id',
                'name',
                'subdomain',
                'domain',
                'status',
                'version',
                'database_name',
                'database_host',
                'installed_at',
                'last_seen_at',
                'suspended_at',
                'terminated_at',
                'created_at',
                'updated_at',
            ]),
            [
                'client' => $installation->client?->only([
                    'id',
                    'company_name',
                    'contact_name',
                    'phone',
                    'email',
                    'address',
                    'city',
                    'country',
                ]),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeLastSubscription(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return $subscription->only([
            'id',
            'status',
            'amount',
            'currency',
            'starts_at',
            'current_period_start',
            'current_period_end',
            'grace_period_ends_at',
            'suspended_at',
            'terminated_at',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function installationAuditSnapshot(Installation $installation): array
    {
        return array_merge(
            ['id' => $installation->id],
            $installation->only([
                'client_id',
                'name',
                'subdomain',
                'domain',
                'status',
                'version',
                'database_name',
                'database_host',
                'installed_at',
                'last_seen_at',
                'suspended_at',
                'terminated_at',
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(?Installation $installation = null): array
    {
        $subdomainRule = Rule::unique('installations', 'subdomain');

        if ($installation !== null) {
            $subdomainRule = $subdomainRule->ignore($installation->id);
        }

        return [
            'client_id' => 'required|exists:clients,id',
            'name' => 'required|string|max:255',
            'subdomain' => ['required', 'string', 'max:255', $subdomainRule],
            'domain' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive,suspended,terminated',
            'version' => 'nullable|string|max:50',
            'database_name' => 'nullable|string|max:255',
            'database_host' => 'nullable|string|max:255',
            'installed_at' => 'nullable|date',
            'last_seen_at' => 'nullable|date',
            'suspended_at' => 'nullable|date',
            'terminated_at' => 'nullable|date',
        ];
    }
}
