<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Présence configuration préflight — valeurs secrètes jamais affichées (TASK 377).
 */
final class ProvisioningPreflightConfigStatusCommand extends Command
{
    protected $signature = 'provisioning:preflight-config-status';

    protected $description = 'Affiche CONFIGURED/MISSING pour la configuration préflight (sans secrets)';

    public function handle(): int
    {
        $this->emitLine('PROVISIONING_CLOUDFLARE_API_TOKEN', config('provisioning.secrets.cloudflare_api_token'));
        $this->emitLine('PROVISIONING_O2SWITCH_API_TOKEN', config('provisioning.secrets.o2switch_api_token'));
        $this->emitLine('PROVISIONING_O2SWITCH_CPANEL_USERNAME', config('provisioning.secrets.o2switch_cpanel_username'));
        $this->emitLine('PROVISIONING_O2SWITCH_CPANEL_HOST', config('provisioning.o2switch.database.cpanel_host'));
        $this->emitLine('PROVISIONING_CLOUDFLARE_ZONE_ID', config('provisioning.cloudflare.dns.zone_id'));
        $this->emitLine('PROVISIONING_CLOUDFLARE_ZONE_NAME', config('provisioning.cloudflare.dns.zone_name'));
        $this->emitLine('PROVISIONING_PREFLIGHT_GIT_PROBE_ROOT', config('provisioning.preflight.git_probe_root'));
        $this->emitLine('PROVISIONING_PREFLIGHT_FILEMAN_PROBE_DIR', config('provisioning.preflight.fileman_probe_dir'));
        $this->emitLine('PROVISIONING_PREFLIGHT_FILEMAN_PROBE_FILE', config('provisioning.preflight.fileman_probe_file'));

        $this->newLine();
        $this->line('Provisioning feature flags (must stay disabled for preflight-only validation):');

        $this->emitFlag('PROVISIONING_CLOUDFLARE_DNS_ENABLED', config('provisioning.cloudflare.dns.enabled'));
        $this->emitFlag('PROVISIONING_O2SWITCH_DATABASE_ENABLED', config('provisioning.o2switch.database.enabled'));
        $this->emitFlag('PROVISIONING_O2SWITCH_DEPLOY_ENABLED', config('provisioning.o2switch.deploy.enabled'));
        $this->emitFlag('PROVISIONING_O2SWITCH_ENVIRONMENT_ENABLED', config('provisioning.o2switch.environment.enabled'));

        $this->newLine();
        $this->line('APP_ENV='.$this->nonSecretScalar(config('app.env')));
        $this->line('APP_URL='.$this->nonSecretScalar(config('app.url')));

        return self::SUCCESS;
    }

    private function emitLine(string $label, mixed $value): void
    {
        $this->line($label.'='.$this->configured($value));
    }

    private function emitFlag(string $label, mixed $enabled): void
    {
        $this->line($label.'='.(filter_var($enabled, FILTER_VALIDATE_BOOLEAN) ? 'ENABLED' : 'DISABLED'));
    }

    private function configured(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return 'CONFIGURED';
        }

        if (is_numeric($value)) {
            return 'CONFIGURED';
        }

        return 'MISSING';
    }

    private function nonSecretScalar(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'MISSING';
        }

        return (string) $value;
    }
}
