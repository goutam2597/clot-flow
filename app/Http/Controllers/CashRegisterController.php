<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Warehouse;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cash registers (tills) and their live status.
 *
 * Session lifecycle (open / close) lives in CashRegisterSessionController —
 * this controller manages the registers themselves so the terminal knows which
 * drawers exist and whether they are currently occupied.
 */
class CashRegisterController extends Controller
{
    /**
     * List the branch's registers with their open session and last session.
     */
    public function index(Request $request): View
    {
        $this->authorizePos();

        $branchId = $this->resolveBranchId($request->user());

        $registers = CashRegister::query()
            ->with(['warehouse'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get()
            ->map(function (CashRegister $register) {
                $openSession = CashRegisterSession::query()
                    ->where('cash_register_id', $register->id)
                    ->where('status', 'open')
                    ->with('user')
                    ->latest('opened_at')
                    ->first();

                return [
                    'register' => $register,
                    'openSession' => $openSession,
                    'lastSession' => CashRegisterSession::query()
                        ->where('cash_register_id', $register->id)
                        ->latest('opened_at')
                        ->first(),
                ];
            });

        return view('pos.registers.index', [
            'title' => __('Cash Registers'),
            'registers' => $registers,
            'warehouses' => $this->branchWarehouses($branchId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePos();

        $branchId = $this->resolveBranchId($request->user());
        $warehouses = $this->branchWarehouses($branchId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32', 'unique:cash_registers,code'],
            'warehouse_id' => ['nullable', 'integer', 'in:' . ($warehouses->pluck('id')->implode(',') ?: '0')],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'warehouse_id' => __('warehouse'),
        ]);

        if (! $branchId) {
            return back()->withInput()->with('error', __('No branch is assigned to your user. Contact an administrator.'));
        }

        $register = CashRegister::create([
            'branch_id' => $branchId,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLogger::log(
            'cash_register.created',
            CashRegister::class,
            $register->id,
            null,
            ['name' => $register->name, 'code' => $register->code],
            __('Cash register :name created', ['name' => $register->name]),
            $branchId
        );

        return redirect()
            ->route('pos.registers.index')
            ->with('success', __('Register :name created.', ['name' => $register->name]));
    }

    public function update(Request $request, CashRegister $cashRegister): RedirectResponse
    {
        $this->authorizePos();

        $branchId = $this->resolveBranchId($request->user());
        $warehouses = $this->branchWarehouses($branchId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32', 'unique:cash_registers,code,' . $cashRegister->id],
            'warehouse_id' => ['nullable', 'integer', 'in:' . ($warehouses->pluck('id')->implode(',') ?: '0')],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'warehouse_id' => __('warehouse'),
        ]);

        $old = ['name' => $cashRegister->name, 'code' => $cashRegister->code, 'is_active' => $cashRegister->is_active];

        $cashRegister->update([
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLogger::log(
            'cash_register.updated',
            CashRegister::class,
            $cashRegister->id,
            $old,
            ['name' => $cashRegister->name, 'code' => $cashRegister->code, 'is_active' => $cashRegister->is_active],
            __('Cash register :name updated', ['name' => $cashRegister->name]),
            $branchId
        );

        return redirect()
            ->route('pos.registers.index')
            ->with('success', __('Register :name updated.', ['name' => $cashRegister->name]));
    }

    /**
     * Registers with history are never deleted — they are deactivated instead so
     * that past sessions stay auditable.
     */
    public function destroy(CashRegister $cashRegister): RedirectResponse
    {
        $this->authorizePos();

        $hasSessions = CashRegisterSession::where('cash_register_id', $cashRegister->id)->exists();

        if ($hasSessions) {
            $cashRegister->update(['is_active' => false]);

            AuditLogger::log(
                'cash_register.deactivated',
                CashRegister::class,
                $cashRegister->id,
                ['is_active' => true],
                ['is_active' => false],
                __('Cash register :name deactivated because it has session history', ['name' => $cashRegister->name]),
                $cashRegister->branch_id
            );

            return redirect()
                ->route('pos.registers.index')
                ->with('warning', __('Register :name has session history, so it was deactivated instead of deleted.', [
                    'name' => $cashRegister->name,
                ]));
        }

        $name = $cashRegister->name;
        $cashRegister->delete();

        AuditLogger::log(
            'cash_register.deleted',
            CashRegister::class,
            $cashRegister->id,
            ['name' => $name],
            null,
            __('Cash register :name deleted', ['name' => $name]),
            $cashRegister->branch_id
        );

        return redirect()
            ->route('pos.registers.index')
            ->with('success', __('Register :name deleted.', ['name' => $name]));
    }

    /* ------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    protected function branchWarehouses(?int $branchId)
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    protected function resolveBranchId($user): ?int
    {
        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        return Branch::where('is_active', true)->where('is_default', true)->value('id')
            ?? Branch::where('is_active', true)->orderBy('id')->value('id');
    }

    /**
     * Registers are POS hardware — gate them with the POS permission used by
     * routes/pos.php.
     */
    protected function authorizePos(): void
    {
        $user = auth()->user();

        abort_unless($user && $user->can('pos.access'), 403);
    }
}
