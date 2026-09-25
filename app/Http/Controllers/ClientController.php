<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\AuditLogService;
use App\Services\InstallationAccessService;
use App\Services\SubscriptionService;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly InstallationAccessService $installationAccessService,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                Rule::in(['active', 'inactive']),
            ],
        ]);

        $query = Client::query();

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        $clients = $query
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => [
                'status' => $validated['status'] ?? null,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Clients/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
        ]);

        $client = Client::create($validated);

        $this->auditLogService->record(
            'client.created',
            auditable: $client,
            newValues: $this->clientAuditSnapshot($client),
        );

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Client $client)
    {
        $client->load([
            'installations' => fn ($query) => $query->orderByDesc('id'),
        ]);

        $installationIds = $client->installations->pluck('id');

        if ($installationIds->isNotEmpty()) {
            $subscriptionsByInstallation = Subscription::query()
                ->whereIn('installation_id', $installationIds)
                ->orderByDesc('id')
                ->get()
                ->groupBy('installation_id');

            $client->installations->each(function (Installation $installation) use ($subscriptionsByInstallation): void {
                $installation->setRelation(
                    'subscriptions',
                    $subscriptionsByInstallation->get($installation->id, collect()),
                );
            });
        }

        $installationOverviews = $client->installations
            ->map(fn (Installation $installation) => $this->installationEcosystemOverview($installation))
            ->values()
            ->all();

        $ecosystemSummary = $this->clientEcosystemSummary($installationIds, $installationOverviews);

        return Inertia::render('Clients/Show', [
            'client' => $client->only([
                'id',
                'company_name',
                'contact_name',
                'phone',
                'email',
                'address',
                'city',
                'country',
                'status',
                'notes',
                'created_at',
                'updated_at',
            ]),
            'installationOverviews' => $installationOverviews,
            'ecosystemSummary' => $ecosystemSummary,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Client $client)
    {
        return Inertia::render('Clients/Edit', [
            'client' => $client,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
        ]);

        $oldValues = $this->clientAuditSnapshot($client);

        $client->update($validated);

        $this->auditLogService->record(
            'client.updated',
            auditable: $client,
            oldValues: $oldValues,
            newValues: $this->clientAuditSnapshot($client->fresh()),
        );

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Client modifié avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client)
    {
        $oldValues = $this->clientAuditSnapshot($client);

        $client->delete();

        $this->auditLogService->record(
            'client.deleted',
            oldValues: $oldValues,
        );

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client supprimé avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function installationEcosystemOverview(Installation $installation): array
    {
        $access = $this->installationAccessService->accessSummary($installation);
        $currentSubscription = $this->installationAccessService->latestSubscription($installation);

        $displaySubscription = $currentSubscription;

        if ($displaySubscription === null) {
            $displaySubscription = $installation->subscriptions
                ->where('status', Subscription::STATUS_TERMINATED)
                ->sortByDesc('id')
                ->first();
        }

        $subscriptionPayload = $displaySubscription !== null
            ? $this->serializeSubscriptionOverview($displaySubscription, $currentSubscription !== null)
            : null;

        $creditPayload = null;

        if ($currentSubscription !== null) {
            $creditSummary = $this->subscriptionService->summarizeSubscriptionCreditForDisplay($currentSubscription);
            $creditPayload = [
                'available_months' => (int) $creditSummary['available_months'],
                'payment_count' => (int) $creditSummary['payment_count'],
            ];
        } elseif ($displaySubscription !== null && $displaySubscription->isTerminated()) {
            $creditSummary = $this->subscriptionService->summarizeSubscriptionCreditForDisplay($displaySubscription);
            $creditPayload = [
                'available_months' => 0,
                'payment_count' => (int) $creditSummary['payment_count'],
            ];
        }

        return [
            'installation' => [
                'id' => $installation->id,
                'name' => $installation->name,
                'subdomain' => $installation->subdomain,
                'domain' => $installation->domain,
                'status' => $installation->status,
            ],
            'access' => $access,
            'subscription' => $subscriptionPayload,
            'credit' => $creditPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubscriptionOverview(Subscription $subscription, bool $isCurrentNonTerminated): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'amount' => (int) $subscription->amount,
            'currency' => (string) $subscription->currency,
            'current_period_start' => $this->formatDateTimeAttribute($subscription->current_period_start),
            'current_period_end' => $this->formatDateTimeAttribute($subscription->current_period_end),
            'is_current_non_terminated' => $isCurrentNonTerminated,
        ];
    }

    /**
     * @param  Collection<int, int|string>  $installationIds
     * @param  list<array<string, mixed>>  $installationOverviews
     * @return array<string, mixed>
     */
    private function clientEcosystemSummary(Collection $installationIds, array $installationOverviews): array
    {
        $totalAvailableCreditMonths = collect($installationOverviews)
            ->sum(fn (array $overview) => (int) ($overview['credit']['available_months'] ?? 0));

        if ($installationIds->isEmpty()) {
            return [
                'payments_count' => 0,
                'last_payment' => null,
                'total_available_credit_months' => 0,
            ];
        }

        $subscriptionIds = Subscription::query()
            ->whereIn('installation_id', $installationIds)
            ->pluck('id');

        if ($subscriptionIds->isEmpty()) {
            return [
                'payments_count' => 0,
                'last_payment' => null,
                'total_available_credit_months' => $totalAvailableCreditMonths,
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
            'payments_count' => $paymentsCount,
            'last_payment' => $lastPayment !== null ? [
                'id' => $lastPayment->id,
                'amount' => (int) $lastPayment->amount,
                'currency' => (string) $lastPayment->currency,
                'paid_at' => $this->formatDateTimeAttribute($lastPayment->paid_at),
            ] : null,
            'total_available_credit_months' => $totalAvailableCreditMonths,
        ];
    }

    private function formatDateTimeAttribute(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientAuditSnapshot(Client $client): array
    {
        return array_merge(
            ['id' => $client->id],
            $client->only([
                'company_name',
                'contact_name',
                'phone',
                'email',
                'address',
                'city',
                'country',
                'status',
                'notes',
            ]),
        );
    }
}
