<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Audit trail: every audited state change across the business.
 *
 * Read-only by design — logs are written by AuditLogger from services and
 * controllers, never edited here. Branch operators see their own branch;
 * head-office staff see everything.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $filters = $request->only(['search', 'action', 'from', 'to', 'branch_id']);

        $user = $request->user();
        $branchScope = $user->branch_id && ! $user->can('branches.manage')
            ? (int) $user->branch_id
            : null;

        $actions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $logs = AuditLog::query()
            ->with(['user', 'branch'])
            ->when($branchScope, fn ($q) => $q->where('branch_id', $branchScope))
            ->when(! $branchScope && ($filters['branch_id'] ?? null),
                fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('description', 'like', '%' . $term . '%')
                        ->orWhere('entity_type', 'like', '%' . $term . '%')
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%' . $term . '%'));
                });
            })
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => $actions,
            'branches' => $branchScope ? collect() : Branch::orderBy('name')->get(),
            'branchScope' => $branchScope,
        ]);
    }
}
