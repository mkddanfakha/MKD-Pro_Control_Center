<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Subscription;
use App\Services\SubscriptionAutomaticCreditRenewalService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Audit lecture seule — renouvellement automatique par crédit (Task 280).
 */
#[Signature('subscriptions:automatic-renewal-audit {--installation=} {--subscription=} {--eligible-only} {--json}')]
#[Description('Audit read-only des abonnements pour le renouvellement automatique par crédit')]
class AuditSubscriptionAutomaticCreditRenewal extends Command
{
    public const PERIOD_FUTURE = 'FUTURE';

    public const PERIOD_CURRENT = 'CURRENT';

    public const PERIOD_EXPIRED = 'EXPIRED';

    public const PERIOD_UNKNOWN = 'UNKNOWN';

    public function __construct(
        private readonly SubscriptionAutomaticCreditRenewalService $automaticCreditRenewalService,
        private readonly SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $rows = [];
        $reasonCounts = [];

        $this->buildQuery()
            ->with(['installation:id,name'])
            ->orderBy('subscriptions.id')
            ->chunkById(200, function ($subscriptions) use ($now, &$rows, &$reasonCounts): void {
                foreach ($subscriptions as $subscription) {
                    $row = $this->analyzeSubscription($subscription, $now);
                    $rows[] = $row;

                    if (! $row['eligible'] && $row['reason'] !== null) {
                        $reason = (string) $row['reason'];
                        $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                    }
                }
            });

        if ((bool) $this->option('eligible-only')) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['eligible']));
            $reasonCounts = [];
            foreach ($rows as $row) {
                if (! $row['eligible'] && $row['reason'] !== null) {
                    $reason = (string) $row['reason'];
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                }
            }
        }

        $summary = $this->buildSummary($rows);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'environment' => (string) config('app.env'),
                'automatic_renewal_enabled' => $this->automaticCreditRenewalService->isEnabled(),
                'evaluated' => $summary['evaluated'],
                'eligible' => $summary['eligible'],
                'not_eligible' => $summary['not_eligible'],
                'with_credit' => $summary['with_credit'],
                'without_credit' => $summary['without_credit'],
                'expired_period' => $summary['expired_period'],
                'terminated' => $summary['terminated'],
                'reasons' => $reasonCounts,
                'subscriptions' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderTextHeader();
        $this->renderRows($rows);
        $this->renderSummary($summary, $reasonCounts);

        return self::SUCCESS;
    }

    /**
     * @return Builder<Subscription>
     */
    private function buildQuery(): Builder
    {
        $query = Subscription::query();

        if ($this->option('subscription') !== null && $this->option('subscription') !== '') {
            $query->whereKey((int) $this->option('subscription'));
        }

        if ($this->option('installation') !== null && $this->option('installation') !== '') {
            $query->where('installation_id', (int) $this->option('installation'));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeSubscription(Subscription $subscription, Carbon $now): array
    {
        $creditSummary = $this->subscriptionService->summarizeSubscriptionCreditForDisplay($subscription);
        $availableMonths = (int) ($creditSummary['available_months'] ?? 0);
        $paidPaymentsWithCredit = $this->countPaidPaymentsWithConsumableCredit($creditSummary['payments'] ?? []);

        $assessment = $this->automaticCreditRenewalService->assessEligibility($subscription, $now);
        $periodState = $this->resolvePeriodState($subscription, $now);

        return [
            'subscription_id' => $subscription->id,
            'installation_id' => $subscription->installation_id,
            'installation_name' => $subscription->installation?->name,
            'status' => (string) $subscription->status,
            'current_period_start' => $subscription->current_period_start?->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->current_period_end?->format('Y-m-d H:i:s'),
            'period_state' => $periodState,
            'available_months' => $availableMonths,
            'paid_payments_with_credit' => $paidPaymentsWithCredit,
            'has_consumable_credit' => $availableMonths > 0,
            'eligible' => (bool) $assessment['eligible'],
            'reason' => $assessment['reason'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $paymentRows
     */
    private function countPaidPaymentsWithConsumableCredit(array $paymentRows): int
    {
        $count = 0;

        foreach ($paymentRows as $paymentRow) {
            if (($paymentRow['status'] ?? '') !== Payment::STATUS_PAID) {
                continue;
            }

            if ((int) ($paymentRow['credit_months_remaining'] ?? 0) <= 0) {
                continue;
            }

            if (($paymentRow['is_refunded'] ?? false) === true) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function resolvePeriodState(Subscription $subscription, Carbon $now): string
    {
        if ($subscription->current_period_start === null || $subscription->current_period_end === null) {
            return self::PERIOD_UNKNOWN;
        }

        $start = Carbon::parse($subscription->current_period_start);
        $end = Carbon::parse($subscription->current_period_end);

        if ($now->greaterThan($end)) {
            return self::PERIOD_EXPIRED;
        }

        if ($now->lessThan($start)) {
            return self::PERIOD_FUTURE;
        }

        return self::PERIOD_CURRENT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function buildSummary(array $rows): array
    {
        $summary = [
            'evaluated' => count($rows),
            'eligible' => 0,
            'not_eligible' => 0,
            'with_credit' => 0,
            'without_credit' => 0,
            'expired_period' => 0,
            'terminated' => 0,
        ];

        foreach ($rows as $row) {
            if ($row['eligible']) {
                $summary['eligible']++;
            } else {
                $summary['not_eligible']++;
            }

            if ($row['has_consumable_credit']) {
                $summary['with_credit']++;
            } else {
                $summary['without_credit']++;
            }

            if ($row['period_state'] === self::PERIOD_EXPIRED) {
                $summary['expired_period']++;
            }

            if ($row['status'] === Subscription::STATUS_TERMINATED) {
                $summary['terminated']++;
            }
        }

        return $summary;
    }

    private function renderTextHeader(): void
    {
        $enabled = $this->automaticCreditRenewalService->isEnabled();

        $this->line('Audit du renouvellement automatique (lecture seule)');
        $this->line('Automatic renewal : '.($enabled ? 'ENABLED' : 'DISABLED'));
        $this->newLine();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderRows(array $rows): void
    {
        foreach ($rows as $row) {
            $eligibleLabel = $row['eligible'] ? 'yes' : 'no';
            $reason = $row['reason'] ?? '-';

            $this->line(sprintf(
                '#%d inst=%d (%s) status=%s [%s→%s] state=%s credit=%dmo paid_payments=%d eligible=%s reason=%s',
                $row['subscription_id'],
                $row['installation_id'],
                $row['installation_name'] ?? '?',
                $row['status'],
                $row['current_period_start'] ?? '?',
                $row['current_period_end'] ?? '?',
                $row['period_state'],
                $row['available_months'],
                $row['paid_payments_with_credit'],
                $eligibleLabel,
                $reason,
            ));
        }

        if ($rows === []) {
            $this->line('(aucun abonnement dans le périmètre)');
        }

        $this->newLine();
    }

    /**
     * @param  array<string, int>  $summary
     * @param  array<string, int>  $reasonCounts
     */
    private function renderSummary(array $summary, array $reasonCounts): void
    {
        $this->line('Audit du renouvellement automatique');
        $this->line('------------------------------------');
        $this->line('Évalués       : '.$summary['evaluated']);
        $this->line('Éligibles     : '.$summary['eligible']);
        $this->line('Non éligibles : '.$summary['not_eligible']);
        $this->line('Crédit dispo  : '.$summary['with_credit']);
        $this->line('Sans crédit   : '.$summary['without_credit']);
        $this->line('Expirés       : '.$summary['expired_period']);
        $this->line('Terminated    : '.$summary['terminated']);
        $this->newLine();

        if ($reasonCounts === []) {
            $this->line('Raisons : (aucune)');

            return;
        }

        $this->line('Raisons :');

        foreach ($reasonCounts as $reason => $count) {
            $this->line(sprintf('- %s : %d', $reason, $count));
        }
    }
}
