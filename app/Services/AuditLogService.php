<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogService
{
    public function record(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        string $result = 'success',
        ?string $errorMessage = null,
    ): AuditLog {
        $request = app()->bound('request') ? request() : null;

        $ipAddress = $this->resolveIpAddress($request);
        $userAgent = $this->resolveUserAgent($request);
        $userId = $request !== null ? Auth::id() : null;

        return AuditLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'result' => $result,
            'error_message' => $errorMessage,
        ]);
    }

    private function resolveIpAddress(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        if (! $request->server->has('REMOTE_ADDR') && ! $request->headers->has('X-Forwarded-For')) {
            return null;
        }

        return $request->ip();
    }

    private function resolveUserAgent(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $userAgent = $request->userAgent();

        return $userAgent !== '' ? $userAgent : null;
    }
}
