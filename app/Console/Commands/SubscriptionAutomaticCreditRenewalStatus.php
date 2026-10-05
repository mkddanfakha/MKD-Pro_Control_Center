<?php

namespace App\Console\Commands;

use App\Services\SubscriptionAutomaticCreditRenewalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schedule;

/**
 * Diagnostic lecture seule — renouvellement automatique par crédit (Task 279).
 */
#[Signature('subscriptions:automatic-renewal-status')]
#[Description('Affiche l’état de configuration du renouvellement automatique par crédit (lecture seule)')]
class SubscriptionAutomaticCreditRenewalStatus extends Command
{
    private const RENEW_COMMAND = 'subscriptions:renew-with-credit';

    public function __construct(
        private readonly SubscriptionAutomaticCreditRenewalService $automaticCreditRenewalService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $enabled = $this->automaticCreditRenewalService->isEnabled();
        $environment = (string) config('app.env');
        $isProduction = $environment === 'production';

        $this->line('Renouvellement automatique des abonnements');
        $this->line('-------------------------------------------');
        $this->line('Environment : '.$environment);
        $this->line('Debug       : '.(config('app.debug') ? 'true' : 'false'));
        $this->line('Timezone    : '.(string) config('app.timezone'));
        $this->line('Automatic   : '.($enabled ? 'ENABLED' : 'DISABLED'));
        $this->line('Dry-run     : AVAILABLE (--dry-run on '.self::RENEW_COMMAND.')');
        $this->line('Scheduler   : '.$this->resolveRenewSchedulerFrequency());
        $this->line('Renew cmd   : '.self::RENEW_COMMAND);

        if ($isProduction && $enabled) {
            $this->newLine();
            $this->warn('ATTENTION : production avec renouvellement automatique ACTIVÉ.');
            $this->warn('Les exécutions de '.self::RENEW_COMMAND.' (hors dry-run) consommeront du crédit réel.');
        }

        if ($isProduction && ! $enabled) {
            $this->newLine();
            $this->line('Production : fonctionnalité désactivée (comportement attendu avant activation explicite).');
        }

        return self::SUCCESS;
    }

    private function resolveRenewSchedulerFrequency(): string
    {
        foreach (Schedule::events() as $event) {
            $command = (string) ($event->command ?? '');

            if (! str_contains($command, self::RENEW_COMMAND)) {
                continue;
            }

            $expression = (string) ($event->expression ?? '');

            return $expression === '0 0 * * *' ? 'DAILY (0 0 * * *)' : 'SCHEDULED ('.$expression.')';
        }

        return 'NOT SCHEDULED';
    }
}
