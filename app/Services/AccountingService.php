<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Expense;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Double-entry accounting engine.
 *
 * Every financial event posts a balanced journal entry. Entries are never
 * edited or deleted; corrections are made via reversing entries. All posting
 * happens inside the caller's database transaction and respects idempotency
 * keys to avoid duplicate ledger activity during retries/offline sync.
 */
class AccountingService
{
    /**
     * Post a balanced journal entry.
     *
     * @param  array  $lines  [['account_id' => int, 'debit' => '0.00', 'credit' => '0.00', 'description' => ?string]]
     * @param  string|null  $idempotencyKey
     */
    public function postEntry(
        string $date,
        array $lines,
        string $description = '',
        ?string $referenceType = null,
        $referenceId = null,
        ?int $branchId = null,
        ?int $userId = null,
        ?string $idempotencyKey = null
    ): JournalEntry {
        if (empty($lines)) {
            throw new \RuntimeException(__('Cannot post an empty journal entry.'));
        }

        // Idempotency: if this exact key already posted, return existing entry.
        if ($idempotencyKey) {
            $existing = JournalEntry::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $debits = '0';
        $credits = '0';
        foreach ($lines as $line) {
            $debits = bcadd($debits, (string) ($line['debit'] ?? '0'), 2);
            $credits = bcadd($credits, (string) ($line['credit'] ?? '0'), 2);
        }

        if (bccomp($debits, $credits, 2) !== 0) {
            throw new \RuntimeException(__('Journal entry is not balanced.'));
        }

        return DB::transaction(function () use ($date, $lines, $description, $referenceType, $referenceId, $branchId, $userId, $idempotencyKey) {
            $entry = JournalEntry::create([
                'entry_number' => NumberGenerator::next('JE'),
                'entry_date' => $date,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'status' => 'posted',
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($lines as $line) {
                $debit = (string) ($line['debit'] ?? '0');
                $credit = (string) ($line['credit'] ?? '0');
                if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
                    continue;
                }
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Reverse a posted entry by creating an offsetting entry.
     *
     * The original is never edited or deleted — it stays in the ledger and is
     * flagged, so the reversal and its reason remain readable years later.
     */
    public function reverseEntry(JournalEntry $entry, string $reason = ''): JournalEntry
    {
        if ($entry->status === 'reversed') {
            throw new \RuntimeException(__('This entry is already reversed.'));
        }

        $description = $reason !== ''
            ? __('Reversal of :number — :reason', [
                'number' => $entry->entry_number,
                'reason' => $reason,
            ])
            : __('Reversal of :number', ['number' => $entry->entry_number]);

        $lines = [];
        foreach ($entry->lines as $line) {
            $lines[] = [
                'account_id' => $line->account_id,
                'debit' => (string) $line->credit,
                'credit' => (string) $line->debit,
                'description' => $description,
            ];
        }

        $reversal = $this->postEntry(
            now()->toDateString(),
            $lines,
            $description,
            JournalEntry::class,
            $entry->id,
            $entry->branch_id,
            auth()->id(),
            null
        );

        $entry->update([
            'status' => 'reversed',
            'reversed_by_entry_id' => $reversal->id,
        ]);

        return $reversal;
    }

    /**
     * Balance for a single account over an optional date window (debits - credits).
     *
     * Reversed entries are deliberately still counted. A reversal posts its own
     * offsetting entry rather than deleting the original, so the original's
     * lines and the reversal's lines cancel. Excluding the original while
     * keeping its reversal would leave the account permanently out by the
     * amount that was reversed. `status = 'reversed'` is an annotation for the
     * audit trail, not a removal marker.
     */
    public function accountBalance(Account $account, ?string $asOf = null, ?string $from = null): string
    {
        $query = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($asOf, $from) {
                $q->whereIn('status', ['posted', 'reversed']);
                if ($from) {
                    $q->whereDate('entry_date', '>=', $from);
                }
                if ($asOf) {
                    $q->whereDate('entry_date', '<=', $asOf);
                }
            });

        $debits = (string) $query->sum('debit');
        $credits = (string) (clone $query)->sum('credit');

        return bcsub($debits, $credits, 2);
    }

    /**
     * Resolve a system account by its code. Throws a clear error if the chart
     * of accounts has not been seeded, rather than silently posting to nowhere.
     */
    protected function systemAccount(string $code): Account
    {
        $account = Account::where('code', $code)->first();
        if (! $account) {
            throw new \RuntimeException(__('Required account ":code" is missing from the chart of accounts.', ['code' => $code]));
        }

        return $account;
    }

    /**
     * Post the sale journal entry (double-entry, balanced, idempotent).
     *
     * Dr Cash/Bank (or AR)         grand_total
     *     Cr Sales Revenue               subtotal (net)
     *     Cr Tax Payable                 tax_total
     * Dr COGS                        cost_total
     *     Cr Inventory                   cost_total
     */
    public function postSaleEntry(Sale $sale, ?string $idempotencyKey = null): JournalEntry
    {
        $lines = [
            ['account_id' => $this->systemAccount('1000')->id, 'debit' => (string) $sale->grand_total, 'credit' => '0', 'description' => __('Cash / AR from sale')],
            ['account_id' => $this->systemAccount('4000')->id, 'debit' => '0', 'credit' => (string) $sale->subtotal, 'description' => __('Sales revenue')],
        ];

        if (bccomp((string) $sale->tax_total, '0', 2) !== 0) {
            $lines[] = ['account_id' => $this->systemAccount('2100')->id, 'debit' => '0', 'credit' => (string) $sale->tax_total, 'description' => __('Output tax')];
        }

        if (bccomp((string) $sale->cost_total, '0', 2) !== 0) {
            $lines[] = ['account_id' => $this->systemAccount('5000')->id, 'debit' => (string) $sale->cost_total, 'credit' => '0', 'description' => __('Cost of goods sold')];
            $lines[] = ['account_id' => $this->systemAccount('1300')->id, 'debit' => '0', 'credit' => (string) $sale->cost_total, 'description' => __('Inventory relief')];
        }

        return $this->postEntry(
            $sale->sold_at->toDateString(),
            $lines,
            __('Sale :number', ['number' => $sale->invoice_number]),
            Sale::class,
            $sale->id,
            $sale->branch_id,
            $sale->user_id,
            $idempotencyKey,
        );
    }

    /**
     * Post the purchase journal entry.
     */
    public function postPurchaseEntry(Purchase $purchase, ?string $idempotencyKey = null): JournalEntry
    {
        $lines = [
            ['account_id' => $this->systemAccount('1300')->id, 'debit' => (string) $purchase->grand_total, 'credit' => '0', 'description' => __('Inventory received')],
            ['account_id' => $this->systemAccount('2000')->id, 'debit' => '0', 'credit' => (string) $purchase->grand_total, 'description' => __('Accounts payable')],
        ];

        return $this->postEntry(
            $purchase->purchased_at->toDateString(),
            $lines,
            __('Purchase :number', ['number' => $purchase->purchase_number]),
            Purchase::class,
            $purchase->id,
            $purchase->branch_id,
            $purchase->user_id,
            $idempotencyKey,
        );
    }

    /**
     * Post the expense journal entry.
     */
    public function postExpenseEntry(Expense $expense, ?string $idempotencyKey = null): JournalEntry
    {
        $expenseAccount = $expense->account_id
            ? Account::find($expense->account_id)
            : $this->systemAccount('6000');

        $lines = [
            ['account_id' => $expenseAccount->id, 'debit' => (string) $expense->amount, 'credit' => '0', 'description' => __('Expense')],
            ['account_id' => $this->systemAccount('1000')->id, 'debit' => '0', 'credit' => (string) $expense->amount, 'description' => __('Cash paid')],
        ];

        return $this->postEntry(
            $expense->expense_date->toDateString(),
            $lines,
            __('Expense :reference', ['reference' => $expense->reference]),
            Expense::class,
            $expense->id,
            $expense->branch_id,
            $expense->user_id,
            $idempotencyKey,
        );
    }
}
