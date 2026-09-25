<?php

namespace App\Http\Controllers;

use App\Exceptions\Subscription\SubscriptionRenewalException;
use App\Exceptions\Subscription\SubscriptionServiceException;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\AuditLogService;
use App\Services\SubscriptionService;
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
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $payments = Payment::query()
            ->with([
                'subscription.installation.client',
            ])
            ->orderByDesc('id')
            ->paginate(15);

        return Inertia::render('Subscriptions/Payments/Index', [
            'payments' => $payments,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Subscriptions/Payments/Create', [
            'subscriptions' => $this->subscriptionsForForm(),
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
     * Display the specified resource.
     */
    public function show(Payment $payment): Response
    {
        $payment->load([
            'subscription.installation.client',
        ]);

        $canRenewSubscription = $this->subscriptionService->canRenewFromPayment($payment);

        return Inertia::render('Subscriptions/Payments/Show', [
            'payment' => $payment,
            'canRenewSubscription' => $canRenewSubscription,
            'renewalPreview' => $canRenewSubscription
                ? $this->subscriptionService->previewRenewalFromPayment($payment)
                : null,
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
        return Inertia::render('Subscriptions/Payments/Edit', [
            'payment' => $payment,
            'subscriptions' => $this->subscriptionsForForm(),
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
    private function validationRules(): array
    {
        return [
            'subscription_id' => 'required|integer|exists:subscriptions,id',
            'amount' => 'required|integer|min:1',
            'currency' => 'required|string|size:3',
            'monthly_unit_amount' => 'prohibited',
            'credit_months_purchased' => 'prohibited',
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
