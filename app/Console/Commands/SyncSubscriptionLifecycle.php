<?php

namespace App\Console\Commands;

use App\Exceptions\Subscription\SubscriptionLifecycleException;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Signature('subscriptions:sync-lifecycle')]
#[Description('Synchronise le cycle de vie des abonnements actifs et en période de grâce')]
class SyncSubscriptionLifecycle extends Command
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $quiet = $this->output->isQuiet();

        $stats = [
            'total' => 0,
            'unchanged' => 0,
            'active_to_grace' => 0,
            'grace_to_suspended' => 0,
            'errors' => 0,
        ];

        if (! $quiet) {
            $this->line('Synchronisation du cycle de vie des abonnements');
            $this->newLine();
            $this->line('Traitement...');
        }

        Subscription::query()
            ->whereIn('status', [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_GRACE_PERIOD,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($quiet, &$stats): void {
                foreach ($subscriptions as $subscription) {
                    $this->processSubscription($subscription, $quiet, $stats);
                }
            });

        if (! $quiet) {
            $this->newLine();
        }

        $this->displaySummary($stats);

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{total: int, unchanged: int, active_to_grace: int, grace_to_suspended: int, errors: int}  $stats
     */
    private function processSubscription(Subscription $subscription, bool $quiet, array &$stats): void
    {
        $stats['total']++;
        $subscriptionId = $subscription->id;
        $statusBefore = $subscription->status;

        try {
            $updated = $this->subscriptionService->syncLifecycle($subscription);
            $statusAfter = $updated->status;

            if ($statusBefore === $statusAfter) {
                $stats['unchanged']++;

                if (! $quiet) {
                    $this->line("✓ Abonnement #{$subscriptionId} : aucun changement");
                }

                return;
            }

            if ($statusBefore === Subscription::STATUS_ACTIVE && $statusAfter === Subscription::STATUS_GRACE_PERIOD) {
                $stats['active_to_grace']++;
            } elseif ($statusBefore === Subscription::STATUS_GRACE_PERIOD && $statusAfter === Subscription::STATUS_SUSPENDED) {
                $stats['grace_to_suspended']++;
            }

            if (! $quiet) {
                $this->line("✓ Abonnement #{$subscriptionId} : {$statusBefore} → {$statusAfter}");
            }
        } catch (SubscriptionLifecycleException $exception) {
            $stats['errors']++;

            if (! $quiet) {
                $this->warn("⚠ Abonnement #{$subscriptionId} : erreur — {$exception->getMessage()}");
            }
        } catch (Throwable $exception) {
            $stats['errors']++;

            if (! $quiet) {
                $this->error("✗ Abonnement #{$subscriptionId} : erreur inattendue — {$exception->getMessage()}");
            }
        }
    }

    /**
     * @param  array{total: int, unchanged: int, active_to_grace: int, grace_to_suspended: int, errors: int}  $stats
     */
    private function displaySummary(array $stats): void
    {
        $previousVerbosity = $this->output->getVerbosity();

        if ($previousVerbosity === OutputInterface::VERBOSITY_QUIET) {
            $this->output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        }

        $this->line('Résumé');
        $this->line("Total traité : {$stats['total']}");
        $this->line("Inchangés : {$stats['unchanged']}");
        $this->line("Active → Grace : {$stats['active_to_grace']}");
        $this->line("Grace → Suspended : {$stats['grace_to_suspended']}");
        $this->line("Erreurs : {$stats['errors']}");

        $this->output->setVerbosity($previousVerbosity);
    }
}
