<?php

namespace App\Models\Concerns;

use App\Models\Concerns\BranchScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies {@see BranchScope} to a model that owns a `branch_id`.
 *
 * Every query through the model is therefore confined to the caller's branch.
 * Escape hatches, used deliberately and only where cross-branch work is the
 * point:
 *
 *   Model::allBranches()                 — head-office reports
 *   Model::where(...)->withoutBranchScope()  — explicit admin override
 */
trait ScopedToBranch
{
    public static function bootScopedToBranch(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    /**
     * Every branch, ignoring the caller's scope. Use for head-office reporting.
     */
    public function scopeAllBranches(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BranchScope::class);
    }

    /**
     * Drop the branch filter for a single query.
     */
    public function scopeWithoutBranchScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BranchScope::class);
    }
}
