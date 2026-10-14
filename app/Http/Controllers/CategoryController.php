<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Product categories. Categories are referenced by products, so deleting a
 * category that is still in use is refused.
 */
class CategoryController extends Controller
{
    protected int $perPage = 15;

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => $request->query('status'),
        ];

        $categories = Category::query()
            ->with('parent')
            ->withCount('products')
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $query->where(function ($inner) use ($filters) {
                    $inner->where('name', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('code', 'like', '%' . $filters['q'] . '%');
                });
            })
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate($this->perPage)
            ->withQueryString();

        return view('categories.index', [
            'title' => __('Categories'),
            'categories' => $categories,
            'filters' => $filters,
            'stats' => [
                'total' => Category::count(),
                'active' => Category::where('is_active', true)->count(),
                'products' => Product::whereNull('deleted_at')->count(),
                'uncategorised' => Product::whereNull('category_id')->count(),
            ],
            'parents' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->categoryData($request);

        $category = DB::transaction(function () use ($data, $request) {
            $category = Category::create($data);

            AuditLogger::log(
                'created',
                Category::class,
                $category->id,
                null,
                $category->toArray(),
                'Category created: ' . $category->name,
                $request->user()->branch_id
            );

            return $category;
        });

        return redirect()
            ->route('categories.index')
            ->with('success', __('Category ":name" has been created.', ['name' => $category->name]));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->categoryData($request, $category);
        $original = $category->toArray();

        DB::transaction(function () use ($category, $data, $original, $request) {
            $category->update($data);

            AuditLogger::log(
                'updated',
                Category::class,
                $category->id,
                $original,
                $category->toArray(),
                'Category updated: ' . $category->name,
                $request->user()->branch_id
            );
        });

        return redirect()
            ->route('categories.index')
            ->with('success', __('Category ":name" has been updated.', ['name' => $category->name]));
    }

    public function destroy(Category $category): RedirectResponse
    {
        $hasChildren = Category::where('parent_id', $category->id)->exists();

        if ($hasChildren || Product::where('category_id', $category->id)->exists()) {
            return redirect()
                ->route('categories.index')
                ->with('error', __('Category ":name" is still in use and cannot be deleted.', ['name' => $category->name]));
        }

        $original = $category->toArray();

        DB::transaction(function () use ($category, $original) {
            AuditLogger::log(
                'deleted',
                Category::class,
                $category->id,
                $original,
                null,
                'Category deleted: ' . $category->name
            );

            $category->delete();
        });

        return redirect()
            ->route('categories.index')
            ->with('success', __('Category ":name" has been deleted.', ['name' => $category->name]));
    }

    protected function categoryData(Request $request, ?Category $category = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('categories', 'code')->ignore($category?->id)],
            'parent_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
                Rule::notIn($category ? [$category->id] : []),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'name' => __('category name'),
            'parent_id' => __('parent category'),
            'is_active' => __('status'),
        ]);

        return [
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'parent_id' => $validated['parent_id'] ?? null,
            // A nullable field is ABSENT from $validated when the form omits
            // it, not present-and-null. Reading one without ?? throws, which
            // makes "create a category without a description" a 500 on the
            // ordinary path rather than on an edge case.
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
