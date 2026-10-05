<?php

namespace App\Services\Provisioning\Infrastructure\O2Switch\Environment;

use App\DTO\Provisioning\ProvisioningContext;

/**
 * Builder déterministe du contenu `.env` Gestion — sans fuite de secrets dans les métadonnées publiques.
 */
final class O2SwitchGestionEnvBuilder
{
    /** @var array<string, string> */
    private array $variables = [];

    /**
     * @param  array<string, string>  $variables
     */
    public static function fromVariables(array $variables): self
    {
        $builder = new self;

        foreach ($variables as $key => $value) {
            $builder->set($key, $value);
        }

        return $builder;
    }

    public function set(string $key, string $value): self
    {
        $normalizedKey = strtoupper(trim($key));

        if (! in_array($normalizedKey, O2SwitchGestionEnvSpecification::PROVISIONING_MANAGED_KEYS, true)) {
            throw new \InvalidArgumentException("Clé .env non autorisée : {$normalizedKey}.");
        }

        $this->variables[$normalizedKey] = $value;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    public function render(): string
    {
        $lines = [];
        foreach (O2SwitchGestionEnvSpecification::PROVISIONING_MANAGED_KEYS as $key) {
            if (! array_key_exists($key, $this->variables)) {
                continue;
            }

            $lines[] = $key.'='.$this->formatValue($this->variables[$key]);
        }

        return implode("\n", $lines)."\n";
    }

    public function nonSensitiveFingerprint(): string
    {
        $public = [];
        foreach ($this->variables as $key => $value) {
            if (in_array($key, O2SwitchGestionEnvSpecification::SENSITIVE_VALUE_KEYS, true)) {
                $public[$key] = '[redacted]';
            } else {
                $public[$key] = $value;
            }
        }

        ksort($public);

        return hash('sha256', json_encode($public, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    public function appliedKeyNames(): array
    {
        return array_keys($this->variables);
    }

    /**
     * @return array<string, string>
     */
    public function publicPreview(): array
    {
        $preview = [];
        foreach ($this->variables as $key => $value) {
            if (in_array($key, O2SwitchGestionEnvSpecification::SENSITIVE_VALUE_KEYS, true)) {
                $preview[$key] = '[redacted]';
            } else {
                $preview[$key] = $value;
            }
        }

        return $preview;
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s#="\']/', $value) === 1) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }
}
