<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Support\AdminActionAvailability;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
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
            'search' => 'nullable|string|max:255',
            'email' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        $nonTerminatedSubscriptionStatuses = [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_GRACE_PERIOD,
            Subscription::STATUS_SUSPENDED,
        ];

        $query = Client::query()
            ->select('clients.*')
            ->selectSub(
                Subscription::query()
                    ->join('installations', 'installations.id', '=', 'subscriptions.installation_id')
                    ->whereColumn('installations.client_id', 'clients.id')
                    ->whereIn('subscriptions.status', $nonTerminatedSubscriptionStatuses)
                    ->selectRaw('count(*)'),
                'non_terminated_subscriptions_count',
            )
            ->withCount([
                'installations',
                'installations as installations_active_count' => fn ($installationQuery) => $installationQuery->where('status', 'active'),
                'installations as installations_inactive_count' => fn ($installationQuery) => $installationQuery->where('status', 'inactive'),
                'installations as installations_suspended_count' => fn ($installationQuery) => $installationQuery->where('status', 'suspended'),
                'installations as installations_terminated_count' => fn ($installationQuery) => $installationQuery->where('status', 'terminated'),
            ]);

        if (filled($validated['status'] ?? null)) {
            $query->where('clients.status', $validated['status']);
        }

        if (filled($validated['email'] ?? null)) {
            $emailTerm = '%'.addcslashes($validated['email'], '%_\\').'%';
            $query->where('clients.email', 'like', $emailTerm);
        }

        if (filled($validated['phone'] ?? null)) {
            $phoneTerm = '%'.addcslashes($validated['phone'], '%_\\').'%';
            $query->where('clients.phone', 'like', $phoneTerm);
        }

        if (filled($validated['search'] ?? null)) {
            $term = '%'.addcslashes($validated['search'], '%_\\').'%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('clients.company_name', 'like', $term)
                    ->orWhere('clients.contact_name', 'like', $term)
                    ->orWhere('clients.email', 'like', $term)
                    ->orWhere('clients.phone', 'like', $term)
                    ->orWhereHas('installations', function ($installationQuery) use ($term): void {
                        $installationQuery
                            ->where('name', 'like', $term)
                            ->orWhere('subdomain', 'like', $term)
                            ->orWhere('domain', 'like', $term);
                    });
            });
        }

        $clients = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Client $client): array => $this->serializeClientForAdminIndex($client));

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => [
                'status' => $validated['status'] ?? null,
                'search' => $validated['search'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ],
            'admin_urls' => [
                'create' => route('clients.create'),
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
     * Display the specified resource (consultation administrative read-only).
     */
    public function show(Request $request, Client $client): Response
    {
        $statistics = $this->clientAdminStatistics($client);

        $installations = Installation::query()
            ->where('client_id', $client->id)
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
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'installations_page')
            ->withQueryString()
            ->through(fn (Installation $installation): array => $this->serializeInstallationForClientShow($installation));

        $subscriptions = Subscription::query()
            ->join('installations', 'installations.id', '=', 'subscriptions.installation_id')
            ->where('installations.client_id', $client->id)
            ->select([
                'subscriptions.id',
                'subscriptions.installation_id',
                'subscriptions.amount',
                'subscriptions.currency',
                'subscriptions.status',
                'subscriptions.starts_at',
                'subscriptions.current_period_start',
                'subscriptions.current_period_end',
                'subscriptions.grace_period_ends_at',
                'subscriptions.suspended_at',
                'subscriptions.terminated_at',
                'installations.name as installation_name',
            ])
            ->orderByDesc('subscriptions.current_period_end')
            ->orderByDesc('subscriptions.id')
            ->paginate(10, ['*'], 'subscriptions_page')
            ->withQueryString()
            ->through(fn (Subscription $subscription): array => $this->serializeSubscriptionForClientShow($subscription));

        $payments = Payment::query()
            ->join('subscriptions', 'subscriptions.id', '=', 'payments.subscription_id')
            ->join('installations', 'installations.id', '=', 'subscriptions.installation_id')
            ->where('installations.client_id', $client->id)
            ->select([
                'payments.id',
                'payments.subscription_id',
                'payments.amount',
                'payments.currency',
                'payments.status',
                'payments.due_at',
                'payments.paid_at',
                'payments.payment_method',
                'payments.reference',
                'payments.period_start',
                'payments.period_end',
                'payments.credit_months_purchased',
                'payments.created_at',
                'installations.id as installation_id',
                'installations.name as installation_name',
            ])
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->paginate(10, ['*'], 'payments_page')
            ->withQueryString()
            ->through(fn (Payment $payment): array => $this->serializePaymentForClientShow($payment));

        $reminders = SubscriptionReminder::query()
            ->join('subscriptions', 'subscriptions.id', '=', 'subscription_reminders.subscription_id')
            ->join('installations', 'installations.id', '=', 'subscriptions.installation_id')
            ->where('installations.client_id', $client->id)
            ->select([
                'subscription_reminders.id',
                'subscription_reminders.subscription_id',
                'subscription_reminders.reminder_type',
                'subscription_reminders.threshold_days',
                'subscription_reminders.scheduled_for',
                'subscription_reminders.detected_at',
                'subscription_reminders.sent_at',
                'subscription_reminders.status',
                'subscription_reminders.created_at',
                'installations.id as installation_id',
                'installations.name as installation_name',
            ])
            ->orderByDesc('subscription_reminders.scheduled_for')
            ->orderByDesc('subscription_reminders.id')
            ->paginate(10, ['*'], 'reminders_page')
            ->withQueryString()
            ->through(fn (SubscriptionReminder $reminder): array => $this->serializeReminderForClientShow($reminder));

        return Inertia::render('Clients/Show', [
            'client' => $this->serializeClientForAdminShow($client),
            'statistics' => $statistics,
            'installations' => $installations,
            'subscriptions' => $subscriptions,
            'payments' => $payments,
            'reminders' => $reminders,
            'navigation' => [
                'clients_index' => route('clients.index'),
                'payments_index' => route('payments.index', ['client_id' => $client->id]),
                'edit' => route('clients.edit', $client),
            ],
            'admin_urls' => AdminActionAvailability::mergeIntoAdminUrls(
                AdminActionAvailability::client($client),
                ['edit' => route('clients.edit', $client)],
            ),
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
        if ($client->installations()->exists()) {
            return redirect()
                ->route('clients.show', $client)
                ->with('error', 'Ce client ne peut pas être supprimé car des installations lui sont encore associées.');
        }

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
     * @return array{installations: array<string, int>, subscriptions: array<string, int>}
     */
    private function clientAdminStatistics(Client $client): array
    {
        $installationStats = Installation::query()
            ->where('client_id', $client->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive")
            ->selectRaw("SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended")
            ->selectRaw("SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as terminated_count")
            ->first();

        $subscriptionStats = Subscription::query()
            ->join('installations', 'installations.id', '=', 'subscriptions.installation_id')
            ->where('installations.client_id', $client->id)
            ->selectRaw('SUM(CASE WHEN subscriptions.status = ? THEN 1 ELSE 0 END) as active', [Subscription::STATUS_ACTIVE])
            ->selectRaw('SUM(CASE WHEN subscriptions.status = ? THEN 1 ELSE 0 END) as grace_period', [Subscription::STATUS_GRACE_PERIOD])
            ->selectRaw('SUM(CASE WHEN subscriptions.status = ? THEN 1 ELSE 0 END) as suspended', [Subscription::STATUS_SUSPENDED])
            ->selectRaw('SUM(CASE WHEN subscriptions.status = ? THEN 1 ELSE 0 END) as terminated_count', [Subscription::STATUS_TERMINATED])
            ->first();

        return [
            'installations' => [
                'total' => (int) ($installationStats->total ?? 0),
                'active' => (int) ($installationStats->active ?? 0),
                'inactive' => (int) ($installationStats->inactive ?? 0),
                'suspended' => (int) ($installationStats->suspended ?? 0),
                'terminated' => (int) ($installationStats->terminated_count ?? 0),
            ],
            'subscriptions' => [
                'active' => (int) ($subscriptionStats->active ?? 0),
                'grace_period' => (int) ($subscriptionStats->grace_period ?? 0),
                'suspended' => (int) ($subscriptionStats->suspended ?? 0),
                'terminated' => (int) ($subscriptionStats->terminated_count ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClientForAdminShow(Client $client): array
    {
        return [
            'id' => $client->id,
            'company_name' => (string) $client->company_name,
            'contact_name' => (string) $client->contact_name,
            'email' => $client->email,
            'phone' => $client->phone,
            'address' => $client->address,
            'city' => $client->city,
            'country' => $client->country,
            'status' => (string) $client->status,
            'created_at' => $this->formatDateTimeAttribute($client->created_at),
            'updated_at' => $this->formatDateTimeAttribute($client->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInstallationForClientShow(Installation $installation): array
    {
        return [
            'id' => $installation->id,
            'name' => (string) $installation->name,
            'subdomain' => (string) $installation->subdomain,
            'domain' => $installation->domain,
            'status' => (string) $installation->status,
            'version' => $installation->version,
            'installed_at' => $this->formatDateTimeAttribute($installation->installed_at),
            'last_seen_at' => $this->formatDateTimeAttribute($installation->last_seen_at),
            'show_url' => route('installations.show', $installation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubscriptionForClientShow(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'installation_id' => (int) $subscription->installation_id,
            'installation_name' => (string) ($subscription->installation_name ?? ''),
            'amount' => (int) $subscription->amount,
            'currency' => (string) $subscription->currency,
            'status' => (string) $subscription->status,
            'starts_at' => $this->formatDateTimeAttribute($subscription->starts_at),
            'current_period_start' => $this->formatDateTimeAttribute($subscription->current_period_start),
            'current_period_end' => $this->formatDateTimeAttribute($subscription->current_period_end),
            'grace_period_ends_at' => $this->formatDateTimeAttribute($subscription->grace_period_ends_at),
            'suspended_at' => $this->formatDateTimeAttribute($subscription->suspended_at),
            'terminated_at' => $this->formatDateTimeAttribute($subscription->terminated_at),
            'show_url' => route('subscriptions.show', $subscription),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePaymentForClientShow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'subscription_id' => (int) $payment->subscription_id,
            'installation_id' => isset($payment->installation_id) ? (int) $payment->installation_id : null,
            'installation_name' => $payment->installation_name ?? null,
            'amount' => (int) $payment->amount,
            'currency' => (string) $payment->currency,
            'status' => (string) $payment->status,
            'due_at' => $this->formatDateTimeAttribute($payment->due_at),
            'paid_at' => $this->formatDateTimeAttribute($payment->paid_at),
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'period_start' => $this->formatDateTimeAttribute($payment->period_start),
            'period_end' => $this->formatDateTimeAttribute($payment->period_end),
            'credit_months_purchased' => $payment->credit_months_purchased !== null
                ? (int) $payment->credit_months_purchased
                : null,
            'created_at' => $this->formatDateTimeAttribute($payment->created_at),
            'subscription_show_url' => route('subscriptions.show', $payment->subscription_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReminderForClientShow(SubscriptionReminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'subscription_id' => (int) $reminder->subscription_id,
            'installation_id' => isset($reminder->installation_id) ? (int) $reminder->installation_id : null,
            'installation_name' => $reminder->installation_name ?? null,
            'type' => (string) $reminder->reminder_type,
            'threshold_days' => (int) $reminder->threshold_days,
            'scheduled_for' => $this->formatDateTimeAttribute($reminder->scheduled_for),
            'detected_at' => $this->formatDateTimeAttribute($reminder->detected_at),
            'sent_at' => $this->formatDateTimeAttribute($reminder->sent_at),
            'status' => (string) $reminder->status,
            'created_at' => $this->formatDateTimeAttribute($reminder->created_at),
            'subscription_show_url' => route('subscriptions.show', $reminder->subscription_id),
            'installation_show_url' => isset($reminder->installation_id)
                ? route('installations.show', (int) $reminder->installation_id)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClientForAdminIndex(Client $client): array
    {
        return [
            'id' => $client->id,
            'company_name' => (string) $client->company_name,
            'contact_name' => (string) $client->contact_name,
            'email' => $client->email,
            'phone' => $client->phone,
            'status' => (string) $client->status,
            'created_at' => $this->formatDateTimeAttribute($client->created_at),
            'installations_count' => (int) ($client->installations_count ?? 0),
            'installations_active_count' => (int) ($client->installations_active_count ?? 0),
            'installations_inactive_count' => (int) ($client->installations_inactive_count ?? 0),
            'installations_suspended_count' => (int) ($client->installations_suspended_count ?? 0),
            'installations_terminated_count' => (int) ($client->installations_terminated_count ?? 0),
            'non_terminated_subscriptions_count' => (int) ($client->non_terminated_subscriptions_count ?? 0),
            'show_url' => route('clients.show', $client),
            'edit_url' => route('clients.edit', $client),
            ...AdminActionAvailability::clientFromInstallationsCount([
                'installations_count' => $client->installations_count ?? 0,
            ]),
        ];
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
