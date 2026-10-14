<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Bulk deletion across the catalogues.
 *
 * The design rule: a bulk action must never be more destructive than the sum of
 * the individual ones. Deleting forty products where three carry stock has to
 * delete the thirty-seven that are safe and report the three by name --- because
 * a bulk delete that either does everything or refuses everything is useless on
 * a catalogue that has been in use for a year.
 *
 * Refusals are collected, never thrown, and the partial success is reported as
 * 207 so the client can say what happened instead of retrying.
 */
class BulkActionService
{
    /**
     * resource => [model, permission, deletable?, reason?]
     *
     * `deletable` decides, per row, whether that row may go. It returns null
     * to allow, or a translated reason to refuse.
     */
    protected function resources(): array
    {
        return [
            'products' => [
                'model' => Product::class,
                'permission' => 'products.manage',
                'subject' => __('products'),
                'deletable' => fn (Product $model) => $this->productRefusal($model),
            ],
            'categories' => [
                'model' => Category::class,
                'permission' => 'products.manage',
                'subject' => __('categories'),
                'deletable' => fn (Category $model) => $this->categoryRefusal($model),
            ],
            'customers' => [
                'model' => Customer::class,
                'permission' => 'customers.manage',
                'subject' => __('customers'),
                'deletable' => fn (Customer $model) => $this->customerRefusal($model),
            ],
            'suppliers' => [
                'model' => Supplier::class,
                'permission' => 'suppliers.manage',
                'subject' => __('suppliers'),
                'deletable' => fn (Supplier $model) => $this->supplierRefusal($model),
            ],
            'users' => [
                'model' => User::class,
                'permission' => 'users.manage',
                'subject' => __('users'),
                'deletable' => fn (User $model) => $this->userRefusal($model),
            ],
        ];
    }

    /**
     * The callable for a resource, already permission-checked.
     *
     * @return callable(int[]): array{success: bool, deleted: int, refused: array<int, array{name: string, reason: string}>}
     */
    public function handlerFor(string $resource): ?callable
    {
        $definition = $this->resources()[$resource] ?? null;

        if ($definition === null) {
            return null;
        }

        $permission = $definition['permission'];

        return function (array $ids, $user) use ($definition, $permission): array {
            Gate::authorize($permission);

            /** @var class-string $modelClass */
            $modelClass = $definition['model'];

            // Missing rows are a client problem, not a reason to refuse the
            // rest: a list that was rendered a minute ago may have had a row
            // deleted by someone else since.
            $models = $modelClass::query()->whereIn('id', $ids)->get();

            $deletable = [];
            $refused = [];

            foreach ($models as $model) {
                $reason = $definition['deletable']($model);

                if ($reason === null) {
                    $deletable[] = $model;
                } else {
                    $refused[] = ['name' => $this->nameFor($model), 'reason' => $reason];
                }
            }

            if ($deletable === []) {
                return ['success' => false, 'deleted' => 0, 'refused' => $refused];
            }

            $deletedIds = DB::transaction(function () use ($deletable, $definition): array {
                $ids = [];

                foreach ($deletable as $model) {
                    AuditLogger::log(
                        'bulk-deleted',
                        $model::class,
                        $model->getKey(),
                        $model->toArray(),
                        null,
                        'Bulk deleted '.$definition['subject'].': '.$this->nameFor($model)
                    );

                    $model->delete();
                    $ids[] = $model->getKey();
                }

                return $ids;
            });

            return [
                'success' => true,
                'deleted' => count($deletedIds),
                'deleted_ids' => $deletedIds,
                'refused' => $refused,
            ];
        };
    }

    /* ------------------------------------------------------------------
     | Per-row rules
     | ------------------------------------------------------------------ */

    /** A product that anything still points at cannot be erased. */
    protected function productRefusal(Product $product): ?string
    {
        $referenced = SaleItem::where('product_id', $product->id)->exists()
            || PurchaseItem::where('product_id', $product->id)->exists()
            || StockMovement::where('product_id', $product->id)->exists()
            || Stock::where('product_id', $product->id)->where('quantity', '!=', 0)->exists();

        return $referenced
            ? __('has stock or transaction history --- deactivate it instead')
            : null;
    }

    /** A category with children or products is a leaf that is not a leaf yet. */
    protected function categoryRefusal(Category $category): ?string
    {
        if ($category->children()->exists()) {
            return __('has sub-categories --- move them first');
        }

        if (Product::where('category_id', $category->id)->exists()) {
            return __('still has products');
        }

        return null;
    }

    protected function customerRefusal(Customer $customer): ?string
    {
        return SaleItem::query()
            ->whereIn('sale_id', \App\Models\Sale::where('customer_id', $customer->id)->select('id'))
            ->exists()
            ? __('appears on recorded sales')
            : null;
    }

    protected function supplierRefusal(Supplier $supplier): ?string
    {
        return PurchaseItem::query()
            ->whereIn('purchase_id', \App\Models\Purchase::where('supplier_id', $supplier->id)->select('id'))
            ->exists()
            ? __('appears on recorded purchases')
            : null;
    }

    /**
     * An owner is not deletable.
     *
     * Deleting the account that owns the business locks everyone out of the
     * installation with no way back in. That is a restore-from-backup, not a
     * permissions change, and it is not something a bulk action should be able
     * to do by accident.
     */
    protected function userRefusal(User $user): ?string
    {
        return $user->hasRole('Business Owner')
            ? __('owns this business, so it cannot be deleted')
            : null;
    }

    protected function nameFor(object $model): string
    {
        foreach (['name', 'invoice_number', 'sku', 'email'] as $attribute) {
            if (isset($model->{$attribute}) && $model->{$attribute} !== '') {
                return (string) $model->{$attribute};
            }
        }

        return '#'.$model->getKey();
    }
}
