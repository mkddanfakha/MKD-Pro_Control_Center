<?php

namespace App\Http\Controllers;

use App\Exceptions\Subscription\SubscriptionServiceException;
use App\Models\Installation;
use App\Models\OfferVersion;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionOfferSnapshot;
use App\Services\AuditLogService;
use App\Services\Commercial\CommercialSubscriptionService;
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
        private readonly CommercialSubscriptionService $commercialSubscriptionService,
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
            'selectableOfferVersions' => $this->commercialSubscriptionService->selectableActiveOfferVersionsForForm(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->storeValidationRules());

        $offerVersion = $this->commercialSubscriptionService->resolveOfferVersionForNewSubscription(
            (int) $validated['offer_version_id'],
        );

        if ($validated['currency'] !== $offerVersion->currency) {
            throw ValidationException::withMessages([
                'currency' => 'La devise doit correspondre à celle de la version commerciale.',
            ]);
        }

        if (! array_key_exists('amount', $validated) || $validated['amount'] === null) {
            $validated['amount'] = (int) $offerVersion->price;
        }

        try {
            $subscription = DB::transaction(function () use ($validated, $offerVersion) {
                $this->assertInstallationAllowsNewSubscription((int) $validated['installation_id']);

                $lockedOfferVersion = OfferVersion::query()
                    ->whereKey($offerVersion->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->commercialSubscriptionService->resolveOfferVersionForNewSubscription($lockedOfferVersion->id);

                $subscription = Subscription::create([
                    'installation_id' => $validated['installation_id'],
                    'offer_version_id' => $lockedOfferVersion->id,
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'status' => Subscription::STATUS_ACTIVE,
                    'starts_at' => $validated['starts_at'],
                    'notes' => $validated['notes'] ?? null,
                ]);

                $subscription = $this->subscriptionService->createInitialPeriod($subscription);

                SubscriptionOfferSnapshot::createFromOfferVersion(
                    $subscription,
                    $lockedOfferVersion,
                    $validated['negotiated_rate_reason'] ?? null,
                );

                $subscription->load(['offerVersion.offer.product', 'offerSnapshot']);

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
        $subscription->load([
            'installation.client',
            'offerSnapshot',
            'offerVersion.offer.product',
        ]);

        return Inertia::render('Subscriptions/Show', [
            'subscription' => $this->serializeSubscriptionForAdminShow($subscription),
            'commercial' => $this->serializeCommercialForSubscriptionShow($subscription),
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
        if ($request->has('offer_version_id')) {
            throw ValidationException::withMessages([
                'offer_version_id' => 'La référence commerciale historique de l’abonnement ne peut pas être modifiée.',
            ]);
        }

        $validated = $request->validate($this->updateValidationRules());

        unset($validated['offer_version_id']);

        $this->assertUpdateBusinessRules($subscription, $validated);

        try {
            DB::transaction(function () use ($subscription, $validated) {
                $subscription->loadMissing('offerSnapshot');
                $oldValues = $this->subscriptionAuditSnapshot($subscription);

                $subscription->update($validated);

                $updatedSubscription = $subscription->fresh();
                $updatedSubscription?->loadMissing('offerSnapshot');

                $this->auditLogService->record(
                    'subscription.updated',
                    auditable: $updatedSubscription ?? $subscription,
                    oldValues: $oldValues,
                    newValues: $this->subscriptionAuditSnapshot($updatedSubscription ?? $subscription),
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
        if ($subscription->offerSnapshot()->exists()) {
            return redirect()
                ->route('subscriptions.show', $subscription)
                ->with('error', 'Cet abonnement ne peut pas être supprimé car un enregistrement commercial figé y est associé.');
        }

        if ($subscription->payments()->exists()) {
            return redirect()
                ->route('subscriptions.show', $subscription)
                ->with('error', 'Cet abonnement ne peut pas être supprimé car des paiements lui sont encore associés.');
        }

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
            'offer_version_id',
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

        if ($subscription->relationLoaded('offerSnapshot') && $subscription->offerSnapshot !== null) {
            $commercialHistory = $subscription->offerSnapshot;
            $snapshot['offer_version_code'] = $commercialHistory->offer_version_code;
            $snapshot['offer_code'] = $commercialHistory->offer_code;
            $snapshot['catalogue_price'] = (int) $commercialHistory->catalogue_price;
            $snapshot['effective_price_at_subscription'] = (int) $commercialHistory->effective_price_at_subscription;
        } elseif ($subscription->relationLoaded('offerVersion') && $subscription->offerVersion !== null) {
            $snapshot['offer_version_code'] = $subscription->offerVersion->code;
            $snapshot['catalogue_price'] = (int) $subscription->offerVersion->price;

            if ($subscription->offerVersion->relationLoaded('offer') && $subscription->offerVersion->offer !== null) {
                $snapshot['offer_code'] = $subscription->offerVersion->offer->code;
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
            'offer_version_id' => 'required|integer|exists:offer_versions,id',
            'amount' => 'nullable|integer|min:0',
            'currency' => 'required|string|size:3',
            'starts_at' => 'required|date',
            'notes' => 'nullable|string',
            'negotiated_rate_reason' => 'nullable|string',
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
    private function serializeSubscriptionForAdminShow(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'offer_version_id' => $subscription->offer_version_id,
            'status' => (string) $subscription->status,
            'amount' => (int) $subscription->amount,
            'currency' => (string) $subscription->currency,
            'starts_at' => $this->formatAdminDateTime($subscription->starts_at),
            'current_period_start' => $this->formatAdminDateTime($subscription->current_period_start),
            'current_period_end' => $this->formatAdminDateTime($subscription->current_period_end),
            'grace_period_ends_at' => $this->formatAdminDateTime($subscription->grace_period_ends_at),
            'suspended_at' => $this->formatAdminDateTime($subscription->suspended_at),
            'terminated_at' => $this->formatAdminDateTime($subscription->terminated_at),
            'notes' => $subscription->notes,
            'created_at' => $this->formatAdminDateTime($subscription->created_at),
            'updated_at' => $this->formatAdminDateTime($subscription->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCommercialForSubscriptionShow(Subscription $subscription): array
    {
        $historyStatus = $this->resolveCommercialHistoryStatus($subscription);

        $snapshot = null;
        if ($subscription->offerSnapshot !== null) {
            $snap = $subscription->offerSnapshot;
            $snapshot = [
                'offer_code' => $snap->offer_code,
                'offer_version_code' => $snap->offer_version_code,
                'product_code' => $snap->product_code,
                'offer_name' => $snap->offer_name,
                'catalogue_price' => (int) $snap->catalogue_price,
                'effective_price_at_subscription' => (int) $snap->effective_price_at_subscription,
                'currency' => (string) $snap->currency,
                'billing_cycle' => $snap->billing_cycle,
                'contract_reference' => $snap->contract_reference,
                'negotiated_rate_reason' => $snap->negotiated_rate_reason,
            ];
        }

        $offerVersion = null;
        if ($subscription->offerVersion !== null) {
            $version = $subscription->offerVersion;
            $offer = $version->offer;
            $offerVersion = [
                'id' => $version->id,
                'code' => (string) $version->code,
                'version' => $version->version,
                'price' => (int) $version->price,
                'currency' => (string) $version->currency,
                'billing_cycle' => $version->billing_cycle,
                'status' => (string) $version->status,
                'offer' => $offer !== null ? [
                    'id' => $offer->id,
                    'code' => (string) $offer->code,
                    'name' => (string) $offer->name,
                ] : null,
            ];
        }

        return [
            'history_status' => $historyStatus,
            'legacy_unspecified' => $subscription->offer_version_id === null,
            'offer_version' => $offerVersion,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @return 'available'|'legacy_unavailable'|'missing_snapshot'
     */
    private function resolveCommercialHistoryStatus(Subscription $subscription): string
    {
        if ($subscription->offerSnapshot !== null) {
            return 'available';
        }

        if ($subscription->offer_version_id === null) {
            return 'legacy_unavailable';
        }

        return 'missing_snapshot';
    }
}
