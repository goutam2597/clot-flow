<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\AccountingService;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Accounting: chart of accounts, journal entries, trial balance, general ledger.
 *
 * Every balance is derived from posted journal lines only; reversed entries are
 * excluded. Corrections are made by reversing entries, never by editing.
 */
class AccountingController extends Controller
{
    /** Account types whose natural balance is a debit. */
    public const DEBIT_TYPES = ['asset', 'expense'];

    public const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    public function __construct(protected AccountingService $accounting) {}

    public function index(Request $request): View
    {
        $asOf = $request->input('as_of') ?: null;

        $accounts = Account::where('is_active', true)->orderBy('code')->get();

        $balances = [];
        foreach (self::ACCOUNT_TYPES as $type) {
            $total = '0.00';
            foreach ($accounts->where('type', $type) as $account) {
                $total = Money::add($total, $this->accounting->accountBalance($account, $asOf), 2);
            }

            $balances[$type] = $this->naturalBalance($total, $type);
        }

        $recentEntries = JournalEntry::query()
            ->with(['lines', 'branch', 'user'])
            ->latest('entry_date')
            ->latest('id')
            ->limit(8)
            ->get();

        return view('accounting.index', [
            'accountCount' => $accounts->count(),
            'balances' => $balances,
            'entryCount' => JournalEntry::count(),
            'recentEntries' => $recentEntries,
            'asOf' => $asOf,
        ]);
    }

    public function accounts(Request $request): View
    {
        $filters = $request->only(['search', 'type']);
        $asOf = $request->input('as_of') ?: null;

        $query = Account::query()
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', '%' . $term . '%')
                        ->orWhere('code', 'like', '%' . $term . '%');
                });
            })
            ->when(in_array(($filters['type'] ?? null), self::ACCOUNT_TYPES, true) ? $filters['type'] : null,
                fn ($q, $value) => $q->where('type', $value))
            ->orderBy('code');

        $accounts = $query->paginate(25)->withQueryString();

        // Balances come from the accounting engine so the ledger stays authoritative.
        $rows = $accounts->getCollection()->map(function (Account $account) use ($asOf) {
            $balance = $this->accounting->accountBalance($account, $asOf);

            return [
                'account' => $account,
                'debit' => Money::isNegative($balance) ? '0.00' : $balance,
                'credit' => Money::isNegative($balance) ? Money::sub('0', $balance, 2) : '0.00',
            ];
        });

        $totals = [
            'debit' => Money::sum($rows->pluck('debit')->all()),
            'credit' => Money::sum($rows->pluck('credit')->all()),
        ];

        return view('accounting.accounts', [
            'accounts' => $accounts,
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $filters,
            'asOf' => $asOf,
            'types' => self::ACCOUNT_TYPES,
        ]);
    }

    public function journals(Request $request): View
    {
        $filters = $request->only(['search', 'status', 'from', 'to']);

        $query = JournalEntry::query()
            ->with(['lines.account', 'branch', 'user'])
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('entry_number', 'like', '%' . $term . '%')
                        ->orWhere('description', 'like', '%' . $term . '%');
                });
            })
            ->when(in_array(($filters['status'] ?? null), ['posted', 'reversed'], true) ? $filters['status'] : null,
                fn ($q, $value) => $q->where('status', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('entry_date', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('entry_date', '<=', $value))
            ->latest('entry_date')
            ->latest('id');

        $entries = $query->paginate(20)->withQueryString();

        return view('accounting.journals', [
            'entries' => $entries,
            'filters' => $filters,
        ]);
    }

    /**
     * Journal entry detail with its lines.
     */
    public function show(JournalEntry $journalEntry): View
    {
        $journalEntry->load(['lines.account', 'branch', 'user', 'reversedBy']);

        return view('accounting.journal-show', [
            'entry' => $journalEntry,
        ]);
    }

    /**
     * Trial balance: every account with a non-zero posted balance, grouped by
     * type, with debit/credit columns that must agree in total.
     */
    public function trialBalance(Request $request): View
    {
        $asOf = $request->input('as_of') ?: null;

        $accounts = Account::where('is_active', true)->orderBy('code')->get();

        $rows = [];
        $groupTotals = [];
        foreach ($accounts as $account) {
            $balance = $this->accounting->accountBalance($account, $asOf);
            if (Money::isZero($balance)) {
                continue;
            }

            $rows[] = [
                'account' => $account,
                'debit' => Money::isNegative($balance) ? '0.00' : $balance,
                'credit' => Money::isNegative($balance) ? Money::sub('0', $balance, 2) : '0.00',
            ];

            $groupTotals[$account->type]['debit'] = Money::add(
                $groupTotals[$account->type]['debit'] ?? '0.00',
                $rows[count($rows) - 1]['debit'],
                2
            );
            $groupTotals[$account->type]['credit'] = Money::add(
                $groupTotals[$account->type]['credit'] ?? '0.00',
                $rows[count($rows) - 1]['credit'],
                2
            );
        }

        $totals = [
            'debit' => Money::sum(array_column($rows, 'debit')),
            'credit' => Money::sum(array_column($rows, 'credit')),
        ];

        return view('accounting.trial-balance', [
            'rows' => $rows,
            'groupTotals' => $groupTotals,
            'totals' => $totals,
            'asOf' => $asOf,
            'isBalanced' => bccomp($totals['debit'], $totals['credit'], 2) === 0,
            'types' => self::ACCOUNT_TYPES,
        ]);
    }

    /**
     * General ledger for a single account: every posted line, running balance.
     */
    public function generalLedger(Request $request): View
    {
        $validated = $request->validate([
            'account' => ['required', 'integer', 'exists:accounts,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], [], ['account' => __('account')]);

        $account = Account::findOrFail($validated['account']);
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        $lines = JournalEntryLine::query()
            ->with(['journalEntry', 'account'])
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->where('status', 'posted');
                $q->when($from, fn ($qq, $v) => $qq->whereDate('entry_date', '>=', $v));
                $q->when($to, fn ($qq, $v) => $qq->whereDate('entry_date', '<=', $v));
            })
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.id')
            ->select('journal_entry_lines.*')
            ->paginate(30)
            ->withQueryString();

        // Opening balance is everything posted before the window.
        $opening = '0.00';
        $priorQuery = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'));
        if ($from) {
            $priorQuery->whereHas('journalEntry', fn ($q) => $q->whereDate('entry_date', '<', $from));
        }
        $opening = Money::sub(
            (string) $priorQuery->sum('debit'),
            (string) (clone $priorQuery)->sum('credit'),
            2
        );

        $running = $opening;
        $ledgerRows = $lines->getCollection()->map(function (JournalEntryLine $line) use (&$running) {
            $running = Money::add(Money::sub($running, (string) $line->credit, 2), (string) $line->debit, 2);

            return ['line' => $line, 'balance' => $running];
        });

        $totals = [
            'debit' => (string) $lines->sum('debit'),
            'credit' => (string) $lines->sum('credit'),
        ];

        return view('accounting.general-ledger', [
            'account' => $account,
            'ledgerRows' => $ledgerRows,
            'lines' => $lines,
            'opening' => $opening,
            'closing' => $running,
            'totals' => $totals,
            'filters' => ['account' => $account->id, 'from' => $from, 'to' => $to],
            'accounts' => Account::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    /**
     * Profit & loss statement derived from posted ledger activity.
     */
    public function profitLoss(Request $request): View
    {
        [$from, $to] = $this->period($request);

        $revenue = $this->typeTotal('revenue', $from, $to);   // credits
        $cogs = $this->typeTotal('expense', $from, $to, ['5000']); // COGS debit
        $operatingExpenses = Money::sub(
            $this->typeTotal('expense', $from, $to),
            $cogs,
            2
        );

        $grossProfit = Money::sub($revenue, $cogs, 2);
        $netProfit = Money::sub($grossProfit, $operatingExpenses, 2);

        return view('accounting.profit-loss', [
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'grossProfit' => $grossProfit,
            'expenses' => $operatingExpenses,
            'netProfit' => $netProfit,
            'margin' => Money::isZero($revenue) ? '0.00'
                : Money::div(Money::mul($netProfit, '100', 4), $revenue, 2),
            'revenueAccounts' => $this->accountBalances('revenue', $from, $to),
            'expenseAccounts' => $this->accountBalances('expense', $from, $to),
        ]);
    }

    /**
     * Balance sheet: assets, liabilities and equity at a point in time.
     */
    public function balanceSheet(Request $request): View
    {
        $asOf = $request->input('as_of') ?: today()->toDateString();

        $assets = $this->accountBalances('asset', null, $asOf);
        $liabilities = $this->accountBalances('liability', null, $asOf);
        $equity = $this->accountBalances('equity', null, $asOf);

        $assetTotal = Money::sum(array_column($assets, 'balance'));
        $liabilityTotal = Money::sum(array_column($liabilities, 'balance'));
        $equityTotal = Money::sum(array_column($equity, 'balance'));

        // Current-period earnings belong to equity but have no explicit posting
        // into a retained-earnings account until a period is closed.
        $retained = Money::sub(
            Money::add($liabilityTotal, $equityTotal, 2),
            $assetTotal,
            2
        );

        $liabilitiesAndEquity = Money::add($liabilityTotal, $equityTotal, 2);

        return view('accounting.balance-sheet', [
            'asOf' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'assetTotal' => $assetTotal,
            'liabilityTotal' => $liabilityTotal,
            'equityTotal' => $equityTotal,
            'retained' => $retained,
            'liabilitiesAndEquity' => $liabilitiesAndEquity,
            'isBalanced' => bccomp($assetTotal, $liabilitiesAndEquity, 2) === 0,
        ]);
    }

    /**
     * Reverse a posted entry. Posted entries are immutable: a correction posts
     * an offsetting entry and marks the original as reversed.
     */
    public function reverse(JournalEntry $journalEntry, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['reason' => __('reason')]);

        if ($journalEntry->status === 'reversed') {
            return back()->with('error', __('This entry is already reversed.'));
        }

        $reversal = $this->accounting->reverseEntry($journalEntry, $validated['reason']);

        AuditLogger::log(
            'accounting.reverse',
            JournalEntry::class,
            $journalEntry->id,
            ['status' => $journalEntry->status],
            [
                'status' => 'reversed',
                'reason' => $validated['reason'],
                'reversal_entry_id' => $reversal->id,
                'reversal_entry_number' => $reversal->entry_number,
            ],
            __('Journal entry :number reversed: :reason', [
                'number' => $journalEntry->entry_number,
                'reason' => $validated['reason'],
            ]),
            $journalEntry->branch_id
        );

        return redirect()
            ->route('accounting.journals.show', $journalEntry)
            ->with('success', __('Journal entry :number reversed successfully.', ['number' => $journalEntry->entry_number]));
    }

    /**
     * Present a signed total in the column that matches the account's natural
     * balance, so the report reads the way an accountant expects.
     */
    protected function naturalBalance(string $amount, string $type): string
    {
        return in_array($type, self::DEBIT_TYPES, true) ? $amount : Money::sub('0', $amount, 2);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function period(Request $request): array
    {
        $from = $request->input('from') ?: today()->startOfYear()->toDateString();
        $to = $request->input('to') ?: today()->toDateString();

        return [$this->validDate($from), $this->validDate($to)];
    }

    /**
     * Sum the natural-balance total of every account of a type in a window.
     *
     * @param  array<int, string>|null  $onlyCodes  Restrict to specific account codes.
     */
    protected function typeTotal(string $type, ?string $from, ?string $to, ?array $onlyCodes = null): string
    {
        $query = Account::where('type', $type)->where('is_active', true);
        if ($onlyCodes) {
            $query->whereIn('code', $onlyCodes);
        }

        $total = '0.00';
        foreach ($query->get() as $account) {
            $balance = $this->accounting->accountBalance($account, $to, $from);
            $total = Money::add($total, $this->naturalBalance($balance, $type), 2);
        }

        return $total;
    }

    /**
     * Per-account balances for a statement section.
     *
     * @return array<int, array{account: Account, balance: string, total: string}>
     */
    protected function accountBalances(string $type, ?string $from, ?string $to): array
    {
        $rows = [];
        foreach (Account::where('type', $type)->where('is_active', true)->orderBy('code')->get() as $account) {
            $balance = $this->naturalBalance($this->accounting->accountBalance($account, $to, $from), $type);
            if (Money::isZero($balance)) {
                continue;
            }
            $rows[] = ['account' => $account, 'balance' => $balance, 'total' => $balance];
        }

        return $rows;
    }

    protected function validDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['from' => __('The date is not valid.')]);
        }
    }
}

