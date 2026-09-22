<?php

namespace App\Http\Controllers;

use App\Exceptions\Subscription\SubscriptionServiceException;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\AuditLogService;
use App\Services\SubscriptionService;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * @return array<string, mixed>
     */
    private function validationRules(): array
    {
        return [
            'subscription_id' => 'required|integer|exists:subscriptions,id',
            'amount' => 'required|integer|min:0',
            'currency' => 'required|string|size:3',
            'status' => 'required|in:pending,paid,failed,refunded',
            'due_at' => 'nullable|date',
            'paid_at' => 'nullable|date',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'payment_method' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ];
    }
}
