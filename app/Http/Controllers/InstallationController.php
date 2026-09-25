<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\AuditLogService;
use App\Services\InstallationAccessService;
use App\Services\SubscriptionService;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InstallationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly InstallationAccessService $installationAccessService,
        private readonly SubscriptionService $subscriptionService,
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
            'installationModules.module',
        ]);

        $currentSubscription = $this->installationAccessService->latestSubscription($installation);

        $credit = null;

        if ($currentSubscription !== null) {
            $creditSummary = $this->subscriptionService->summarizeSubscriptionCreditForDisplay($currentSubscription);
            $credit = [
                'available_months' => (int) $creditSummary['available_months'],
                'payment_count' => (int) $creditSummary['payment_count'],
            ];
        }

        return Inertia::render('Installations/Show', [
            'installation' => $this->serializeInstallationForShow($installation),
            'access' => $this->installationAccessService->accessSummary($installation),
            'lastSubscription' => $this->serializeLastSubscription($currentSubscription),
            'credit' => $credit,
            'paymentsSummary' => $this->paymentsSummaryForInstallation($installation, $currentSubscription),
            'modules' => $this->serializeInstallationModules($installation),
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
     * @return array<string, mixed>
     */
    private function paymentsSummaryForInstallation(Installation $installation, ?Subscription $currentSubscription): array
    {
        $subscriptionIds = $currentSubscription !== null
            ? collect([$currentSubscription->id])
            : Subscription::query()
                ->where('installation_id', $installation->id)
                ->pluck('id');

        if ($subscriptionIds->isEmpty()) {
            return [
                'count' => 0,
                'last_payment' => null,
            ];
        }

        $paymentsCount = Payment::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->count();

        $lastPayment = Payment::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();

        return [
            'count' => $paymentsCount,
            'last_payment' => $lastPayment !== null ? [
                'id' => $lastPayment->id,
                'amount' => (int) $lastPayment->amount,
                'currency' => (string) $lastPayment->currency,
                'paid_at' => $this->formatDateTimeValue($lastPayment->paid_at),
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeInstallationModules(Installation $installation): array
    {
        return $installation->installationModules
            ->sortByDesc('id')
            ->values()
            ->map(function (InstallationModule $installationModule): array {
                $module = $installationModule->module;

                return [
                    'id' => $installationModule->id,
                    'status' => (string) $installationModule->status,
                    'version' => $installationModule->version,
                    'module' => $module !== null ? [
                        'id' => $module->id,
                        'name' => (string) $module->name,
                        'price' => $module->price !== null ? (int) $module->price : null,
                        'currency' => (string) $module->currency,
                    ] : null,
                ];
            })
            ->all();
    }

    private function formatDateTimeValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return null;
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
