<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\AuditLogAdminPresentation;
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
            'auditable_id' => 'nullable|integer|min:1',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'search' => 'nullable|string|max:255',
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
            $resolvedType = AuditLogAdminPresentation::resolveAuditableTypeFilter($validated['auditable_type']);
            $query->where('auditable_type', $resolvedType);
        }

        if (filled($validated['auditable_id'] ?? null)) {
            $query->where('auditable_id', $validated['auditable_id']);
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

        if (filled($validated['search'] ?? null)) {
            $term = '%'.$validated['search'].'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('action', 'like', $term)
                    ->orWhere('error_message', 'like', $term);
            });
        }

        $paginator = $query->paginate(25)->withQueryString();
        $subjectContext = AuditLogAdminPresentation::buildSubjectContext($paginator->getCollection());

        $paginator->through(
            fn (AuditLog $log): array => AuditLogAdminPresentation::serializeEntry($log, $subjectContext),
        );

        return Inertia::render('AuditLogs/Index', [
            'auditLogs' => $paginator,
            'filters' => [
                'action' => $validated['action'] ?? null,
                'result' => $validated['result'] ?? null,
                'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
                'auditable_type' => $validated['auditable_type'] ?? null,
                'auditable_id' => isset($validated['auditable_id']) ? (int) $validated['auditable_id'] : null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
        ]);
    }
}
