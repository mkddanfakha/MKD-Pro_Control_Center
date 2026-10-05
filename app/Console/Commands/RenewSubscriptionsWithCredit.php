<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionAutomaticCreditRenewalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrateur CLI du renouvellement automatique par crédit (Tasks 272–276, dry-run Task 277).
 *
 * Aucune logique de crédit locale : délégation exclusive à {@see SubscriptionAutomaticCreditRenewalService}.
 */
#[Signature('subscriptions:renew-with-credit {--dry-run : Simule le renouvellement sans modifier les données}')]
#[Description('Applique au plus un mois de crédit déjà payé par abonnement éligible (désactivé si automatic_credit_renewal.enabled=false)')]
class RenewSubscriptionsWithCredit extends Command
{
    public function __construct(
        private readonly SubscriptionAutomaticCreditRenewalService $automaticCreditRenewalService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->automaticCreditRenewalService->isEnabled()) {
            $this->renderDisabledSummary($dryRun);

            return self::SUCCESS;
        }

        $this->warnIfProductionAutomaticRenewalEnabled($dryRun);

        $startedAt = microtime(true);
        $quiet = $this->output->isQuiet();

        $stats = $this->emptyRunStats($dryRun);

        if (! $quiet) {
            if ($dryRun) {
                $this->line('DRY-RUN — aucune donnée ne sera modifiée');
                $this->newLine();
            }

            $this->line('Renouvellement par crédit prépayé (maximum un mois par abonnement et par exécution)');
            $this->newLine();
        }

        $this->automaticCreditRenewalService
            ->querySubscriptionsForRenewalEvaluation()
            ->chunkById(100, function ($subscriptions) use ($quiet, &$stats, $dryRun): void {
                foreach ($subscriptions as $subscription) {
                    if ($dryRun) {
                        $this->processSubscriptionDryRun($subscription, $quiet, $stats);
                    } else {
                        $this->processSubscription($subscription, $quiet, $stats);
                    }
                }
            });

        $stats['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        if ($dryRun) {
            $this->renderDryRunSummary($stats, $quiet);
        } else {
            $this->renderRunSummary($stats, $quiet);
        }

        $this->logRunSummary($stats, $dryRun);

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRunStats(bool $dryRun): array
    {
        return [
            'dry_run' => $dryRun,
            'evaluated' => 0,
            'renewed' => 0,
            'simulated' => 0,
            'skipped' => 0,
            'rejected' => 0,
            'errors' => 0,
            'skip_reasons' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function processSubscription(Subscription $subscription, bool $quiet, array &$stats): void
    {
        $stats['evaluated']++;

        try {
            $result = $this->automaticCreditRenewalService->attemptAutomaticRenewal($subscription);
        } catch (Throwable $exception) {
            $stats['errors']++;

            Log::error('subscriptions:renew-with-credit — erreur technique', [
                'dry_run' => false,
                'subscription_id' => $subscription->id,
                'exception' => $exception,
            ]);

            if (! $quiet) {
                $this->error("✗ Abonnement #{$subscription->id} : erreur technique (voir les logs)");
            }

            return;
        }

        match ($result['outcome']) {
            SubscriptionAutomaticCreditRenewalService::OUTCOME_CONSUMED => (function () use ($quiet, &$stats, $subscription, $result): void {
                $stats['renewed']++;
                if (! $quiet) {
                    $this->line(sprintf(
                        '✓ Abonnement #%d : mois consommé (payment #%s, consumption #%s)',
                        $subscription->id,
                        $result['payment_id'] ?? '?',
                        $result['consumption_id'] ?? '?',
                    ));
                }
            })(),
            SubscriptionAutomaticCreditRenewalService::OUTCOME_FAILED => (function () use ($quiet, &$stats, $subscription, $result): void {
                $stats['rejected']++;
                if (! $quiet) {
                    $this->warn(sprintf(
                        '⚠ Abonnement #%d : consommation refusée par le moteur (%s)',
                        $subscription->id,
                        $result['reason'] ?? 'consumption_rejected',
                    ));
                }
            })(),
            default => (function () use (&$stats, $result): void {
                $stats['skipped']++;
                $reason = (string) ($result['reason'] ?? 'not_eligible');
                $stats['skip_reasons'][$reason] = ($stats['skip_reasons'][$reason] ?? 0) + 1;
            })(),
        };
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function processSubscriptionDryRun(Subscription $subscription, bool $quiet, array &$stats): void
    {
        $stats['evaluated']++;

        try {
            $result = $this->automaticCreditRenewalService->simulateAutomaticRenewal($subscription);
        } catch (Throwable $exception) {
            $stats['errors']++;

            Log::error('subscriptions:renew-with-credit — erreur technique', [
                'dry_run' => true,
                'subscription_id' => $subscription->id,
                'exception' => $exception,
            ]);

            if (! $quiet) {
                $this->error("✗ Abonnement #{$subscription->id} : erreur technique (dry-run, voir les logs)");
            }

            return;
        }

        if ($result['outcome'] === SubscriptionAutomaticCreditRenewalService::OUTCOME_WOULD_CONSUME) {
            $stats['simulated']++;

            if (! $quiet) {
                $this->line(sprintf(
                    '◦ Abonnement #%d : simulation renouvellement (payment #%s, période %s → %s, statut → %s, crédit payment avant=%s mois)',
                    $subscription->id,
                    $result['payment_id'] ?? '?',
                    $subscription->current_period_end?->format('Y-m-d H:i:s') ?? '?',
                    $result['simulated_period_end'] ?? '?',
                    $result['simulated_status'] ?? Subscription::STATUS_ACTIVE,
                    $result['remaining_months_before'] ?? '?',
                ));
            }

            return;
        }

        $stats['skipped']++;
        $reason = (string) ($result['reason'] ?? 'not_eligible');
        $stats['skip_reasons'][$reason] = ($stats['skip_reasons'][$reason] ?? 0) + 1;

        if (! $quiet) {
            $this->line(sprintf(
                '– Abonnement #%d : ignoré (%s)',
                $subscription->id,
                $reason,
            ));
        }
    }

    private function warnIfProductionAutomaticRenewalEnabled(bool $dryRun): void
    {
        if ((string) config('app.env') !== 'production' || $this->output->isQuiet()) {
            return;
        }

        $this->newLine();
        $this->warn('ATTENTION : APP_ENV=production et renouvellement automatique ACTIVÉ.');

        if ($dryRun) {
            $this->warn('Mode dry-run : aucune consommation réelle ne sera effectuée.');
        } else {
            $this->warn('Exécution réelle : des crédits prépayés seront consommés sur les abonnements éligibles.');
        }

        $this->newLine();
    }

    private function renderDisabledSummary(bool $dryRunRequested): void
    {
        if ($this->output->isQuiet()) {
            return;
        }

        $this->line('Renouvellement automatique par crédit : désactivé (subscriptions.automatic_credit_renewal.enabled=false).');

        if ($dryRunRequested) {
            $this->line('Mode dry-run demandé : aucune simulation (activation fonctionnelle requise pour simuler).');
        }

        $this->line('Résumé : fonctionnalité désactivée — 0 évalué, 0 renouvelé, 0 erreur technique.');
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function renderRunSummary(array $stats, bool $quiet): void
    {
        if ($quiet) {
            return;
        }

        $this->newLine();
        $this->line(sprintf(
            'Résumé : %d évalués, %d renouvelés, %d ignorés (non éligibles), %d refusés (moteur), %d erreur(s) technique(s), durée %d ms',
            $stats['evaluated'],
            $stats['renewed'],
            $stats['skipped'],
            $stats['rejected'],
            $stats['errors'],
            $stats['duration_ms'] ?? 0,
        ));

        $this->renderSkipReasonsLine($stats);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function renderDryRunSummary(array $stats, bool $quiet): void
    {
        if ($quiet) {
            return;
        }

        $this->newLine();
        $this->line(sprintf(
            'Résumé DRY-RUN : %d évalués, %d simulations de renouvellement, %d ignorés, %d erreur(s) technique(s), durée %d ms',
            $stats['evaluated'],
            $stats['simulated'],
            $stats['skipped'],
            $stats['errors'],
            $stats['duration_ms'] ?? 0,
        ));

        $this->renderSkipReasonsLine($stats);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function renderSkipReasonsLine(array $stats): void
    {
        if ($stats['skipped'] > 0 && $stats['skip_reasons'] !== []) {
            $parts = [];

            foreach ($stats['skip_reasons'] as $reason => $count) {
                $parts[] = sprintf('%s=%d', $reason, $count);
            }

            $this->line('Non éligibles (détail) : '.implode(', ', $parts));
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function logRunSummary(array $stats, bool $dryRun): void
    {
        Log::info('subscriptions:renew-with-credit — exécution terminée', [
            'dry_run' => $dryRun,
            'evaluated' => $stats['evaluated'],
            'renewed' => $stats['renewed'],
            'simulated' => $stats['simulated'],
            'skipped' => $stats['skipped'],
            'rejected' => $stats['rejected'],
            'errors' => $stats['errors'],
            'skip_reasons' => $stats['skip_reasons'],
            'duration_ms' => $stats['duration_ms'] ?? null,
        ]);
    }
}
