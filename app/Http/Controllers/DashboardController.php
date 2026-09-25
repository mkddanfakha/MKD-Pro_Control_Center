<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the operational control center dashboard.
     */
    public function index(): Response
    {
        $clientStats = $this->clientStatistics();
        $installationStats = $this->installationStatistics();
        $subscriptionStats = $this->subscriptionStatistics();
        $paymentStats = $this->paymentStatistics();

        return Inertia::render('Dashboard', [
            'totalClients' => Client::query()->count(),
            'activeClients' => (int) $clientStats->active,
            'inactiveClients' => (int) $clientStats->inactive_count,
            'totalInstallations' => (int) $installationStats->total,
            'activeInstallations' => (int) $installationStats->active,
            'suspendedInstallations' => (int) $installationStats->suspended,
            'terminatedInstallations' => (int) $installationStats->terminated_count,
            'totalSubscriptions' => (int) $subscriptionStats->total,
            'activeSubscriptions' => (int) $subscriptionStats->active,
            'gracePeriodSubscriptions' => (int) $subscriptionStats->grace_period,
            'suspendedSubscriptions' => (int) $subscriptionStats->suspended,
            'terminatedSubscriptions' => (int) $subscriptionStats->terminated_count,
            'totalPayments' => (int) $paymentStats->total,
            'paidPayments' => (int) $paymentStats->paid,
            'pendingPayments' => (int) $paymentStats->pending,
            'failedPayments' => (int) $paymentStats->failed,
            'refundedPayments' => (int) $paymentStats->refunded,
            'totalPaidAmount' => (int) Payment::query()
                ->where('status', Payment::STATUS_PAID)
                ->sum('amount'),
            'subscriptionsExpiringSoon' => Subscription::query()
                ->where('status', Subscription::STATUS_ACTIVE)
                ->whereNotNull('current_period_end')
                ->whereBetween('current_period_end', [now(), now()->addDays(7)])
                ->count(),
            'overduePayments' => Payment::query()
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED])
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count(),
            'recentActivities' => $this->recentActivities(),
        ]);
    }

    /**
     * @return object{total: int|string, active: int|string, inactive_count: int|string}
     */
    private function clientStatistics(): object
    {
        return Client::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count")
            ->first();
    }

    /**
     * @return object{total: int|string, active: int|string, suspended: int|string, terminated_count: int|string}
     */
    private function installationStatistics(): object
    {
        return Installation::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended")
            ->selectRaw("SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as terminated_count")
            ->first();
    }

    /**
     * @return object{total: int|string, active: int|string, grace_period: int|string, suspended: int|string, terminated_count: int|string}
     */
    private function subscriptionStatistics(): object
    {
        return Subscription::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active', [Subscription::STATUS_ACTIVE])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as grace_period', [Subscription::STATUS_GRACE_PERIOD])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as suspended', [Subscription::STATUS_SUSPENDED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as terminated_count', [Subscription::STATUS_TERMINATED])
            ->first();
    }

    /**
     * @return object{total: int|string, paid: int|string, pending: int|string, failed: int|string, refunded: int|string}
     */
    private function paymentStatistics(): object
    {
        return Payment::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid', [Payment::STATUS_PAID])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending', [Payment::STATUS_PENDING])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [Payment::STATUS_FAILED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refunded', [Payment::STATUS_REFUNDED])
            ->first();
    }

    /**
     * @return list<array{type: string, type_label: string, label: string, occurred_at: string|null, url: string}>
     */
    private function recentActivities(): array
    {
        /** @var Collection<int, array{type: string, type_label: string, label: string, occurred_at: string|null, url: string, sort_key: string}> $activities */
        $activities = collect();

        Client::query()
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'company_name', 'created_at'])
            ->each(function (Client $client) use ($activities): void {
                $activities->push([
                    'type' => 'client',
                    'type_label' => 'Client',
                    'label' => $client->company_name,
                    'occurred_at' => $client->created_at?->toIso8601String(),
                    'url' => route('clients.show', $client),
                    'sort_key' => $client->created_at?->format('U') ?? '0',
                ]);
            });

        Installation::query()
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'name', 'created_at'])
            ->each(function (Installation $installation) use ($activities): void {
                $activities->push([
                    'type' => 'installation',
                    'type_label' => 'Installation',
                    'label' => $installation->name,
                    'occurred_at' => $installation->created_at?->toIso8601String(),
                    'url' => route('installations.show', $installation),
                    'sort_key' => $installation->created_at?->format('U') ?? '0',
                ]);
            });

        Subscription::query()
            ->with('installation:id,name')
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'installation_id', 'amount', 'currency', 'created_at'])
            ->each(function (Subscription $subscription) use ($activities): void {
                $installationName = $subscription->installation?->name ?? '—';
                $amount = number_format((int) $subscription->amount, 0, ',', ' ');
                $currency = $subscription->currency ?? 'XOF';

                $activities->push([
                    'type' => 'subscription',
                    'type_label' => 'Abonnement',
                    'label' => "{$installationName} — {$amount} {$currency}",
                    'occurred_at' => $subscription->created_at?->toIso8601String(),
                    'url' => route('subscriptions.show', $subscription),
                    'sort_key' => $subscription->created_at?->format('U') ?? '0',
                ]);
            });

        Payment::query()
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'amount', 'currency', 'reference', 'created_at'])
            ->each(function (Payment $payment) use ($activities): void {
                $amount = number_format((int) $payment->amount, 0, ',', ' ');
                $currency = $payment->currency ?? 'XOF';
                $reference = $payment->reference ? " ({$payment->reference})" : '';

                $activities->push([
                    'type' => 'payment',
                    'type_label' => 'Paiement',
                    'label' => "{$amount} {$currency}{$reference}",
                    'occurred_at' => $payment->created_at?->toIso8601String(),
                    'url' => route('payments.show', $payment),
                    'sort_key' => $payment->created_at?->format('U') ?? '0',
                ]);
            });

        return $activities
            ->sortByDesc('sort_key')
            ->take(10)
            ->map(fn (array $activity) => [
                'type' => $activity['type'],
                'type_label' => $activity['type_label'],
                'label' => $activity['label'],
                'occurred_at' => $activity['occurred_at'],
                'url' => $activity['url'],
            ])
            ->values()
            ->all();
    }
}
