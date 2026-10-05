<?php

namespace App\Http\Controllers;

use App\Exceptions\Provisioning\ProvisioningRunCreationException;
use App\Models\Client;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Payment;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningRunStep;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Support\AdminActionAvailability;
use App\Services\Provisioning\ProvisioningExecutionAuditService;
use App\Services\Provisioning\ProvisioningRunFactory;
use App\Services\AuditLogService;
use App\Services\InstallationAccessService;
use App\Services\SubscriptionService;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InstallationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly InstallationAccessService $installationAccessService,
        private readonly SubscriptionService $subscriptionService,
        private readonly ProvisioningRunFactory $provisioningRunFactory,
        private readonly ProvisioningExecutionAuditService $provisioningExecutionAuditService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                Rule::in(['active', 'inactive', 'suspended', 'terminated']),
            ],
            'client_id' => 'nullable|integer|exists:clients,id',
            'search' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:50',
        ]);

        $query = Installation::query()
            ->select([
                'id',
                'client_id',
                'name',
                'subdomain',
                'domain',
                'status',
                'version',
                'installed_at',
                'last_seen_at',
                'suspended_at',
                'terminated_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'client:id,company_name,contact_name',
                'subscriptions' => fn ($subscriptionQuery) => $subscriptionQuery
                    ->whereIn('status', [
                        Subscription::STATUS_ACTIVE,
                        Subscription::STATUS_GRACE_PERIOD,
                        Subscription::STATUS_SUSPENDED,
                    ])
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            ->withExists(['subscriptions', 'installationModules']);

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        if (filled($validated['client_id'] ?? null)) {
            $query->where('client_id', (int) $validated['client_id']);
        }

        if (filled($validated['version'] ?? null)) {
            $query->where('version', $validated['version']);
        }

        if (filled($validated['search'] ?? null)) {
            $term = '%'.addcslashes($validated['search'], '%_\\').'%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('name', 'like', $term)
                    ->orWhere('subdomain', 'like', $term)
                    ->orWhere('domain', 'like', $term);
            });
        }

        $installations = $query
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        $subscriptionIds = $installations->getCollection()
            ->map(fn (Installation $installation) => $installation->subscriptions->first()?->id)
            ->filter()
            ->values();

        /** @var \Illuminate\Support\Collection<int, SubscriptionReminder> $latestRemindersBySubscription */
        $latestRemindersBySubscription = collect();

        if ($subscriptionIds->isNotEmpty()) {
            $latestRemindersBySubscription = SubscriptionReminder::query()
                ->whereIn('subscription_id', $subscriptionIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->unique('subscription_id')
                ->keyBy('subscription_id');
        }

        $installations->through(function (Installation $installation) use ($latestRemindersBySubscription): array {
            $currentSubscription = $this->installationAccessService->latestSubscription($installation);

            return array_merge(
                $this->serializeInstallationForIndex($installation),
                [
                    'current_subscription' => $this->serializeCurrentSubscriptionForIndex($currentSubscription),
                    'last_reminder' => $this->serializeLastReminderForIndex(
                        $currentSubscription !== null
                            ? $latestRemindersBySubscription->get($currentSubscription->id)
                            : null,
                    ),
                ],
            );
        });

        return Inertia::render('Installations/Index', [
            'installations' => $installations,
            'clients' => Client::query()
                ->orderBy('company_name')
                ->get(['id', 'company_name']),
            'filters' => [
                'status' => $validated['status'] ?? null,
                'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
                'search' => $validated['search'] ?? null,
                'version' => $validated['version'] ?? null,
            ],
            'admin_urls' => [
                'create' => route('installations.create'),
            ],
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
     * Display the specified resource (consultation administrative read-only).
     */
    public function show(Request $request, Installation $installation): Response
    {
        $installation->load([
            'client:id,company_name,contact_name,email,phone,address,city,country,status',
            'installationModules.module',
        ]);

        $currentSubscription = $this->installationAccessService->latestSubscription($installation);

        $subscriptionIds = Subscription::query()
            ->where('installation_id', $installation->id)
            ->pluck('id');

        $payments = Payment::query()
            ->select([
                'id',
                'subscription_id',
                'amount',
                'currency',
                'status',
                'due_at',
                'paid_at',
                'payment_method',
                'reference',
                'period_start',
                'period_end',
                'credit_months_purchased',
                'credit_exhausted_at',
                'created_at',
            ])
            ->whereIn('subscription_id', $subscriptionIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'payments_page')
            ->withQueryString()
            ->through(fn (Payment $payment): array => $this->serializePaymentForAdminShow($payment));

        $reminders = SubscriptionReminder::query()
            ->select([
                'id',
                'subscription_id',
                'reminder_type',
                'threshold_days',
                'scheduled_for',
                'detected_at',
                'sent_at',
                'status',
                'created_at',
            ])
            ->whereIn('subscription_id', $subscriptionIds)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'reminders_page')
            ->withQueryString()
            ->through(fn (SubscriptionReminder $reminder): array => $this->serializeReminderForAdminShow($reminder));

        $installationModules = $this->serializeInstallationModules($installation);

        $lastProvisioningRun = ProvisioningRun::query()
            ->where('installation_id', $installation->id)
            ->withCount([
                'steps',
                'steps as steps_completed_count' => fn ($query) => $query->whereIn('status', [
                    ProvisioningRunStep::STATUS_SUCCEEDED,
                    ProvisioningRunStep::STATUS_SKIPPED,
                    ProvisioningRunStep::STATUS_FAILED,
                    ProvisioningRunStep::STATUS_MANUAL_INTERVENTION_REQUIRED,
                ]),
            ])
            ->orderByDesc('id')
            ->first();

        $provisioningAvailability = $this->provisioningRunFactory
            ->describeCreateRequestAvailability($installation);

        return Inertia::render('Installations/Show', [
            'installation' => $this->serializeInstallationForAdminDetail($installation),
            'current_subscription' => $this->serializeCurrentSubscriptionForShow($currentSubscription),
            'installation_modules' => $installationModules,
            'administrative_readiness' => $this->buildAdministrativeReadinessSummary(
                $installation,
                $currentSubscription,
                (int) $payments->total(),
                count($installationModules),
            ),
            'payments' => $payments,
            'reminders' => $reminders,
            'navigation' => [
                'installations_index' => route('installations.index'),
                'client_show' => $installation->client !== null
                    ? route('clients.show', $installation->client)
                    : null,
                'subscription_show' => $currentSubscription !== null
                    ? route('subscriptions.show', $currentSubscription)
                    : null,
                'payments_index' => route('payments.index', ['installation_id' => $installation->id]),
                'reminders_index' => route('subscription-reminders.index', ['installation_id' => $installation->id]),
                'installation_modules_index' => route('installation-modules.index'),
                'edit' => route('installations.edit', $installation),
            ],
            'admin_urls' => AdminActionAvailability::mergeIntoAdminUrls(
                AdminActionAvailability::installation($installation),
                ['edit' => route('installations.edit', $installation)],
            ),
            'last_provisioning_run' => $this->serializeLastProvisioningRunForShow($lastProvisioningRun),
            'provisioning_actions' => [
                'can_create_request' => $provisioningAvailability['can_create_request'],
                'unavailable_reason' => $provisioningAvailability['unavailable_reason'],
                'button_label' => $provisioningAvailability['button_label'],
                'retry_basis_run_id' => $provisioningAvailability['retry_basis_run_id'],
                'store_url' => route('installations.provisioning-runs.store', $installation),
            ],
        ]);
    }

    /**
     * Crée une demande de provisioning (pending) sans exécuter le pipeline.
     */
    public function storeProvisioningRun(Installation $installation): RedirectResponse
    {
        try {
            $run = $this->provisioningRunFactory->createRequest($installation, auth()->id());
            $this->provisioningExecutionAuditService->recordRequestCreated($run);
            if ($run->retry_of_run_id !== null) {
                $this->provisioningExecutionAuditService->recordRetryCreated($run);
            }
        } catch (ProvisioningRunCreationException $exception) {
            return redirect()
                ->route('installations.show', $installation)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('installations.show', $installation)
            ->with(
                'success',
                'Demande de provisioning enregistrée. Aucune exécution automatique n\'a été lancée.',
            );
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
        if ($installation->subscriptions()->exists()) {
            return redirect()
                ->route('installations.show', $installation)
                ->with('error', 'Cette installation ne peut pas être supprimée car un abonnement lui est encore associé.');
        }

        if ($installation->installationModules()->exists()) {
            return redirect()
                ->route('installations.show', $installation)
                ->with('error', 'Cette installation ne peut pas être supprimée car des modules lui sont encore associés.');
        }

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
                'installed_at' => $this->formatDateTimeValue($installation->installed_at),
                'show_url' => route('installations.show', $installation),
                'last_seen_at' => $this->formatDateTimeValue($installation->last_seen_at),
                'suspended_at' => $this->formatDateTimeValue($installation->suspended_at),
                'terminated_at' => $this->formatDateTimeValue($installation->terminated_at),
                'created_at' => $this->formatDateTimeValue($installation->created_at),
                'updated_at' => $this->formatDateTimeValue($installation->updated_at),
                'edit_url' => route('installations.edit', $installation),
                ...AdminActionAvailability::installationFromExistsFlags(
                    (bool) ($installation->subscriptions_exists ?? false),
                    (bool) ($installation->installation_modules_exists ?? false),
                ),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeCurrentSubscriptionForIndex(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'status' => (string) $subscription->status,
            'current_period_end' => $this->formatDateTimeValue($subscription->current_period_end),
            'show_url' => route('subscriptions.show', $subscription),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeLastReminderForIndex(?SubscriptionReminder $reminder): ?array
    {
        if ($reminder === null) {
            return null;
        }

        return [
            'id' => $reminder->id,
            'status' => (string) $reminder->status,
            'threshold_days' => (int) $reminder->threshold_days,
            'detected_at' => $this->formatDateTimeValue($reminder->detected_at),
            'sent_at' => $this->formatDateTimeValue($reminder->sent_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInstallationForAdminDetail(Installation $installation): array
    {
        return [
            'id' => $installation->id,
            'name' => (string) $installation->name,
            'subdomain' => (string) $installation->subdomain,
            'domain' => $installation->domain,
            'status' => (string) $installation->status,
            'version' => $installation->version,
            'installed_at' => $this->formatDateTimeValue($installation->installed_at),
            'last_seen_at' => $this->formatDateTimeValue($installation->last_seen_at),
            'suspended_at' => $this->formatDateTimeValue($installation->suspended_at),
            'terminated_at' => $this->formatDateTimeValue($installation->terminated_at),
            'show_url' => route('installations.show', $installation),
            'client' => $installation->client !== null ? [
                'id' => $installation->client->id,
                'company_name' => (string) $installation->client->company_name,
                'show_url' => route('clients.show', $installation->client),
                'contact_name' => (string) $installation->client->contact_name,
                'email' => $installation->client->email,
                'phone' => $installation->client->phone,
                'address' => $installation->client->address,
                'city' => $installation->client->city,
                'country' => $installation->client->country,
                'status' => (string) $installation->client->status,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeCurrentSubscriptionForShow(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'status' => (string) $subscription->status,
            'amount' => (int) $subscription->amount,
            'currency' => (string) $subscription->currency,
            'starts_at' => $this->formatDateTimeValue($subscription->starts_at),
            'current_period_start' => $this->formatDateTimeValue($subscription->current_period_start),
            'current_period_end' => $this->formatDateTimeValue($subscription->current_period_end),
            'grace_period_ends_at' => $this->formatDateTimeValue($subscription->grace_period_ends_at),
            'suspended_at' => $this->formatDateTimeValue($subscription->suspended_at),
            'terminated_at' => $this->formatDateTimeValue($subscription->terminated_at),
            'show_url' => route('subscriptions.show', $subscription),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePaymentForAdminShow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (int) $payment->amount,
            'currency' => (string) $payment->currency,
            'status' => (string) $payment->status,
            'due_at' => $this->formatDateTimeValue($payment->due_at),
            'paid_at' => $this->formatDateTimeValue($payment->paid_at),
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'period_start' => $this->formatDateTimeValue($payment->period_start),
            'period_end' => $this->formatDateTimeValue($payment->period_end),
            'credit_months_purchased' => $payment->credit_months_purchased !== null
                ? (int) $payment->credit_months_purchased
                : null,
            'credit_exhausted_at' => $this->formatDateTimeValue($payment->credit_exhausted_at),
            'created_at' => $this->formatDateTimeValue($payment->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReminderForAdminShow(SubscriptionReminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'type' => (string) $reminder->reminder_type,
            'threshold_days' => (int) $reminder->threshold_days,
            'scheduled_for' => $this->formatDateTimeValue($reminder->scheduled_for),
            'detected_at' => $this->formatDateTimeValue($reminder->detected_at),
            'sent_at' => $this->formatDateTimeValue($reminder->sent_at),
            'status' => (string) $reminder->status,
            'created_at' => $this->formatDateTimeValue($reminder->created_at),
        ];
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
                    'activated_at' => $this->formatDateTimeValue($installationModule->activated_at),
                    'deactivated_at' => $this->formatDateTimeValue($installationModule->deactivated_at),
                    'show_url' => route('installation-modules.show', $installationModule),
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

    /**
     * Synthèse administrative documentaire (sans vérification technique distante).
     *
     * @return array<string, mixed>
     */
    private function buildAdministrativeReadinessSummary(
        Installation $installation,
        ?Subscription $currentSubscription,
        int $paymentsCount,
        int $modulesCount,
    ): array {
        $hasClient = $installation->client !== null;

        return [
            'control_center_registration' => [
                'label' => 'Enregistrement Control Center',
                'value' => 'Enregistré',
            ],
            'technical_deployment' => [
                'label' => 'Déploiement technique',
                'value' => 'Non suivi actuellement',
                'tracked' => false,
            ],
            'status_administrative_note' => 'Le statut administratif de l’installation (ex. actif) ne signifie pas que l’application MKD-Pro est déployée ou accessible sur le serveur client.',
            'checklist' => [
                [
                    'key' => 'client',
                    'label' => 'Client',
                    'state' => $hasClient ? 'complete' : 'missing',
                    'detail' => $hasClient
                        ? (string) $installation->client->company_name
                        : 'Client introuvable',
                ],
                [
                    'key' => 'installation',
                    'label' => 'Installation',
                    'state' => 'complete',
                    'detail' => 'Fiche enregistrée dans le Control Center',
                ],
                [
                    'key' => 'modules',
                    'label' => 'Modules',
                    'state' => $modulesCount > 0 ? 'complete' : 'empty',
                    'detail' => $modulesCount > 0
                        ? $modulesCount.' module(s) affecté(s)'
                        : 'Aucun module affecté',
                ],
                [
                    'key' => 'subscription',
                    'label' => 'Abonnement',
                    'state' => $currentSubscription !== null ? 'complete' : 'missing',
                    'detail' => $currentSubscription !== null
                        ? 'Abonnement courant présent'
                        : 'Aucun abonnement actif / non terminé',
                ],
                [
                    'key' => 'payment',
                    'label' => 'Paiement',
                    'state' => $paymentsCount > 0 ? 'complete' : 'empty',
                    'detail' => $paymentsCount > 0
                        ? $paymentsCount.' paiement(s) en historique'
                        : 'Aucun paiement enregistré',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeLastProvisioningRunForShow(?ProvisioningRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'retry_of_run_id' => $run->retry_of_run_id,
            'error_message' => $run->error_message,
            'created_at' => $this->formatDateTimeValue($run->created_at),
            'started_at' => $this->formatDateTimeValue($run->started_at),
            'finished_at' => $this->formatDateTimeValue($run->finished_at),
            'steps_total' => (int) ($run->steps_count ?? 0),
            'steps_completed' => (int) ($run->steps_completed_count ?? 0),
            'show_url' => route('provisioning-runs.show', $run),
        ];
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
