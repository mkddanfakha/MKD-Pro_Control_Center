<?php

namespace App\Services\Provisioning\Infrastructure\Preflight;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client HTTP cPanel UAPI pour préflight read-only (TASK 375).
 */
final class O2SwitchCpanelPreflightHttp
{
    /**
     * @return array{0: PendingRequest, 1: string, 2: string}|null host, cpanelUser
     */
    public static function authorizedClient(string $cpanelHost): ?array
    {
        $token = config('provisioning.secrets.o2switch_api_token');
        $cpanelUser = config('provisioning.secrets.o2switch_cpanel_username');

        if (! is_string($token) || $token === '' || ! is_string($cpanelUser) || $cpanelUser === '') {
            return null;
        }

        if (trim($cpanelHost) === '') {
            return null;
        }

        $timeout = (int) config('provisioning.preflight.http_timeout_seconds', 15);
        $timeout = $timeout > 0 ? $timeout : 15;

        $client = Http::timeout($timeout)->withHeaders([
            'Authorization' => 'cpanel '.$cpanelUser.':'.$token,
        ]);

        return [$client, trim($cpanelHost), $cpanelUser];
    }

    public static function uapiBaseUrl(string $cpanelHost, string $modulePath): string
    {
        return 'https://'.$cpanelHost.':2083'.$modulePath;
    }
}
