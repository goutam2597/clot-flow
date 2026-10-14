<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\AuditLogger;
use App\Support\Formatter;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cash register sessions (drawer open / close).
 *
 * A session is opened with a counted opening float and closed with a counted
 * closing balance. Expected cash is derived from the session's completed sales
 * and the cash payments recorded against them, so the difference the cashier
 * must explain is always computed from real transactions.
 */
class CashRegisterSessionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePos();

        $branchId = $this->resolveBranchId($request->user());
        $status = $request->query('status');
        $registerId = (int) $request->query('cash_register_id', 0);

        $sessions = CashRegisterSession::query()
            ->with(['cashRegister', 'user'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(in_array($status, ['open', 'closed'], true), fn ($q) => $q->where('status', $status))
            ->when($registerId, fn ($q) => $q->where('cash_register_id', $registerId))
            ->orderByDesc('opened_at')
            ->paginate(20)
            ->withQueryString();

        return view('pos.sessions.index', [
            'title' => __('Register Sessions'),
            'sessions' => $sessions,
            'registers' => $this->branchRegisters($branchId),
            'status' => $status,
            'registerId' => $registerId,
        ]);
    }

    /**
     * Form used to open a new session with a counted opening float.
     */
    public function create(Request $request): View
    {
        $this->authorizePos();

        $branchId = $this->resolveBranchId($request->user());
        $registers = $this->branchRegisters($branchId)
            ->reject(fn (CashRegister $register) => $this->openSessionFor($register) !== null);

        return view('pos.sessions.create', [
            'title' => __('Open Register Session'),
            'registers' => $registers,
            'currentSession' => $this->currentSession($request->user()),
        ]);
    }

    /**
     * Open a session. Only one open session per register is allowed.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePos();

        $user = $request->user();
        $branchId = $this->resolveBranchId($user);
        $registers = $this->branchRegisters($branchId);

        $validated = $request->validate([
            'cash_register_id' => ['required', 'integer', 'in:' . ($registers->pluck('id')->implode(',') ?: '0')],
            'opening_balance' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'cash_register_id' => __('register'),
            'opening_balance' => __('opening balance'),
        ]);

        $register = $registers->firstWhere('id', (int) $validated['cash_register_id']);

        if (! $register) {
            return back()->withInput()->with('error', __('The selected register is not available.'));
        }

        if ($this->openSessionFor($register)) {
            return back()->withInput()->with('error', __('Register :name already has an open session.', [
                'name' => $register->name,
            ]));
        }

        $session = CashRegisterSession::create([
            'cash_register_id' => $register->id,
            'branch_id' => $register->branch_id,
            'user_id' => $user->id,
            'opening_balance' => $this->decimal($validated['opening_balance'], 2),
            'closing_balance' => null,
            'expected_cash' => null,
            'cash_difference' => null,
            'total_sales' => '0.00',
            'sale_count' => 0,
            'status' => 'open',
            'opened_at' => now(),
            'closed_at' => null,
            'notes' => $validated['notes'] ?? null,
        ]);

        AuditLogger::log(
            'cash_register_session.opened',
            CashRegisterSession::class,
            $session->id,
            null,
            [
                'cash_register_id' => $register->id,
                'opening_balance' => (string) $session->opening_balance,
            ],
            __('Register session opened for :register with float :amount', [
                'register' => $register->name,
                'amount' => Formatter::money($session->opening_balance),
            ]),
            $register->branch_id
        );

        return redirect()
            ->route('pos.sessions.current')
            ->with('success', __('Session opened for :name.', ['name' => $register->name]));
    }

    /**
     * The session currently open for the signed-in user.
     */
    public function current(Request $request): View|RedirectResponse
    {
        $this->authorizePos();

        $session = $this->currentSession($request->user());

        if (! $session) {
            return redirect()
                ->route('pos.sessions.create')
                ->with('info', __('No register session is open. Open one to start selling.'));
        }

        return view('pos.sessions.current', [
            'title' => __('Current Session'),
            'session' => $session,
            'stats' => $this->sessionStats($session),
            'sales' => $this->sessionSalesQuery($session)->limit(15)->get(),
        ]);
    }

    /**
     * Session detail: transactions, expected cash and the closing summary.
     */
    public function show(CashRegisterSession $session): View
    {
        $this->authorizePos();

        $session->load(['cashRegister', 'user', 'branch']);

        return view('pos.sessions.show', [
            'title' => __('Session #:id', ['id' => $session->id]),
            'session' => $session,
            'stats' => $this->sessionStats($session),
            'sales' => $this->sessionSalesQuery($session)->paginate(20, ['*'], 'sales_page'),
        ]);
    }

    /**
     * Close a session with a counted closing balance.
     *
     * expected_cash   = opening_balance + cash received - cash change given
     * cash_difference = closing_balance - expected_cash
     */
    public function close(Request $request, CashRegisterSession $session): RedirectResponse
    {
        $this->authorizePos();

        abort_if($session->status !== 'open', 409);

        $validated = $request->validate([
            'closing_balance' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'closing_balance' => __('closing balance'),
        ]);

        $stats = $this->sessionStats($session);
        $closingBalance = $this->decimal($validated['closing_balance'], 2);
        $expectedCash = $stats['expected_cash'];
        $difference = Money::sub($closingBalance, $expectedCash, 2);

        DB::transaction(function () use ($session, $closingBalance, $expectedCash, $difference, $validated, $stats) {
            $session->update([
                'closing_balance' => $closingBalance,
                'expected_cash' => $expectedCash,
                'cash_difference' => $difference,
                'total_sales' => $stats['sales_total'],
                'sale_count' => $stats['sale_count'],
                'status' => 'closed',
                'closed_at' => now(),
                'notes' => $validated['notes'] ?? $session->notes,
            ]);
        });

        AuditLogger::log(
            'cash_register_session.closed',
            CashRegisterSession::class,
            $session->id,
            ['status' => 'open'],
            [
                'status' => 'closed',
                'closing_balance' => $closingBalance,
                'expected_cash' => $expectedCash,
                'cash_difference' => $difference,
            ],
            __('Register session closed with a cash difference of :difference', [
                'difference' => Formatter::money($difference),
            ]),
            $session->branch_id
        );

        $message = Money::isZero($difference)
            ? __('Session closed. Cash balances exactly.')
            : __('Session closed. Cash difference: :difference.', ['difference' => Formatter::money($difference)]);

        return redirect()
            ->route('pos.sessions.show', $session)
            ->with(Money::isZero($difference) ? 'success' : 'warning', $message);
    }

    /* ------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    /**
     * Sales belonging to a session (completed only), newest first.
     */
    protected function sessionSalesQuery(CashRegisterSession $session)
    {
        return Sale::query()
            ->where('cash_register_session_id', $session->id)
            ->where('status', 'completed')
            ->with(['customer', 'user'])
            ->orderByDesc('sold_at');
    }

    /**
     * Live figures for a session, all derived from real transactions:
     * sales totals, cash received, cash change handed back, expected drawer
     * cash and (once closed) the counted difference.
     */
    protected function sessionStats(CashRegisterSession $session): array
    {
        $saleIds = Sale::query()
            ->where('cash_register_session_id', $session->id)
            ->where('status', 'completed')
            ->pluck('id')
            ->all();

        $cashMethodIds = PaymentMethod::where('type', 'cash')->pluck('id')->all();

        $cashReceived = '0.00';
        $cashChange = '0.00';

        if ($saleIds && $cashMethodIds) {
            $cashPayments = SalePayment::query()
                ->whereIn('sale_id', $saleIds)
                ->whereIn('payment_method_id', $cashMethodIds)
                ->get(['amount', 'change_amount']);

            foreach ($cashPayments as $payment) {
                $cashReceived = Money::add($cashReceived, (string) $payment->amount, 2);
                $cashChange = Money::add($cashChange, (string) $payment->change_amount, 2);
            }
        }

        $salesTotal = '0.00';
        foreach (Sale::query()->whereIn('id', $saleIds ?: [0])->pluck('grand_total') as $total) {
            $salesTotal = Money::add($salesTotal, (string) $total, 2);
        }

        $opening = (string) $session->opening_balance;
        $expectedCash = Money::sub(Money::add($opening, $cashReceived, 2), $cashChange, 2);

        $difference = $session->cash_difference !== null
            ? (string) $session->cash_difference
            : Money::sub((string) ($session->closing_balance ?? '0.00'), $expectedCash, 2);

        return [
            'sale_count' => count($saleIds),
            'sales_total' => $salesTotal,
            'cash_received' => $cashReceived,
            'cash_change' => $cashChange,
            'opening_balance' => $this->decimal($opening, 2),
            'expected_cash' => $expectedCash,
            'cash_difference' => $difference,
        ];
    }

    protected function currentSession($user): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('status', 'open')
            ->when($user->branch_id, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->with('cashRegister')
            ->latest('opened_at')
            ->first();
    }

    protected function openSessionFor(CashRegister $register): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('cash_register_id', $register->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    protected function branchRegisters(?int $branchId)
    {
        return CashRegister::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
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
     * Register sessions are POS operations — gate them with the POS permission
     * used by routes/pos.php.
     */
    protected function authorizePos(): void
    {
        $user = auth()->user();

        abort_unless($user && $user->can('pos.access'), 403);
    }

    protected function decimal($value, int $scale = 2): string
    {
        $value = is_null($value) || $value === '' ? '0' : (string) $value;

        return number_format((float) $value, $scale, '.', '');
    }
}
