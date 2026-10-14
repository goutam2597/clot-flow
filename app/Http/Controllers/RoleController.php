<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role and permission administration (spatie/laravel-permission).
 *
 * Permissions are presented grouped by module so an administrator can grant a
 * coherent slice of the product instead of hunting through a flat list. Every
 * change is audited with the previous and next permission sets.
 */
class RoleController extends Controller
{
    protected int $perPage = 15;

    /**
     * Human readable module names, in presentation order.
     */
    protected array $modules = [
        'dashboard' => 'Dashboard',
        'pos' => 'Point of Sale',
        'sales' => 'Sales',
        'products' => 'Products',
        'inventory' => 'Inventory',
        'purchases' => 'Purchases',
        'customers' => 'Customers',
        'suppliers' => 'Suppliers',
        'invoices' => 'Invoices',
        'expenses' => 'Expenses',
        'accounting' => 'Accounting',
        'reports' => 'Reports',
        'branches' => 'Branches & Warehouses',
        'users' => 'Users',
        'roles' => 'Roles & Permissions',
        'settings' => 'Settings',
        'languages' => 'Languages',
        'audit' => 'Audit Logs',
    ];

    /**
     * Human readable actions for the permission verbs in use.
     */
    protected array $actions = [
        'view' => 'View',
        'access' => 'Access',
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
        'manage' => 'Manage',
        'void' => 'Void transactions',
        'refund' => 'Process refunds',
        'adjust' => 'Adjust stock',
        'transfer' => 'Transfer stock',
        'receive' => 'Receive goods',
    ];

    public function index(): View
    {
        $roles = Role::query()
            ->withCount('permissions')
            ->with('users')
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('admin.roles.index', [
            'roles' => $roles,
            'stats' => [
                'roles' => Role::count(),
                'permissions' => Permission::where('guard_name', 'web')->count(),
                'assigned' => Role::has('users')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.create', [
            'role' => new Role(),
            'permissionGroups' => $this->permissionGroups(),
            'selected' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRole($request);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        $permissions = $validated['permissions'] ?? [];
        $role->syncPermissions($permissions);

        AuditLogger::log(
            'created',
            Role::class,
            $role->id,
            null,
            ['name' => $role->name, 'permissions' => $permissions],
            __('Role created')
        );

        return redirect()
            ->route('admin.roles.index')
            ->with('success', __('Role created successfully.'));
    }

    public function edit(Role $role): View
    {
        $role->load('permissions');

        return view('admin.roles.edit', [
            'role' => $role,
            'permissionGroups' => $this->permissionGroups(),
            'selected' => $role->permissions->pluck('name')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $this->validateRole($request, $role);

        $previous = [
            'name' => $role->name,
            'permissions' => $role->permissions()->pluck('name')->all(),
        ];

        $role->fill(['name' => $validated['name']])->save();

        $permissions = $validated['permissions'] ?? [];
        $role->syncPermissions($permissions);

        AuditLogger::log(
            'updated',
            Role::class,
            $role->id,
            $previous,
            ['name' => $role->name, 'permissions' => $permissions],
            __('Role permissions updated')
        );

        return redirect()
            ->route('admin.roles.index')
            ->with('success', __('Role updated successfully.'));
    }

    /**
     * Roles that still own users are kept: removing them would silently strip
     * access from active accounts. Reassign those users first.
     */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        if ($request->user()->hasRole($role->name)) {
            return back()->with('error', __('You cannot delete the role assigned to your own account.'));
        }

        if ($role->users()->exists()) {
            return back()->with('error', __(':name is assigned to :count users. Reassign them before deleting the role.', [
                'name' => $role->name,
                'count' => $role->users()->count(),
            ]));
        }

        $snapshot = [
            'name' => $role->name,
            'permissions' => $role->permissions()->pluck('name')->all(),
        ];
        $roleId = $role->id;
        $role->delete();

        AuditLogger::log('deleted', Role::class, $roleId, $snapshot, null, __('Role deleted'));

        return redirect()
            ->route('admin.roles.index')
            ->with('success', __('Role deleted successfully.'));
    }

    /**
     * Group every web-guard permission by module for the checkbox grid.
     *
     * @return array<string, list<array{name: string, label: string}>>
     */
    protected function permissionGroups(): array
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();

        $groups = [];

        foreach ($permissions as $permission) {
            $module = Str::before($permission->name, '.');
            $action = Str::after($permission->name, '.');
            $moduleLabel = __($this->modules[$module] ?? Str::headline($module));

            $groups[$moduleLabel][] = [
                'name' => $permission->name,
                'label' => __($this->actions[$action] ?? Str::headline($action)),
            ];
        }

        return $groups;
    }

    /**
     * Validation shared by create and update. Only web-guard permissions that
     * really exist can be attached to a role.
     */
    protected function validateRole(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:125',
                Rule::unique('roles', 'name')->where(
                    fn ($query) => $query->where('guard_name', 'web')
                )->ignore($role?->id),
            ],
            'permissions' => ['array'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where(
                    fn ($query) => $query->where('guard_name', 'web')
                ),
            ],
        ]);
    }
}
