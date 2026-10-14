<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts a model to the signed-in user's branch.
 *
 * A cashier at one branch must not read, count or report on another branch's
 * records. Enforcing that in a query scope rather than in each controller means
 * the rule holds for indexes, reports, exports, JSON endpoints and queued jobs
 * alike — anything that goes through Eloquent.
 *
 * Head-office staff who can manage branches (`branches.manage`) intentionally
 * see every branch; everyone else is pinned to their own.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, \Illuminate\Database\Eloquent\Model $model): void
    {
        $branchId = static::branchId();

        if ($branchId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('branch_id'), $branchId);
    }

    /**
     * The branch the current request is confined to, or null for "see all".
     *
     * Returns null when there is no authenticated user (console commands,
     * queued jobs, seeders) — those run deliberately and must not be narrowed
     * by whoever happens to be logged into the web UI.
     */
    public static function branchId(): ?int
    {
        if (! app()->bound('auth')) {
            return null;
        }

        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if ($user->branch_id === null) {
            return null;
        }

        if (method_exists($user, 'can') && $user->can('branches.manage')) {
            return null;
        }

        return (int) $user->branch_id;
    }

    /**
     * True when the given model belongs to the caller's branch.
     */
    public static function allows(\Illuminate\Database\Eloquent\Model $model): bool
    {
        $branchId = static::branchId();

        if ($branchId === null) {
            return true;
        }

        return (int) $model->branch_id === $branchId;
    }
}
