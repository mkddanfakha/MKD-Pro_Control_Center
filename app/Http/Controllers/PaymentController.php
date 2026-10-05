<?php

namespace App\Http\Controllers;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Exceptions\Subscription\SubscriptionServiceException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPaymentConsumption;
use App\Services\AuditLogService;
use App\Services\SubscriptionService;
use App\Support\AdminActionAvailability;
use App\Support\OperationalActionAvailability;
use App\Support\AuditLogAdminPresentation;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly AuditLogService $auditLogService,
    ) {}
    /**
     * Display a listing of the resource (consultation administrative read-only).
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    Payment::STATUS_PENDING,
                    Payment::STATUS_PAID,
                    Payment::STATUS_FAILED,
                    Payment::STATUS_REFUNDED,
                ]),
            ],
            'payment_method' => 'nullable|string|max:100',
            'client_id' => 'nullable|integer|exists:clients,id',
            'installation_id' => 'nullable|integer|exists:installations,id',
            'subscription_id' => 'nullable|integer|exists:subscriptions,id',
            'search' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'overdue' => ['nullable', Rule::in([1])],
        ]);

        $query = Payment::query()
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
                'payments.notes',
                'payments.monthly_unit_amount',
                'payments.credit_months_purchased',
                'payments.credit_exhausted_at',
                'payments.period_start',
                'payments.period_end',
                'payments.created_at',
            ])
            ->with([
                'subscription' => fn ($subscriptionQuery) => $subscriptionQuery->select([
                    'id',
                    'installation_id',
                    'status',
                    'amount',
                    'currency',
                ]),
                'subscription.installation' => fn ($installationQuery) => $installationQuery->select([
                    'id',
                    'client_id',
                    'name',
                    'subdomain',
                ]),
                'subscription.installation.client' => fn ($clientQuery) => $clientQuery->select([
                    'id',
                    'company_name',
                ]),
            ])
            ->withCount('consumptions');

        if (filled($validated['status'] ?? null)) {
            $query->where('payments.status', $validated['status']);
        }

        if (filled($validated['payment_method'] ?? null)) {
            $query->where('payments.payment_method', $validated['payment_method']);
        }

        if (filled($validated['subscription_id'] ?? null)) {
            $query->where('payments.subscription_id', (int) $validated['subscription_id']);
        }

        if (filled($validated['installation_id'] ?? null)) {
            $installationId = (int) $validated['installation_id'];
            $query->whereHas('subscription', fn ($subscriptionQuery) => $subscriptionQuery->where('installation_id', $installationId));
        }

        if (filled($validated['client_id'] ?? null)) {
            $clientId = (int) $validated['client_id'];
            $query->whereHas('subscription.installation', fn ($installationQuery) => $installationQuery->where('client_id', $clientId));
        }

        if (filled($validated['search'] ?? null)) {
            $search = $validated['search'];
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($builder) use ($term, $search): void {
                $builder
                    ->where('payments.reference', 'like', $term)
                    ->orWhereHas('subscription.installation', function ($installationQuery) use ($term): void {
                        $installationQuery
                            ->where('name', 'like', $term)
                            ->orWhere('subdomain', 'like', $term)
                            ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('company_name', 'like', $term));
                    });

                if (ctype_digit($search)) {
                    $builder->orWhere('payments.id', (int) $search);
                }
            });
        }

        if (filled($validated['date_from'] ?? null) || filled($validated['date_to'] ?? null)) {
            $dateFrom = $validated['date_from'] ?? null;
            $dateTo = $validated['date_to'] ?? null;

            $query->where(function ($dateQuery) use ($dateFrom, $dateTo): void {
                $dateQuery->where(function ($paidQuery) use ($dateFrom, $dateTo): void {
                    $paidQuery
                        ->where('payments.status', Payment::STATUS_PAID)
                        ->whereNotNull('payments.paid_at');

                    if ($dateFrom !== null) {
                        $paidQuery->where('payments.paid_at', '>=', $dateFrom.' 00:00:00');
                    }

                    if ($dateTo !== null) {
                        $paidQuery->where('payments.paid_at', '<=', $dateTo.' 23:59:59');
                    }
                })->orWhere(function ($otherQuery) use ($dateFrom, $dateTo): void {
                    $otherQuery->where('payments.status', '!=', Payment::STATUS_PAID);

                    if ($dateFrom !== null) {
                        $otherQuery->where('payments.created_at', '>=', $dateFrom.' 00:00:00');
                    }

                    if ($dateTo !== null) {
                        $otherQuery->where('payments.created_at', '<=', $dateTo.' 23:59:59');
                    }
                });
            });
        }

        if (filled($validated['overdue'] ?? null) && (int) $validated['overdue'] === 1) {
            $query
                ->whereIn('payments.status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED])
                ->whereNotNull('payments.due_at')
                ->where('payments.due_at', '<', now());
        }

        $payments = $query
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Payment $payment): array => $this->serializePaymentForAdminIndex($payment));

        return Inertia::render('Payments/Index', [
            'payments' => $payments,
            'indicators' => $this->paymentAdminIndicators(),
            'filters' => [
                'status' => $validated['status'] ?? null,
                'payment_method' => $validated['payment_method'] ?? null,
                'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
                'installation_id' => isset($validated['installation_id']) ? (int) $validated['installation_id'] : null,
                'subscription_id' => isset($validated['subscription_id']) ? (int) $validated['subscription_id'] : null,
                'search' => $validated['search'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'overdue' => isset($validated['overdue']) ? (int) $validated['overdue'] : null,
            ],
            'admin_urls' => [
                'create' => route('payments.create'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Subscriptions/Payments/Create', [
            'subscriptions' => $this->subscriptionsForForm(),
            'defaultMonthlyAmount' => (int) config('subscriptions.default_monthly_amount'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->validationRules());

        $this->assertValidatedPaymentPeriodFieldsCoherent($validated);

        $validated = array_merge($validated, $this->resolveValidatedPaymentCreditFields($validated));

        $payment = Payment::create($validated);

        $this->auditLogService->record(
            'payment.created',
            auditable: $payment,
            newValues: $this->paymentAuditSnapshot($payment),
        );

        return redirect()
            ->route('payments.show', $payment)
            ->with('success', 'Paiement créé avec succès.');
    }

    /**
     * Fiche administrative détaillée du paiement (lecture seule).
     */
    public function show(Request $request, Payment $payment): Response
    {
        $payment->load([
            'subscription.installation.client',
            'consumptions' => fn ($query) => $query->orderBy('consumed_at')->orderBy('id'),
        ]);
        $payment->loadCount('consumptions');

        $credit = $this->paymentCreditForDisplay($payment);
        unset($credit['consumptions']);

        $subscription = $payment->subscription;
        $installation = $subscription?->installation;
        $client = $installation?->client;

        return Inertia::render('Payments/Show', [
            'payment' => $this->serializePaymentForAdminShow($payment),
            'credit' => $credit,
            'consumptions' => $this->serializeConsumptionsForAdminShow($payment),
            'subscription' => $subscription !== null
                ? $this->serializeSubscriptionForPaymentAdminShow($subscription)
                : null,
            'installation' => $installation !== null
                ? $this->serializeInstallationForPaymentAdminShow($installation)
                : null,
            'client' => $client !== null
                ? $this->serializeClientForPaymentAdminShow($client)
                : null,
            'audit_history' => $this->paymentAuditHistoryForAdminShow($payment),
            'navigation' => [
                'payments_index' => route('payments.index'),
                'subscription_show' => $subscription !== null
                    ? route('subscriptions.show', $subscription)
                    : null,
                'installation_show' => $installation !== null
                    ? route('installations.show', $installation)
                    : null,
                'client_show' => $client !== null
                    ? route('clients.show', $client)
                    : null,
                'audit_logs_index' => route('audit-logs.index', [
                    'auditable_type' => $payment->getMorphClass(),
                    'auditable_id' => $payment->id,
                ]),
                'edit' => route('payments.edit', $payment),
            ],
            'admin_urls' => AdminActionAvailability::mergeIntoAdminUrls(
                AdminActionAvailability::payment($payment),
                ['edit' => route('payments.edit', $payment)],
            ),
        ]);
    }

    /**
     * Renouvelle explicitement l'abonnement lié à un paiement payé.
     */
    public function renewSubscription(Payment $payment): RedirectResponse
    {
        $payment->loadMissing('subscription');

        $subscription = $payment->subscription;

        $paymentBefore = $this->paymentAuditSnapshot($payment);
        $subscriptionBefore = $subscription !== null
            ? $this->subscriptionAuditSnapshot($subscription)
            : null;

        try {
            $this->subscriptionService->renewFromPayment($payment);
        } catch (SubscriptionServiceException $exception) {
            $this->auditLogService->record(
                'payment.renewal_failed',
                auditable: $payment,
                result: 'failure',
                errorMessage: 'Le renouvellement de l’abonnement a échoué.',
            );

            return redirect()
                ->route('payments.show', $payment)
                ->with('error', $exception->getMessage());
        }

        $paymentAfterRenewal = $payment->fresh();
        $subscriptionAfterRenewal = $subscription?->fresh();

        $this->auditLogService->record(
            'payment.renewal_applied',
            auditable: $paymentAfterRenewal,
            oldValues: [
                'payment' => $paymentBefore,
                'subscription' => $subscriptionBefore,
            ],
            newValues: [
                'payment' => $this->paymentAuditSnapshot($paymentAfterRenewal),
                'subscription' => $subscriptionAfterRenewal !== null
                    ? $this->subscriptionAuditSnapshot($subscriptionAfterRenewal)
                    : null,
            ],
        );

        return redirect()
            ->route('payments.show', $payment)
            ->with('success', 'Abonnement renouvelé avec succès pour la période suivante.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Payment $payment): Response
    {
        $payment->loadCount('consumptions');
        $payment->loadMissing('subscription');

        return Inertia::render('Subscriptions/Payments/Edit', [
            'payment' => $payment,
            'subscriptions' => $this->subscriptionsForForm(),
            'operational_actions' => OperationalActionAvailability::forPayment($payment),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validate($this->validationRules());

        $consumptionCount = $payment->consumptions()->count();

        if ($consumptionCount > 0 && $validated['status'] !== Payment::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Un paiement dont le crédit a déjà été consommé doit conserver le statut payé.',
            ]);
        }

        if ($payment->hasRenewalBeenApplied() && $consumptionCount === 0 && $validated['status'] !== Payment::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Un paiement déjà utilisé pour un renouvellement doit conserver le statut payé.',
            ]);
        }

        $this->assertValidatedPaymentPeriodFieldsCoherent($validated);

        if ($consumptionCount > 0) {
            $this->assertConsumedPaymentImmutableFinancialFields($payment, $validated);
        } elseif ($payment->hasRenewalBeenApplied()) {
            $this->assertRenewalAppliedPaymentImmutableFields($payment, $validated);
        }

        if ($consumptionCount === 0) {
            $validated = array_merge($validated, $this->resolveValidatedPaymentCreditFields($validated));
        }

        $oldValues = $this->paymentAuditSnapshot($payment);

        $payment->update($validated);

        $this->auditLogService->record(
            'payment.updated',
            auditable: $payment,
            oldValues: $oldValues,
            newValues: $this->paymentAuditSnapshot($payment->fresh()),
        );

        return redirect()
            ->route('payments.show', $payment)
            ->with('success', 'Paiement modifié avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Payment $payment): RedirectResponse
    {
        if ($payment->consumptions()->exists()) {
            throw ValidationException::withMessages([
                'payment' => 'Ce paiement ne peut pas être supprimé car son crédit a déjà été consommé.',
            ]);
        }

        $oldValues = $this->paymentAuditSnapshot($payment);

        $payment->delete();

        $this->auditLogService->record(
            'payment.deleted',
            oldValues: $oldValues,
        );

        return redirect()
            ->route('payments.index')
            ->with('success', 'Paiement supprimé avec succès.');
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentAuditSnapshot(Payment $payment): array
    {
        $snapshot = ['id' => $payment->id];

        foreach ([
            'subscription_id',
            'amount',
            'currency',
            'status',
            'due_at',
            'paid_at',
            'period_start',
            'period_end',
            'payment_method',
            'reference',
            'notes',
            'renewal_applied_at',
            'monthly_unit_amount',
            'credit_months_purchased',
            'credit_exhausted_at',
        ] as $attribute) {
            $value = $payment->getAttribute($attribute);

            if ($value instanceof DateTimeInterface) {
                $snapshot[$attribute] = $value->format('Y-m-d H:i:s');
            } else {
                $snapshot[$attribute] = $value;
            }
        }

        return $snapshot;
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Subscription>
     */
    private function subscriptionsForForm()
    {
        return Subscription::query()
            ->with([
                'installation:id,name,subdomain,client_id',
                'installation.client:id,company_name',
            ])
            ->orderByDesc('id')
            ->get(['id', 'amount', 'currency', 'status', 'installation_id']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertValidatedPaymentPeriodFieldsCoherent(array $validated): void
    {
        $start = $validated['period_start'] ?? null;
        $end = $validated['period_end'] ?? null;

        $startEmpty = $start === null || $start === '';
        $endEmpty = $end === null || $end === '';

        if ($startEmpty && $endEmpty) {
            return;
        }

        if ($startEmpty || $endEmpty) {
            throw ValidationException::withMessages([
                'period_start' => 'Les dates de période doivent être renseignées ensemble ou laissées vides.',
                'period_end' => 'Les dates de période doivent être renseignées ensemble ou laissées vides.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{monthly_unit_amount: int, credit_months_purchased: int}
     */
    private function resolveValidatedPaymentCreditFields(array $validated): array
    {
        $subscription = Subscription::query()->find($validated['subscription_id']);

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'subscription_id' => 'Abonnement introuvable.',
            ]);
        }

        if ((string) $validated['currency'] !== (string) $subscription->currency) {
            throw ValidationException::withMessages([
                'currency' => 'La devise doit correspondre exactement à la devise de l\'abonnement.',
            ]);
        }

        try {
            return $this->subscriptionService->calculatePaymentCreditFields(
                $subscription,
                (int) $validated['amount'],
                (string) $validated['currency'],
            );
        } catch (SubscriptionRenewalException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertConsumedPaymentImmutableFinancialFields(Payment $payment, array $validated): void
    {
        $errors = [];

        if ((int) $validated['subscription_id'] !== (int) $payment->subscription_id) {
            $errors['subscription_id'] = 'L\'abonnement ne peut pas être modifié après consommation de crédit.';
        }

        if ((int) $validated['amount'] !== (int) $payment->amount) {
            $errors['amount'] = 'Le montant ne peut pas être modifié après consommation de crédit.';
        }

        if ((string) $validated['currency'] !== (string) $payment->currency) {
            $errors['currency'] = 'La devise ne peut pas être modifiée après consommation de crédit.';
        }

        if ($this->normalizedRequestDate($validated['period_start'] ?? null) !== $this->normalizedPaymentDate($payment->period_start)) {
            $errors['period_start'] = 'La date de début de période ne peut pas être modifiée après consommation de crédit.';
        }

        if ($this->normalizedRequestDate($validated['period_end'] ?? null) !== $this->normalizedPaymentDate($payment->period_end)) {
            $errors['period_end'] = 'La date de fin de période ne peut pas être modifiée après consommation de crédit.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertRenewalAppliedPaymentImmutableFields(Payment $payment, array $validated): void
    {
        if (! $payment->hasRenewalBeenApplied()) {
            return;
        }

        $errors = [];

        if ((int) $validated['subscription_id'] !== (int) $payment->subscription_id) {
            $errors['subscription_id'] = 'L\'abonnement ne peut pas être modifié après un renouvellement appliqué.';
        }

        if ((int) $validated['amount'] !== (int) $payment->amount) {
            $errors['amount'] = 'Le montant ne peut pas être modifié après un renouvellement appliqué.';
        }

        if ((string) $validated['currency'] !== (string) $payment->currency) {
            $errors['currency'] = 'La devise ne peut pas être modifiée après un renouvellement appliqué.';
        }

        if ($this->normalizedRequestDate($validated['period_start'] ?? null) !== $this->normalizedPaymentDate($payment->period_start)) {
            $errors['period_start'] = 'La date de début de période ne peut pas être modifiée après un renouvellement appliqué.';
        }

        if ($this->normalizedRequestDate($validated['period_end'] ?? null) !== $this->normalizedPaymentDate($payment->period_end)) {
            $errors['period_end'] = 'La date de fin de période ne peut pas être modifiée après un renouvellement appliqué.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function normalizedRequestDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function normalizedPaymentDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentCreditForDisplay(Payment $payment): array
    {
        $consumptionsCount = (int) $payment->consumptions_count;
        $remaining = $payment->remainingCreditMonths();
        $purchased = $payment->credit_months_purchased !== null
            ? (int) $payment->credit_months_purchased
            : null;
        $monthlyUnitAmount = $payment->monthly_unit_amount !== null
            ? (int) $payment->monthly_unit_amount
            : null;
        $hasCreditDefinition = $purchased !== null && $monthlyUnitAmount !== null;

        $consumptions = $payment->consumptions
            ->map(function (SubscriptionPaymentConsumption $consumption): array {
                return [
                    'id' => $consumption->id,
                    'period_start' => $consumption->period_start?->format('Y-m-d H:i:s'),
                    'period_end' => $consumption->period_end?->format('Y-m-d H:i:s'),
                    'consumed_at' => $consumption->consumed_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->values()
            ->all();

        $isPaid = $payment->isPaid();
        $isRefunded = $payment->isRefunded();

        $subscription = $payment->relationLoaded('subscription') ? $payment->subscription : null;
        $isSubscriptionTerminated = $subscription !== null && $subscription->isTerminated();

        return [
            'amount' => (int) $payment->amount,
            'currency' => (string) $payment->currency,
            'monthly_unit_amount' => $monthlyUnitAmount,
            'credit_months_purchased' => $purchased,
            'credit_months_remaining' => $remaining,
            'consumptions_count' => $consumptionsCount,
            'credit_exhausted_at' => $payment->credit_exhausted_at?->format('Y-m-d H:i:s'),
            'status' => (string) $payment->status,
            'is_refunded' => $isRefunded,
            'is_paid' => $isPaid,
            'presents_consumable_credit' => $isPaid && ! $isRefunded && $remaining > 0 && $hasCreditDefinition && ! $isSubscriptionTerminated,
            'is_exhausted' => $hasCreditDefinition && $remaining === 0 && $consumptionsCount > 0,
            'show_credit_details' => $hasCreditDefinition || $consumptionsCount > 0,
            'consumptions' => $consumptions,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     paid: int,
     *     pending: int,
     *     failed: int,
     *     refunded: int,
     *     total_paid_amount: int,
     *     total_pending_amount: int,
     *     total_refunded_amount: int,
     * }
     */
    private function paymentAdminIndicators(): array
    {
        $row = Payment::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid', [Payment::STATUS_PAID])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending', [Payment::STATUS_PENDING])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [Payment::STATUS_FAILED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refunded', [Payment::STATUS_REFUNDED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as total_paid_amount', [Payment::STATUS_PAID])
            ->selectRaw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as total_pending_amount', [Payment::STATUS_PENDING])
            ->selectRaw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as total_refunded_amount', [Payment::STATUS_REFUNDED])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'paid' => (int) ($row->paid ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'refunded' => (int) ($row->refunded ?? 0),
            'total_paid_amount' => (int) ($row->total_paid_amount ?? 0),
            'total_pending_amount' => (int) ($row->total_pending_amount ?? 0),
            'total_refunded_amount' => (int) ($row->total_refunded_amount ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePaymentForAdminIndex(Payment $payment): array
    {
        $subscription = $payment->subscription;
        $installation = $subscription?->installation;
        $client = $installation?->client;

        return [
            'id' => $payment->id,
            'amount' => (int) $payment->amount,
            'currency' => (string) $payment->currency,
            'status' => (string) $payment->status,
            'due_at' => $this->formatAdminDateTime($payment->due_at),
            'paid_at' => $this->formatAdminDateTime($payment->paid_at),
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'monthly_unit_amount' => $payment->monthly_unit_amount !== null
                ? (int) $payment->monthly_unit_amount
                : null,
            'credit_months_purchased' => $payment->credit_months_purchased !== null
                ? (int) $payment->credit_months_purchased
                : null,
            'credit_exhausted_at' => $this->formatAdminDateTime($payment->credit_exhausted_at),
            'period_start' => $this->formatAdminDateTime($payment->period_start),
            'period_end' => $this->formatAdminDateTime($payment->period_end),
            'created_at' => $this->formatAdminDateTime($payment->created_at),
            'consumptions_count' => (int) ($payment->consumptions_count ?? 0),
            'subscription' => $subscription !== null ? [
                'id' => $subscription->id,
                'status' => (string) $subscription->status,
                'amount' => (int) $subscription->amount,
                'currency' => (string) $subscription->currency,
            ] : null,
            'installation' => $installation !== null ? [
                'id' => $installation->id,
                'name' => (string) $installation->name,
                'subdomain' => (string) $installation->subdomain,
            ] : null,
            'client' => $client !== null ? [
                'id' => $client->id,
                'name' => (string) $client->company_name,
                'show_url' => route('clients.show', $client),
            ] : null,
            'subscription_show_url' => $subscription !== null
                ? route('subscriptions.show', $subscription)
                : null,
            'installation_show_url' => $installation !== null
                ? route('installations.show', $installation)
                : null,
            'client_show_url' => $client !== null
                ? route('clients.show', $client)
                : null,
            'show_url' => route('payments.show', $payment),
            'edit_url' => route('payments.edit', $payment),
            ...AdminActionAvailability::paymentFromConsumptionsCount((int) ($payment->consumptions_count ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePaymentForAdminShow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
            'amount' => (int) $payment->amount,
            'currency' => (string) $payment->currency,
            'status' => (string) $payment->status,
            'due_at' => $this->formatAdminDateTime($payment->due_at),
            'paid_at' => $this->formatAdminDateTime($payment->paid_at),
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'period_start' => $this->formatAdminDateTime($payment->period_start),
            'period_end' => $this->formatAdminDateTime($payment->period_end),
            'renewal_applied_at' => $this->formatAdminDateTime($payment->renewal_applied_at),
            'created_at' => $this->formatAdminDateTime($payment->created_at),
            'updated_at' => $this->formatAdminDateTime($payment->updated_at),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeConsumptionsForAdminShow(Payment $payment): array
    {
        return $payment->consumptions
            ->map(fn (SubscriptionPaymentConsumption $consumption): array => [
                'id' => $consumption->id,
                'payment_id' => (int) $consumption->payment_id,
                'subscription_id' => (int) $consumption->subscription_id,
                'period_start' => $this->formatAdminDateTime($consumption->period_start),
                'period_end' => $this->formatAdminDateTime($consumption->period_end),
                'consumed_at' => $this->formatAdminDateTime($consumption->consumed_at),
                'created_at' => $this->formatAdminDateTime($consumption->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubscriptionForPaymentAdminShow(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => (string) $subscription->status,
            'amount' => (int) $subscription->amount,
            'currency' => (string) $subscription->currency,
            'starts_at' => $this->formatAdminDateTime($subscription->starts_at),
            'current_period_start' => $this->formatAdminDateTime($subscription->current_period_start),
            'current_period_end' => $this->formatAdminDateTime($subscription->current_period_end),
            'grace_period_ends_at' => $this->formatAdminDateTime($subscription->grace_period_ends_at),
            'suspended_at' => $this->formatAdminDateTime($subscription->suspended_at),
            'terminated_at' => $this->formatAdminDateTime($subscription->terminated_at),
            'show_url' => route('subscriptions.show', $subscription),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInstallationForPaymentAdminShow(\App\Models\Installation $installation): array
    {
        return [
            'id' => $installation->id,
            'name' => (string) $installation->name,
            'subdomain' => (string) $installation->subdomain,
            'domain' => $installation->domain,
            'status' => (string) $installation->status,
            'version' => $installation->version,
            'show_url' => route('installations.show', $installation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClientForPaymentAdminShow(\App\Models\Client $client): array
    {
        return [
            'id' => $client->id,
            'company_name' => (string) $client->company_name,
            'contact_name' => (string) $client->contact_name,
            'email' => $client->email,
            'phone' => $client->phone,
            'status' => (string) $client->status,
            'show_url' => route('clients.show', $client),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentAuditHistoryForAdminShow(Payment $payment): array
    {
        $actions = [
            'payment.created',
            'payment.updated',
            'payment.deleted',
            'payment.renewal_applied',
            'payment.renewal_failed',
        ];

        $paymentMorph = $payment->getMorphClass();

        $logs = AuditLog::query()
            ->with('user')
            ->where('auditable_type', $paymentMorph)
            ->where('auditable_id', $payment->id)
            ->whereIn('action', $actions)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        if ($logs->isEmpty()) {
            return [];
        }

        $subjectContext = AuditLogAdminPresentation::buildSubjectContext($logs);

        return $logs
            ->map(
                fn (AuditLog $log): array => AuditLogAdminPresentation::serializeEntry($log, $subjectContext),
            )
            ->values()
            ->all();
    }

    private function formatAdminDateTime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(): array
    {
        return [
            'subscription_id' => 'required|integer|exists:subscriptions,id',
            'amount' => 'required|integer|min:1',
            'currency' => 'required|string|size:3',
            'monthly_unit_amount' => 'prohibited',
            'credit_months_purchased' => 'prohibited',
            'credit_exhausted_at' => 'prohibited',
            'renewal_applied_at' => 'prohibited',
            'status' => 'required|in:pending,paid,failed,refunded',
            'due_at' => 'nullable|date',
            'paid_at' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => in_array(request()->input('status'), [
                    Payment::STATUS_PAID,
                    Payment::STATUS_REFUNDED,
                ], true)),
                Rule::prohibitedIf(fn () => in_array(request()->input('status'), [
                    Payment::STATUS_PENDING,
                    Payment::STATUS_FAILED,
                ], true)),
            ],
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'payment_method' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ];
    }
}
