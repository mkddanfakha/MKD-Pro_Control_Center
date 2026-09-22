<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'nullable|string|max:255',
            'result' => 'nullable|in:success,failure',
            'user_id' => 'nullable|integer|min:1',
            'auditable_type' => 'nullable|string|max:255',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
        ]);

        $query = AuditLog::query()
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (filled($validated['action'] ?? null)) {
            $query->where('action', $validated['action']);
        }

        if (filled($validated['result'] ?? null)) {
            $query->where('result', $validated['result']);
        }

        if (filled($validated['user_id'] ?? null)) {
            $query->where('user_id', $validated['user_id']);
        }

        if (filled($validated['auditable_type'] ?? null)) {
            $query->where('auditable_type', $validated['auditable_type']);
        }

        if (filled($validated['date_from'] ?? null)) {
            $query->where(
                'created_at',
                '>=',
                Carbon::createFromFormat('Y-m-d', $validated['date_from'])->startOfDay(),
            );
        }

        if (filled($validated['date_to'] ?? null)) {
            $query->where(
                'created_at',
                '<=',
                Carbon::createFromFormat('Y-m-d', $validated['date_to'])->endOfDay(),
            );
        }

        return Inertia::render('AuditLogs/Index', [
            'auditLogs' => $query->paginate(25)->withQueryString(),
            'filters' => [
                'action' => $validated['action'] ?? null,
                'result' => $validated['result'] ?? null,
                'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
                'auditable_type' => $validated['auditable_type'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ],
        ]);
    }
}
