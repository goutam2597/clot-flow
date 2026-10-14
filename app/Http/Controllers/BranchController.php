<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Warehouse;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Branch administration: branches and their warehouses.
 *
 * The default branch is protected — it anchors users, settings and the POS
 * fallback, so it can neither be deactivated nor deleted. Branches with
 * dependent records are deactivated instead of deleted to keep history
 * auditable.
 */
class BranchController extends Controller
{
    public function index(): View
    {
        $branches = Branch::query()
            ->with(['warehouses', 'users'])
            ->withCount(['warehouses', 'users'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('branches.index', [
            'branches' => $branches,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:32', 'unique:branches,code'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $branch = Branch::create([
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address_line' => $validated['address_line'] ?? null,
            'city' => $validated['city'] ?? null,
            'country' => $validated['country'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        Warehouse::create([
            'branch_id' => $branch->id,
            'name' => __('Main Warehouse'),
            'code' => strtoupper($branch->code) . '-MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);

        AuditLogger::log('branch.created', Branch::class, $branch->id, null,
            ['name' => $branch->name, 'code' => $branch->code],
            __('Branch :name created', ['name' => $branch->name]), $branch->id);

        return redirect()->route('branches.index')
            ->with('success', __('Branch :name created.', ['name' => $branch->name]));
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:32',
                Rule::unique('branches', 'code')->ignore($branch->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($branch->is_default && ! $request->boolean('is_active', true)) {
            return back()->with('error', __('The default branch cannot be deactivated.'));
        }

        $old = ['name' => $branch->name, 'is_active' => $branch->is_active];

        $branch->update([
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address_line' => $validated['address_line'] ?? null,
            'city' => $validated['city'] ?? null,
            'country' => $validated['country'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLogger::log('branch.updated', Branch::class, $branch->id, $old,
            ['name' => $branch->name, 'is_active' => $branch->is_active],
            __('Branch :name updated', ['name' => $branch->name]), $branch->id);

        return redirect()->route('branches.index')
            ->with('success', __('Branch :name updated.', ['name' => $branch->name]));
    }
}
