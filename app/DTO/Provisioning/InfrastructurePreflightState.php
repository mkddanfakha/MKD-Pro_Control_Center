<?php

namespace App\DTO\Provisioning;

/**
 * États stables du préflight infrastructure (TASK 375) — non destructif.
 */
final class InfrastructurePreflightState
{
    public const READY = 'ready';

    public const NOT_CONFIGURED = 'not_configured';

    public const AUTHENTICATION_FAILED = 'authentication_failed';

    public const FORBIDDEN = 'forbidden';

    public const UNREACHABLE = 'unreachable';

    public const PROTOCOL_PENDING = 'protocol_pending';

    public const UNSUPPORTED = 'unsupported';

    public const FAILED = 'failed';

    public const GLOBAL_READY = 'ready';

    public const GLOBAL_NOT_READY = 'not_ready';

    /**
     * @return list<string>
     */
    public static function terminalStates(): array
    {
        return [
            self::READY,
            self::NOT_CONFIGURED,
            self::AUTHENTICATION_FAILED,
            self::FORBIDDEN,
            self::UNREACHABLE,
            self::PROTOCOL_PENDING,
            self::UNSUPPORTED,
            self::FAILED,
        ];
    }

    public static function isReady(string $state): bool
    {
        return $state === self::READY;
    }
}
