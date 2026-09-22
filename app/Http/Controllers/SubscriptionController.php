<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Models\Subscription;
use App\Services\AuditLogService;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $subscriptions = Subscription::query()
            ->with('installation.client')
            ->orderByDesc('id')
            ->paginate(15);

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions,
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
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'amount' => $request->filled('amount') ? $request->input('amount') : 15000,
            'currency' => $request->filled('currency') ? $request->input('currency') : 'XOF',
        ]);

        $validated = $request->validate($this->validationRules());

        $subscription = Subscription::create($validated);

        $this->auditLogService->record(
            'subscription.created',
            auditable: $subscription,
            newValues: $this->subscriptionAuditSnapshot($subscription),
        );

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
        ]);
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

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'installations' => $installations,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $validated = $request->validate($this->validationRules());

        $oldValues = $this->subscriptionAuditSnapshot($subscription);

        $subscription->update($validated);

        $this->auditLogService->record(
            'subscription.updated',
            auditable: $subscription,
            oldValues: $oldValues,
            newValues: $this->subscriptionAuditSnapshot($subscription->fresh()),
        );

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
     * @return array<string, mixed>
     */
    private function validationRules(): array
    {
        return [
            'installation_id' => 'required|integer|exists:installations,id',
            'amount' => 'required|integer|min:0',
            'currency' => 'required|string|size:3',
            'status' => 'required|string|in:active,grace_period,suspended,terminated',
            'starts_at' => 'nullable|date',
            'current_period_start' => 'nullable|date',
            'current_period_end' => 'nullable|date|after_or_equal:current_period_start',
            'grace_period_ends_at' => 'nullable|date',
            'suspended_at' => 'nullable|date',
            'terminated_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ];
    }
}
