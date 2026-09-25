<?php

namespace App\Http\Controllers;

use App\Exceptions\Subscription\SubscriptionServiceException;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\AuditLogService;
use App\Services\SubscriptionService;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    /**
     * @var list<string>
     */
    private const NON_TERMINATED_STATUSES = [
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_GRACE_PERIOD,
        Subscription::STATUS_SUSPENDED,
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
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
                Rule::in([
                    Subscription::STATUS_ACTIVE,
                    Subscription::STATUS_GRACE_PERIOD,
                    Subscription::STATUS_SUSPENDED,
                    Subscription::STATUS_TERMINATED,
                ]),
            ],
            'expiring_within_days' => ['nullable', Rule::in([7])],
        ]);

        $query = Subscription::query()
            ->with('installation.client');

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        if (filled($validated['expiring_within_days'] ?? null)) {
            $query
                ->where('status', Subscription::STATUS_ACTIVE)
                ->whereNotNull('current_period_end')
                ->whereBetween('current_period_end', [now(), now()->addDays(7)]);
        }

        $subscriptions = $query
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'filters' => [
                'status' => $validated['status'] ?? null,
                'expiring_within_days' => isset($validated['expiring_within_days'])
                    ? (int) $validated['expiring_within_days']
                    : null,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        $installations = Installation::query()
            ->with('client')
            ->orderBy('id')
            ->get(['id', 'client_id', 'name', 'subdomain']);

        return Inertia::render('Subscriptions/Create', [
            'installations' => $installations,
            'defaultMonthlyAmount' => (int) config('subscriptions.default_monthly_amount'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'amount' => $request->filled('amount') ? $request->input('amount') : config('subscriptions.default_monthly_amount'),
            'currency' => $request->filled('currency') ? $request->input('currency') : 'XOF',
        ]);

        $validated = $request->validate($this->storeValidationRules());

        try {
            $subscription = DB::transaction(function () use ($validated) {
                $this->assertInstallationAllowsNewSubscription((int) $validated['installation_id']);

                $subscription = Subscription::create([
                    'installation_id' => $validated['installation_id'],
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'status' => Subscription::STATUS_ACTIVE,
                    'starts_at' => $validated['starts_at'],
                    'notes' => $validated['notes'] ?? null,
                ]);

                $subscription = $this->subscriptionService->createInitialPeriod($subscription);

                $this->auditLogService->record(
                    'subscription.created',
                    auditable: $subscription,
                    newValues: $this->subscriptionAuditSnapshot($subscription),
                );

                return $subscription;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'installation_id' => 'Cette installation possède déjà un abonnement non terminé.',
            ]);
        }

        return redirect()
            ->route('subscriptions.show', $subscription)
            ->with('success', 'Abonnement créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Subscription $subscription): Response
    {
        $subscription->load('installation.client');

        return Inertia::render('Subscriptions/Show', [
            'subscription' => $subscription,
            'credit' => $this->subscriptionService->summarizeSubscriptionCreditForDisplay($subscription),
        ]);
    }

    /**
     * Consomme le prochain mois de crédit disponible pour l'abonnement (FIFO).
     */
    public function consumeCredit(Subscription $subscription): RedirectResponse
    {
        $subscriptionBefore = $this->subscriptionAuditSnapshot($subscription);

        try {
            $consumption = $this->subscriptionService->consumeNextCreditForSubscription($subscription);
        } catch (SubscriptionServiceException $exception) {
            $this->auditLogService->record(
                'subscription.credit_consumption_failed',
                auditable: $subscription,
                result: 'failure',
                errorMessage: 'La consommation de crédit a échoué.',
            );

            return redirect()
                ->route('subscriptions.show', $subscription)
                ->with('error', $exception->getMessage());
        }

        $subscriptionAfter = $subscription->fresh();

        $this->auditLogService->record(
            'subscription.credit_consumed',
            auditable: $subscriptionAfter,
            oldValues: [
                'subscription' => $subscriptionBefore,
            ],
            newValues: [
                'subscription' => $this->subscriptionAuditSnapshot($subscriptionAfter),
                'consumption' => [
                    'id' => $consumption->id,
                    'payment_id' => $consumption->payment_id,
                    'period_start' => $consumption->period_start->format('Y-m-d H:i:s'),
                    'period_end' => $consumption->period_end->format('Y-m-d H:i:s'),
                ],
            ],
        );

        return redirect()
            ->route('subscriptions.show', $subscription)
            ->with('success', 'Un mois de crédit a été consommé et l’abonnement a été renouvelé.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Subscription $subscription): Response
    {
        $installations = Installation::query()
            ->with('client')
            ->orderBy('id')
            ->get(['id', 'client_id', 'name', 'subdomain']);

        $credit = $this->subscriptionService->summarizeSubscriptionCreditForDisplay($subscription);

        $hasPendingPayments = false;

        foreach ($credit['payments'] as $paymentRow) {
            if ($paymentRow['status'] === Payment::STATUS_PENDING) {
                $hasPendingPayments = true;

                break;
            }
        }

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'installations' => $installations,
            'credit' => $credit,
            'hasPendingPayments' => $hasPendingPayments,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $validated = $request->validate($this->updateValidationRules());

        $this->assertUpdateBusinessRules($subscription, $validated);

        try {
            DB::transaction(function () use ($subscription, $validated) {
                $oldValues = $this->subscriptionAuditSnapshot($subscription);

                $subscription->update($validated);

                $this->auditLogService->record(
                    'subscription.updated',
                    auditable: $subscription,
                    oldValues: $oldValues,
                    newValues: $this->subscriptionAuditSnapshot($subscription->fresh()),
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'installation_id' => 'Cette installation possède déjà un abonnement non terminé.',
            ]);
        }

        return redirect()
            ->route('subscriptions.show', $subscription)
            ->with('success', 'Abonnement modifié avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subscription $subscription): RedirectResponse
    {
        $oldValues = $this->subscriptionAuditSnapshot($subscription);

        $subscription->delete();

        $this->auditLogService->record(
            'subscription.deleted',
            oldValues: $oldValues,
        );

        return redirect()
            ->route('subscriptions.index')
            ->with('success', 'Abonnement supprimé avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionAuditSnapshot(Subscription $subscription): array
    {
        $snapshot = ['id' => $subscription->id];

        foreach ([
            'installation_id',
            'amount',
            'currency',
            'status',
            'starts_at',
            'current_period_start',
            'current_period_end',
            'grace_period_ends_at',
            'suspended_at',
            'terminated_at',
            'notes',
        ] as $attribute) {
            $value = $subscription->getAttribute($attribute);

            if ($value instanceof DateTimeInterface) {
                $snapshot[$attribute] = $value->format('Y-m-d H:i:s');
            } else {
                $snapshot[$attribute] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * @throws ValidationException
     */
    protected function assertInstallationAllowsNewSubscription(int $installationId): void
    {
        $hasNonTerminatedSubscription = Subscription::query()
            ->where('installation_id', $installationId)
            ->whereIn('status', self::NON_TERMINATED_STATUSES)
            ->exists();

        if ($hasNonTerminatedSubscription) {
            throw ValidationException::withMessages([
                'installation_id' => 'Cette installation possède déjà un abonnement non terminé.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function storeValidationRules(): array
    {
        return [
            'installation_id' => 'required|integer|exists:installations,id',
            'amount' => 'required|integer|min:0',
            'currency' => 'required|string|size:3',
            'starts_at' => 'required|date',
            'notes' => 'nullable|string',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertUpdateBusinessRules(Subscription $subscription, array $validated): void
    {
        if ($subscription->isTerminated() && $validated['status'] !== Subscription::STATUS_TERMINATED) {
            throw ValidationException::withMessages([
                'status' => 'Un abonnement terminé ne peut pas être réactivé.',
            ]);
        }

        if (! in_array($validated['status'], self::NON_TERMINATED_STATUSES, true)) {
            return;
        }

        $resultInstallationId = (int) $validated['installation_id'];

        $hasOtherNonTerminatedSubscription = Subscription::query()
            ->where('installation_id', $resultInstallationId)
            ->whereIn('status', self::NON_TERMINATED_STATUSES)
            ->whereKeyNot($subscription->id)
            ->exists();

        if ($hasOtherNonTerminatedSubscription) {
            throw ValidationException::withMessages([
                'installation_id' => 'Cette installation possède déjà un abonnement non terminé.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function updateValidationRules(): array
    {
        return [
            'installation_id' => 'required|integer|exists:installations,id',
            'amount' => 'required|integer|min:0',
            'currency' => 'required|string|size:3',
            'status' => 'required|string|in:active,grace_period,suspended,terminated',
            'starts_at' => 'nullable|date',
            'current_period_start' => 'nullable|date|required_with:current_period_end',
            'current_period_end' => 'nullable|date|required_with:current_period_start|after_or_equal:current_period_start',
            'grace_period_ends_at' => 'nullable|date',
            'suspended_at' => 'nullable|date',
            'terminated_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ];
    }
}
