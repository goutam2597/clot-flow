<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\IdempotencyKey;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Exceptions\InsufficientStockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Offline synchronization management.
 *
 * Terminals keep selling while disconnected: every offline transaction is
 * queued locally with a stable idempotency key and pushed to this server as
 * soon as connectivity returns. This controller is the operator console for
 * that queue — health counters, a filterable queue listing, and the retry /
 * manual-confirmation workflow.
 *
 * Every record is processed inside its own transaction so one malformed sale
 * can never block the rest of a batch, and each state change is written to the
 * audit trail plus the idempotency ledger.
 */
class SyncController extends Controller
{
    /** Every sync state a sale can carry (see the sales migration). */
    public const SYNC_STATUSES = ['synced', 'pending', 'syncing', 'failed', 'attention'];

    /** States that still require server-side work. */
    public const QUEUE_STATUSES = ['pending', 'syncing', 'failed', 'attention'];

    /** Audit actions surfaced on the sync log screen. */
    public const LOG_ACTIONS = ['synced', 'sync_failed', 'sync_attention', 'sync_marked_synced', 'sync_push'];

    public const PER_PAGE = 20;

    public const BATCH_LIMIT = 200;

    public const SALE_STATUSES = ['completed', 'held', 'voided', 'returned'];

    public function __construct(protected SaleService $sales) {}

    /**
     * Ingest sales captured on a disconnected terminal.
     *
     * This is the write side of offline mode: the browser queues each sale with
     * a stable idempotency key and pushes it here when connectivity returns. A
     * key that has already been accepted short-circuits to the original sale,
     * so a retried or duplicated push can never create a second sale, a second
     * stock movement or a second journal entry.
     *
     * Every sale is processed inside its own transaction, so one bad record
     * cannot roll back or block the rest of the batch.
     */
    public function receive(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('sales.create')) {
            abort(403, __('You are not allowed to record sales.'));
        }

        $validated = $request->validate([
            'sales' => ['required', 'array', 'min:1', 'max:' . self::BATCH_LIMIT],
            'sales.*.idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9\-_]+$/'],
            'sales.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'sales.*.cash_register_session_id' => ['nullable', 'integer', 'exists:cash_register_sessions,id'],
            'sales.*.customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sales.*.sold_at' => ['nullable', 'date'],
            'sales.*.discount_total' => ['nullable', 'numeric', 'min:0'],
            'sales.*.shipping_total' => ['nullable', 'numeric', 'min:0'],
            'sales.*.notes' => ['nullable', 'string', 'max:1000'],
            'sales.*.items' => ['required', 'array', 'min:1'],
            'sales.*.items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'sales.*.items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'sales.*.items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'sales.*.items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'sales.*.items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'sales.*.payments' => ['required', 'array', 'min:1'],
            'sales.*.payments.*.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'sales.*.payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'sales.*.payments.*.tendered_amount' => ['nullable', 'numeric', 'min:0'],
            'sales.*.payments.*.reference' => ['nullable', 'string', 'max:120'],
        ], [], [
            'sales' => __('queued sales'),
            'sales.*.idempotency_key' => __('idempotency key'),
        ]);

        $branchId = $user->branch_id;
        $batchReference = 'SYNC-' . now()->format('Ymd-His') . '-' . strtoupper(Str::random(6));

        $results = [];

        foreach ($validated['sales'] as $payload) {
            $results[] = $this->receiveSale($payload, $user, $branchId, $batchReference);
        }

        $summary = $this->summarise($results);

        AuditLogger::log(
            'sync_push',
            Sale::class,
            null,
            null,
            array_merge(['batch' => $batchReference, 'source' => 'offline'], $summary),
            __('Offline batch :batch received: :synced created, :failed rejected, :attention need review, :skipped already recorded.', [
                'batch' => $batchReference,
                'synced' => $summary['synced'],
                'failed' => $summary['failed'],
                'attention' => $summary['attention'],
                'skipped' => $summary['skipped'],
            ]),
            $branchId
        );

        return response()->json([
            'success' => ($summary['failed'] === 0 && $summary['attention'] === 0),
            'batch' => $batchReference,
            'summary' => $summary,
            'results' => $results,
        ]);
    }

    /**
     * Record one queued offline sale.
     *
     * @param  array  $payload
     * @return array<string, mixed>
     */
    protected function receiveSale(array $payload, $user, ?int $branchId, string $batchReference): array
    {
        $key = $payload['idempotency_key'];

        // 1. Already accepted? Return the original sale untouched.
        $seen = IdempotencyKey::where('key', $key)
            ->where('endpoint', 'sync/sales')
            ->where('status', 'completed')
            ->first();

        if ($seen && $seen->resource_id) {
            $existing = Sale::find($seen->resource_id);

            if ($existing) {
                return [
                    'key' => $key,
                    'sale_id' => $existing->id,
                    'invoice_number' => (string) $existing->invoice_number,
                    'result' => 'skipped',
                    'message' => __('Already synchronized.'),
                ];
            }
        }

        // 2. Build the sale from product records. The queued prices are only a
        //    hint: SaleService recalculates every figure from the catalog.
        $branchId = $branchId
            ?: \App\Models\Branch::where('is_active', true)->where('is_default', true)->value('id')
            ?: \App\Models\Branch::where('is_active', true)->orderBy('id')->value('id');

        if (! $branchId) {
            return [
                'key' => $key,
                'result' => 'failed',
                'message' => __('No branch is configured to receive this sale.'),
            ];
        }

        $items = [];

        foreach ($payload['items'] as $line) {
            $product = Product::where('status', 'active')->with('taxRate')->find($line['product_id']);

            if (! $product) {
                return [
                    'key' => $key,
                    'result' => 'failed',
                    'message' => __('A product on this sale is no longer available.'),
                ];
            }

            $items[] = [
                'product_id' => $product->id,
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'tax_rate_id' => $product->tax_rate_id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => $this->decimal($line['quantity'], 4),
                'unit_price' => $this->decimal($product->selling_price, 4),
                'unit_cost' => $this->decimal($product->cost_price, 4),
                'discount_amount' => $this->decimal($line['discount_amount'] ?? '0', 2),
                'tax_rate' => $this->decimal($product->taxRate?->rate ?? '0', 4),
            ];
        }

        $payments = [];

        foreach ($payload['payments'] as $payment) {
            $method = PaymentMethod::where('is_active', true)->find($payment['payment_method_id']);

            if (! $method) {
                return [
                    'key' => $key,
                    'result' => 'failed',
                    'message' => __('A payment method on this sale is no longer active.'),
                ];
            }

            $amount = $this->decimal($payment['amount'], 2);
            $tendered = Money::max($this->decimal($payment['tendered_amount'] ?? $payment['amount'], 2), $amount, 2);

            $payments[] = [
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'tendered_amount' => $tendered,
                'reference' => $payment['reference'] ?? null,
            ];
        }

        $sessionId = $payload['cash_register_session_id'] ?? null;

        if ($sessionId && ! CashRegisterSession::where('status', 'open')->whereKey($sessionId)->exists()) {
            $sessionId = null;
        }

        $salePayload = [
            'branch_id' => $branchId,
            'warehouse_id' => (int) $payload['warehouse_id'],
            'cash_register_session_id' => $sessionId,
            'user_id' => $user->id,
            'customer_id' => $payload['customer_id'] ?? null,
            'items' => $items,
            'payments' => $payments,
            'discount_total' => $this->decimal($payload['discount_total'] ?? '0', 2),
            'shipping_total' => $this->decimal($payload['shipping_total'] ?? '0', 2),
            'source' => 'offline',
            'sold_at' => $this->soldAt($payload['sold_at'] ?? null),
            'notes' => $payload['notes'] ?? null,
        ];

        try {
            $sale = $this->sales->create($salePayload, $key);

            IdempotencyKey::updateOrCreate(
                ['key' => $key, 'endpoint' => 'sync/sales'],
                [
                    'resource_type' => Sale::class,
                    'resource_id' => $sale->id,
                    'status' => 'completed',
                    'response_payload' => [
                        'invoice_number' => (string) $sale->invoice_number,
                        'grand_total' => (string) $sale->grand_total,
                        'batch' => $batchReference,
                    ],
                    'user_id' => $user->id,
                ]
            );

            AuditLogger::log(
                'synced',
                Sale::class,
                $sale->id,
                null,
                [
                    'sync_status' => 'synced',
                    'invoice_number' => $sale->invoice_number,
                    'grand_total' => (string) $sale->grand_total,
                    'idempotency_key' => $key,
                    'batch' => $batchReference,
                    'source' => 'offline',
                ],
                __('Offline sale :invoice received from the terminal queue.', ['invoice' => $sale->invoice_number]),
                $branchId
            );

            return [
                'key' => $key,
                'sale_id' => $sale->id,
                'invoice_number' => (string) $sale->invoice_number,
                'grand_total' => (string) $sale->grand_total,
                'receipt_url' => route('pos.receipt', $sale),
                'result' => 'synced',
                'message' => __('Sale :invoice synchronized.', ['invoice' => $sale->invoice_number]),
            ];
        } catch (InsufficientStockException $e) {
            // Stock moved while this terminal was offline. Never overwrite it —
            // park the record so a human can decide.
            $this->park($key, $payload, $e->getMessage(), 'attention', $user, $branchId, $batchReference);

            return [
                'key' => $key,
                'result' => 'attention',
                'message' => $e->getMessage(),
            ];
        } catch (\RuntimeException $e) {
            $this->park($key, $payload, $e->getMessage(), 'failed', $user, $branchId, $batchReference);

            return [
                'key' => $key,
                'result' => 'failed',
                'message' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            report($e);

            $this->park($key, $payload, $e->getMessage(), 'failed', $user, $branchId, $batchReference);

            return [
                'key' => $key,
                'result' => 'failed',
                'message' => __('The sale could not be recorded. Please review it manually.'),
            ];
        }
    }

    /**
     * Record a rejected offline sale in the idempotency ledger so the terminal
     * stops retrying it forever and an operator can resolve it.
     */
    protected function park(string $key, array $payload, string $reason, string $status, $user, ?int $branchId, string $batchReference): void
    {
        IdempotencyKey::updateOrCreate(
            ['key' => $key, 'endpoint' => 'sync/sales'],
            [
                'resource_type' => Sale::class,
                'resource_id' => null,
                'status' => $status,
                'response_payload' => [
                    'reason' => $reason,
                    'batch' => $batchReference,
                    'payload' => [
                        'sold_at' => $payload['sold_at'] ?? null,
                        'items' => count($payload['items'] ?? []),
                        'warehouse_id' => $payload['warehouse_id'] ?? null,
                    ],
                ],
                'user_id' => $user->id,
            ]
        );

        AuditLogger::log(
            $status === 'attention' ? 'sync_attention' : 'sync_failed',
            Sale::class,
            null,
            null,
            [
                'idempotency_key' => $key,
                'status' => $status,
                'reason' => $reason,
                'batch' => $batchReference,
            ],
            __('Offline sale :key rejected: :reason', ['key' => $key, 'reason' => $reason]),
            $branchId
        );
    }

    /**
     * The moment the cashier rang the sale up. A clock further than a day out is
     * rejected so a mis-set device cannot rewrite history.
     */
    protected function soldAt(?string $value): Carbon
    {
        if (! $value) {
            return now();
        }

        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return now();
        }

        if ($date->isFuture()) {
            return now();
        }

        if ($date->lt(now()->subDays(1))) {
            return now();
        }

        return $date;
    }

    protected function decimal($value, int $scale = 2): string
    {
        $value = ($value === null || $value === '') ? '0' : (string) $value;

        return bcadd($value, '0', $scale);
    }

    /**
     * Sync health overview + filterable queue listing.
     */
    public function status(Request $request): View
    {
        $user = $request->user();
        $branchId = $user->branch_id;
        $filters = $this->filters($request);

        // Counters are always computed on the queue without the sync-status
        // filter, so the cards keep telling the whole story while the table
        // below shows whatever the operator filtered for.
        $base = $this->queueQuery($request, $branchId, false);

        $counts = [];
        foreach (self::SYNC_STATUSES as $status) {
            $counts[$status] = (clone $base)->where('sync_status', $status)->count();
        }
        $counts['queue'] = array_sum(array_map(fn ($s) => $counts[$s], self::QUEUE_STATUSES));
        $counts['all'] = (clone $base)->count();

        $queueValue = (string) (clone $base)->whereIn('sync_status', self::QUEUE_STATUSES)->sum('grand_total');
        $failedValue = (string) (clone $base)->where('sync_status', 'failed')->sum('grand_total');
        $offlineToday = (clone $base)->whereDate('sold_at', today())->count();

        $lastSyncedAt = (clone $base)->where('sync_status', 'synced')->max('updated_at');
        $oldestQueuedAt = (clone $base)->whereIn('sync_status', self::QUEUE_STATUSES)->min('sold_at');
        $stalledCount = (clone $base)
            ->whereIn('sync_status', ['pending', 'syncing'])
            ->where('sold_at', '<', now()->subHours(2))
            ->count();

        $health = match (true) {
            $counts['failed'] > 0 || $counts['attention'] > 0 => 'attention',
            $counts['pending'] > 0 || $counts['syncing'] > 0 => 'pending',
            default => 'healthy',
        };

        $query = $this->queueQuery($request, $branchId)
            ->with(['customer', 'user', 'branch', 'warehouse'])
            ->withCount('items');

        $this->applySort($query, $filters['sort'], $filters['dir']);

        $sales = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('sync.status', [
            'counts' => $counts,
            'queueValue' => $queueValue,
            'failedValue' => $failedValue,
            'offlineToday' => $offlineToday,
            'health' => $health,
            'lastSyncedAt' => $lastSyncedAt ? Carbon::parse($lastSyncedAt) : null,
            'oldestQueuedAt' => $oldestQueuedAt ? Carbon::parse($oldestQueuedAt) : null,
            'stalledCount' => $stalledCount,
            'filters' => $filters,
            'sales' => $sales,
            'statuses' => self::SYNC_STATUSES,
            'saleStatuses' => self::SALE_STATUSES,
            'canRetry' => $user->can('sales.create'),
            'canMarkSynced' => $user->can('settings.manage'),
            'branches' => $branchId
                ? Branch::whereKey($branchId)->get(['id', 'name'])
                : Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Push the queue: retry pending/failed sales, or confirm a sale as synced.
     *
     * Accepts an explicit sale (`sale_id`), an explicit batch (`ids[]`), or no
     * selection at all — in which case every queued sale matching the posted
     * filters is processed. JSON clients receive a per-sale report.
     */
    public function push(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'action' => ['required', 'in:retry,mark_synced'],
            'sale_id' => ['nullable', 'integer', 'exists:sales,id'],
            'ids' => ['nullable', 'array', 'max:' . self::BATCH_LIMIT],
            'ids.*' => ['integer', 'exists:sales,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'sync_status' => ['nullable', 'string', 'in:all,queue,' . implode(',', self::SYNC_STATUSES)],
            'source' => ['nullable', 'string', 'in:all,online,offline'],
            'status' => ['nullable', 'string', 'in:' . implode(',', self::SALE_STATUSES)],
            'branch_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'string', 'max:20'],
            'to' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', 'string', 'in:sold_at,invoice_number,grand_total'],
            'dir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        // Overriding a sync state is an administrative action; retrying a push is
        // part of normal sales operation.
        $required = $validated['action'] === 'mark_synced' ? 'settings.manage' : 'sales.create';

        if (! $user->can($required)) {
            abort(403, __('You are not allowed to run this synchronization action.'));
        }

        $targets = $this->resolveTargets($request, $validated, $user->branch_id);

        if ($targets->isEmpty()) {
            return $this->pushResponse($request, [], __('No queued sales matched the current selection.'));
        }

        $batchReference = 'SYNC-' . now()->format('Ymd-His') . '-' . strtoupper(Str::random(6));

        $results = [];
        foreach ($targets as $sale) {
            $results[] = $validated['action'] === 'mark_synced'
                ? $this->markSynced($sale, $batchReference)
                : $this->retrySale($sale, $batchReference);
        }

        $summary = $this->summarise($results);

        AuditLogger::log(
            'sync_push',
            Sale::class,
            null,
            null,
            array_merge(['action' => $validated['action'], 'batch' => $batchReference], $summary),
            __('Sync batch :batch: :synced synced, :failed failed, :attention need attention, :skipped skipped.', [
                'batch' => $batchReference,
                'synced' => $summary['synced'],
                'failed' => $summary['failed'],
                'attention' => $summary['attention'],
                'skipped' => $summary['skipped'],
            ]),
            $user->branch_id
        );

        return $this->pushResponse($request, $results, null, $summary);
    }

    /**
     * Sync activity trail (audit entries + idempotency ledger).
     */
    public function log(Request $request): View
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $actions = self::LOG_ACTIONS;
        $term = $filters['q'];
        $from = $this->dateFilter($filters['from']);
        $to = $this->dateFilter($filters['to'], true);

        $entries = AuditLog::query()
            ->with('user')
            ->where('entity_type', Sale::class)
            ->whereIn('action', $actions)
            ->when($user->branch_id, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('description', 'like', '%' . $term . '%')
                        ->orWhere('entity_id', is_numeric($term) ? (int) $term : 0);
                });
            })
            ->when(in_array($filters['action'], $actions, true), fn ($q) => $q->where('action', $filters['action']))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $keys = IdempotencyKey::query()
            ->where('endpoint', 'sync/push')
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('key', 'like', '%' . $term . '%')
                        ->orWhere('resource_type', 'like', '%' . $term . '%');
                });
            })
            ->latest('id')
            ->paginate(10, ['*'], 'keys_page')
            ->withQueryString();

        return view('sync.log', [
            'entries' => $entries,
            'keys' => $keys,
            'filters' => $filters,
            'actions' => $actions,
        ]);
    }

    /**
     * Re-validate a queued sale and move it to its correct sync state.
     *
     * @return array<string, mixed>
     */
    protected function retrySale(Sale $sale, string $batchReference): array
    {
        try {
            return DB::transaction(function () use ($sale, $batchReference) {
                $locked = Sale::whereKey($sale->id)->lockForUpdate()->first();

                if (! $locked) {
                    return $this->result($sale, 'skipped', __('Sale no longer exists.'));
                }

                $oldStatus = (string) $locked->sync_status;

                if ($oldStatus === 'synced') {
                    return $this->result($locked, 'skipped', __('Already synchronized.'));
                }

                // Another terminal may be mid-push for this record.
                if ($oldStatus === 'syncing' && $locked->updated_at && $locked->updated_at->gt(now()->subMinutes(2))) {
                    return $this->result($locked, 'skipped', __('A push is already in progress for this sale.'));
                }

                $locked->forceFill(['sync_status' => 'syncing'])->save();

                $issues = $this->collectSyncIssues($locked);

                if ($issues['blocking'] !== []) {
                    return $this->rejectSale($locked, $oldStatus, 'failed', 'sync_failed', $issues['blocking'], $batchReference);
                }

                if ($issues['attention'] !== []) {
                    return $this->rejectSale($locked, $oldStatus, 'attention', 'sync_attention', $issues['attention'], $batchReference);
                }

                $locked->forceFill(['sync_status' => 'synced'])->save();
                $this->recordLedger($locked, 'retry', ['status' => 'synced', 'batch' => $batchReference]);

                AuditLogger::log(
                    'synced',
                    Sale::class,
                    $locked->id,
                    ['sync_status' => $oldStatus],
                    [
                        'sync_status' => 'synced',
                        'invoice_number' => $locked->invoice_number,
                        'grand_total' => (string) $locked->grand_total,
                    ],
                    __('Offline invoice :invoice pushed to head office.', ['invoice' => $locked->invoice_number]),
                    $locked->branch_id
                );

                return $this->result($locked, 'synced', __('Invoice :invoice synchronized.', ['invoice' => $locked->invoice_number]));
            });
        } catch (Throwable $e) {
            Sale::whereKey($sale->id)->update(['sync_status' => 'failed']);

            AuditLogger::log(
                'sync_failed',
                Sale::class,
                $sale->id,
                null,
                ['sync_status' => 'failed', 'invoice_number' => $sale->invoice_number, 'error' => $e->getMessage()],
                __('Sync crashed for invoice :invoice: :reason', [
                    'invoice' => $sale->invoice_number,
                    'reason' => $e->getMessage(),
                ]),
                $sale->branch_id
            );

            return $this->result($sale, 'failed', $e->getMessage());
        }
    }

    /**
     * Administrative override: confirm a sale as synchronized without re-running
     * the reconciliation checks.
     *
     * @return array<string, mixed>
     */
    protected function markSynced(Sale $sale, string $batchReference): array
    {
        try {
            return DB::transaction(function () use ($sale, $batchReference) {
                $locked = Sale::whereKey($sale->id)->lockForUpdate()->first();

                if (! $locked) {
                    return $this->result($sale, 'skipped', __('Sale no longer exists.'));
                }

                $oldStatus = (string) $locked->sync_status;

                if ($oldStatus === 'synced') {
                    return $this->result($locked, 'skipped', __('Already synchronized.'));
                }

                $locked->forceFill(['sync_status' => 'synced'])->save();
                $this->recordLedger($locked, 'manual', ['status' => 'synced', 'batch' => $batchReference]);

                AuditLogger::log(
                    'sync_marked_synced',
                    Sale::class,
                    $locked->id,
                    ['sync_status' => $oldStatus],
                    [
                        'sync_status' => 'synced',
                        'invoice_number' => $locked->invoice_number,
                        'grand_total' => (string) $locked->grand_total,
                    ],
                    __('Invoice :invoice confirmed as synchronized manually.', ['invoice' => $locked->invoice_number]),
                    $locked->branch_id
                );

                return $this->result($locked, 'synced', __('Invoice :invoice marked as synchronized.', ['invoice' => $locked->invoice_number]));
            });
        } catch (Throwable $e) {
            return $this->result($sale, 'failed', $e->getMessage());
        }
    }

    /**
     * Park a sale in failed/attention with a full audit trail.
     *
     * @param  array<int, string>  $issues
     * @return array<string, mixed>
     */
    protected function rejectSale(Sale $sale, string $oldStatus, string $newStatus, string $action, array $issues, string $batchReference): array
    {
        $reason = implode(' ', $issues);

        $sale->forceFill(['sync_status' => $newStatus])->save();
        $this->recordLedger($sale, 'retry', ['status' => $newStatus, 'batch' => $batchReference]);

        AuditLogger::log(
            $action,
            Sale::class,
            $sale->id,
            ['sync_status' => $oldStatus],
            ['sync_status' => $newStatus, 'invoice_number' => $sale->invoice_number, 'issues' => $issues],
            $newStatus === 'failed'
                ? __('Sync rejected for invoice :invoice: :reason', ['invoice' => $sale->invoice_number, 'reason' => $reason])
                : __('Invoice :invoice needs review: :reason', ['invoice' => $sale->invoice_number, 'reason' => $reason]),
            $sale->branch_id
        );

        return $this->result($sale, $newStatus, $reason);
    }

    /**
     * Reconcile a queued sale against the same rules SaleService applied when the
     * record was created. Blocking issues reject the push; attention issues need
     * a human decision before the sale can be considered synchronized.
     *
     * @return array{blocking: array<int, string>, attention: array<int, string>}
     */
    protected function collectSyncIssues(Sale $sale): array
    {
        $blocking = [];
        $attention = [];

        $sale->loadMissing(['items', 'payments']);

        if ($sale->items->isEmpty()) {
            $blocking[] = __('the sale has no line items');
        }

        if ($sale->payments->isEmpty()) {
            $blocking[] = __('the sale has no payment record');
        }

        if ($sale->status !== 'completed') {
            $attention[] = __('the sale is :status and cannot be posted as completed', ['status' => $sale->status]);
        }

        if ($sale->source !== 'offline') {
            $attention[] = __('the sale was not captured offline');
        }

        if (trim((string) $sale->invoice_number) === '') {
            $blocking[] = __('the invoice number is missing');
        }

        if ($sale->sold_at && $sale->sold_at->gt(now()->addMinutes(5))) {
            $blocking[] = __('the sale date is in the future');
        }

        // Document totals: grand = subtotal + tax + shipping.
        $expectedGrand = Money::add(Money::add((string) $sale->subtotal, (string) $sale->tax_total, 2), (string) $sale->shipping_total, 2);
        if (! $this->matches($expectedGrand, (string) $sale->grand_total)) {
            $blocking[] = __('the grand total does not match subtotal, tax and shipping');
        }

        // Payments must account for the whole document.
        $settled = Money::add((string) $sale->paid_total, (string) $sale->due_total, 2);
        if (! $this->matches($settled, (string) $sale->grand_total)) {
            $blocking[] = __('paid and outstanding amounts do not add up to the grand total');
        }

        $expectedPaymentStatus = Money::isZero((string) $sale->due_total)
            ? 'paid'
            : (Money::isZero((string) $sale->paid_total) ? 'due' : 'partial');

        if ($sale->payment_status !== $expectedPaymentStatus) {
            $blocking[] = __('the payment status does not match the settled amount');
        }

        // Line level roll-ups must reconcile with the document.
        $taxTotal = Money::sum($sale->items->map(fn ($item) => (string) $item->tax_amount)->all(), 2);
        if (! $this->matches($taxTotal, (string) $sale->tax_total)) {
            $blocking[] = __('line taxes do not add up to the sale tax total');
        }

        $discountTotal = Money::sum($sale->items->map(fn ($item) => (string) $item->discount_amount)->all(), 2);
        if (! $this->matches($discountTotal, (string) $sale->discount_total)) {
            $blocking[] = __('line discounts do not add up to the sale discount total');
        }

        $lineTotal = Money::sum($sale->items->map(fn ($item) => (string) $item->line_total)->all(), 2);
        if (! $this->matches($lineTotal, Money::add((string) $sale->subtotal, (string) $sale->tax_total, 2))) {
            $blocking[] = __('line totals do not add up to the sale subtotal and tax');
        }

        foreach ($sale->items as $item) {
            $gross = Money::mul((string) $item->unit_price, (string) $item->quantity, 4);
            $net = Money::sub($gross, (string) $item->discount_amount, 4);
            $tax = Money::percent($net, (string) $item->tax_rate, 2);
            $expectedLine = Money::add($net, $tax, 2);

            if (! $this->matches($expectedLine, (string) $item->line_total, '0.05')) {
                $blocking[] = __('line :name is not internally consistent', ['name' => $item->product_name]);

                continue;
            }

            if (Money::cmp((string) $item->quantity, '0', 4) <= 0) {
                $blocking[] = __('line :name has a non-positive quantity', ['name' => $item->product_name]);
            }
        }

        if (Sale::where('invoice_number', $sale->invoice_number)->whereKeyNot($sale->id)->exists()) {
            $attention[] = __('the invoice number already exists on another sale');
        }

        return ['blocking' => $blocking, 'attention' => $attention];
    }

    /**
     * Keep one ledger row per sale per endpoint, reusing the sale's own
     * idempotency key so retries stay idempotent.
     */
    protected function recordLedger(Sale $sale, string $mode, array $payload): void
    {
        IdempotencyKey::updateOrCreate(
            ['endpoint' => 'sync/push', 'resource_type' => 'sale', 'resource_id' => $sale->id],
            [
                'key' => (string) ($sale->idempotency_key ?: Str::uuid()),
                'status' => 'completed',
                'response_payload' => array_merge([
                    'invoice_number' => $sale->invoice_number,
                    'grand_total' => (string) $sale->grand_total,
                    'sync_status' => $sale->sync_status,
                    'mode' => $mode,
                ], $payload),
                'user_id' => auth()->id(),
            ]
        );
    }

    /**
     * Shared queue query: source, branch scope, search, sale status and dates.
     */
    protected function queueQuery(Request $request, ?int $branchId, bool $applyStatusFilter = true): Builder
    {
        $query = Sale::query();

        $source = (string) $request->input('source', 'offline');
        if (in_array($source, ['online', 'offline'], true)) {
            $query->where('source', $source);
        }

        if ($branchId) {
            $query->where('branch_id', $branchId);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        $term = trim((string) $request->input('q', ''));
        if ($term !== '') {
            $query->where(function ($inner) use ($term) {
                $inner->where('invoice_number', 'like', '%' . $term . '%')
                    ->orWhere('idempotency_key', 'like', '%' . $term . '%')
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%' . $term . '%'));
            });
        }

        $saleStatus = (string) $request->input('status', '');
        if (in_array($saleStatus, self::SALE_STATUSES, true)) {
            $query->where('status', $saleStatus);
        }

        if ($from = $this->dateFilter($request->input('from'))) {
            $query->where('sold_at', '>=', $from);
        }

        if ($to = $this->dateFilter($request->input('to'), true)) {
            $query->where('sold_at', '<=', $to);
        }

        if ($applyStatusFilter) {
            $status = (string) $request->input('sync_status', 'queue');

            if (in_array($status, self::SYNC_STATUSES, true)) {
                $query->where('sync_status', $status);
            } elseif ($status === 'queue') {
                $query->whereIn('sync_status', self::QUEUE_STATUSES);
            }
        }

        return $query;
    }

    /**
     * Decide which sales a push request targets.
     */
    protected function resolveTargets(Request $request, array $validated, ?int $branchId)
    {
        $query = Sale::query();

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if (! empty($validated['sale_id'])) {
            return $query->whereKey($validated['sale_id'])->get();
        }

        if (! empty($validated['ids'])) {
            return $query->whereIn('id', $validated['ids'])->orderBy('sold_at')->get();
        }

        // No explicit selection: push whatever the posted filters describe.
        return $this->queueQuery($request, $branchId)
            ->whereIn('sync_status', self::QUEUE_STATUSES)
            ->orderBy('sold_at')
            ->limit(self::BATCH_LIMIT)
            ->get();
    }

    protected function applySort(Builder $query, string $sort, string $dir): void
    {
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

        $column = match ($sort) {
            'invoice_number' => 'invoice_number',
            'grand_total' => 'grand_total',
            default => 'sold_at',
        };

        $query->orderBy($column, $dir)->orderByDesc('id');
    }

    /**
     * Normalised request filters shared by the screen and its redirects.
     *
     * @return array<string, string>
     */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'action' => (string) $request->input('action', ''),
            'sync_status' => (string) $request->input('sync_status', 'queue'),
            'source' => (string) $request->input('source', 'offline'),
            'status' => (string) $request->input('status', ''),
            'branch_id' => (string) $request->input('branch_id', ''),
            'from' => (string) $request->input('from', ''),
            'to' => (string) $request->input('to', ''),
            'sort' => (string) $request->input('sort', 'sold_at'),
            'dir' => (string) $request->input('dir', 'desc'),
        ];
    }

    protected function dateFilter(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = Carbon::parse((string) $value);
        } catch (Throwable $e) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    protected function matches(string $actual, string $expected, string $tolerance = '0.01'): bool
    {
        $diff = Money::sub($actual, $expected, 4);

        return Money::cmp($diff, $tolerance) <= 0 && Money::cmp($diff, '-' . $tolerance) >= 0;
    }

    /**
     * Per-sale outcome of a push, used by both the JSON report and the audit
     * trail.
     *
     * @return array<string, mixed>
     */
    protected function result(Sale $sale, string $result, string $message): array
    {
        return [
            'sale_id' => $sale->id,
            'invoice_number' => (string) $sale->invoice_number,
            'result' => $result,
            'sync_status' => (string) $sale->sync_status,
            'grand_total' => (string) $sale->grand_total,
            'message' => $message,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, int>
     */
    protected function summarise(array $results): array
    {
        $summary = ['processed' => count($results), 'synced' => 0, 'failed' => 0, 'attention' => 0, 'skipped' => 0];

        foreach ($results as $result) {
            $key = $result['result'] ?? 'skipped';
            if (isset($summary[$key])) {
                $summary[$key]++;
            }
        }

        return $summary;
    }

    /**
     * Web clients get a flash message, POS/API clients get a JSON report.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<string, int>  $summary
     */
    protected function pushResponse(Request $request, array $results, ?string $emptyMessage = null, array $summary = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $emptyMessage === null,
                'message' => $emptyMessage ?? __('Synchronization batch processed.'),
                'summary' => $summary,
                'results' => $results,
            ], $emptyMessage === null ? 200 : 422);
        }

        if ($emptyMessage !== null) {
            return back()->with('error', $emptyMessage);
        }

        $message = __('Sync complete: :synced synchronized, :failed failed, :attention need attention, :skipped skipped.', [
            'synced' => $summary['synced'] ?? 0,
            'failed' => $summary['failed'] ?? 0,
            'attention' => $summary['attention'] ?? 0,
            'skipped' => $summary['skipped'] ?? 0,
        ]);

        $type = ($summary['failed'] ?? 0) > 0 || ($summary['attention'] ?? 0) > 0 ? 'warning' : 'success';

        return back()->with($type, $message);
    }
}
