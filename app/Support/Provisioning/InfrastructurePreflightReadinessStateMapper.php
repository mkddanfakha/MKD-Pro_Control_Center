<?php

namespace App\Support\Provisioning;

use App\DTO\Provisioning\InfrastructurePreflightState;
use App\DTO\Provisioning\InstallationReadinessProofStatus;
use InvalidArgumentException;

/**
 * Traduction états préflight → statuts de preuve readiness (TASK 382).
 *
 * Seul {@see InfrastructurePreflightState::READY} produit {@see InstallationReadinessProofStatus::VERIFIED}.
 */
final class InfrastructurePreflightReadinessStateMapper
{
    public static function toProofStatus(string $preflightState): string
    {
        return match ($preflightState) {
            InfrastructurePreflightState::READY => InstallationReadinessProofStatus::VERIFIED,
            InfrastructurePreflightState::NOT_CONFIGURED => InstallationReadinessProofStatus::NOT_CONFIGURED,
            InfrastructurePreflightState::AUTHENTICATION_FAILED => InstallationReadinessProofStatus::FAILED,
            InfrastructurePreflightState::FORBIDDEN => InstallationReadinessProofStatus::MANUAL_INTERVENTION_REQUIRED,
            InfrastructurePreflightState::UNREACHABLE => InstallationReadinessProofStatus::FAILED,
            InfrastructurePreflightState::PROTOCOL_PENDING => InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
            InfrastructurePreflightState::UNSUPPORTED => InstallationReadinessProofStatus::NOT_YET_AUTOMATED,
            InfrastructurePreflightState::FAILED => InstallationReadinessProofStatus::FAILED,
            default => throw new InvalidArgumentException('État préflight non mappable : '.$preflightState),
        };
    }
}
