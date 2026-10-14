<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\Language;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * User administration.
 *
 * Accounts anchor the audit trail and every financial document, so writes are
 * audited, passwords are only ever handled as hashes, and accounts that already own
 * transactions can be deactivated instead of deleted.
 */
class UserController extends Controller
{
    protected int $perPage = 15;

    /**
     * Account statuses offered in the UI (mirrors the users.status column).
     */
    public static function statuses(): array
    {
        return [
            'active' => __('Active'),
            'inactive' => __('Inactive'),
            'suspended' => __('Suspended'),
        ];
    }

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'role' => $request->query('role'),
            'branch_id' => $request->query('branch_id'),
            'status' => $request->query('status'),
        ];

        $users = User::query()
            ->with(['branch', 'roles'])
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $term = '%' . $filters['q'] . '%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($filters['role'], fn ($query) => $query->whereHas(
                'roles',
                fn ($role) => $role->where('name', $filters['role'])
            ))
            ->when($filters['branch_id'], fn ($query) => $query->where('branch_id', $filters['branch_id']))
            ->when($filters['status'], fn ($query) => $query->where('status', $filters['status']))
            ->orderBy('name')
            ->paginate($this->perPage)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->pluck('name', 'name'),
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'statuses' => self::statuses(),
            'filters' => $filters,
            'stats' => [
                'total' => User::count(),
                'active' => User::where('status', 'active')->count(),
                'restricted' => User::whereIn('status', ['inactive', 'suspended'])->count(),
                'roles' => Role::count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', $this->formData(new User([
            'status' => 'active',
            'theme' => 'light',
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateUser($request);

        $attributes = collect($validated)->except(['password', 'role'])->all();
        $attributes['password'] = Hash::make($validated['password']);

        $user = new User();
        $user->fill($attributes)->save();
        $user->assignRole($validated['role']);

        AuditLogger::log(
            'created',
            User::class,
            $user->id,
            null,
            array_merge($this->snapshot($user), ['role' => $validated['role']]),
            __('User created')
        );

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('User created successfully.'));
    }

    public function edit(User $user): View
    {
        $user->load(['branch', 'roles']);

        return view('admin.users.edit', $this->formData($user));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validateUser($request, $user);

        $old = array_merge($this->snapshot($user), ['role' => $user->roles->pluck('name')->first()]);

        $attributes = collect($validated)->except(['password', 'role'])->all();
        if (filled($validated['password'] ?? null)) {
            $attributes['password'] = Hash::make($validated['password']);
        }

        $user->fill($attributes)->save();
        $user->syncRoles([$validated['role']]);

        AuditLogger::log(
            'updated',
            User::class,
            $user->id,
            $old,
            array_merge($this->snapshot($user->refresh()), ['role' => $validated['role']]),
            __('User updated')
        );

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('User updated successfully.'));
    }

    /**
     * Activate / deactivate / suspend an account. Never applied to the current user.
     */
    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys(self::statuses()))],
        ]);

        if ($user->id === $request->user()->id) {
            return back()->with('error', __('You cannot change the status of your own account.'));
        }

        $previous = $user->status;
        $user->fill(['status' => $validated['status']])->save();

        AuditLogger::log(
            'status_changed',
            User::class,
            $user->id,
            ['status' => $previous],
            ['status' => $user->status],
            __('User status changed')
        );

        return back()->with('success', __(':name is now :status.', [
            'name' => $user->name,
            'status' => self::statuses()[$user->status],
        ]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', __('You cannot delete your own account.'));
        }

        $blockers = [];
        if (Sale::where('user_id', $user->id)->exists()) {
            $blockers[] = __('sales');
        }
        if (Purchase::where('user_id', $user->id)->exists()) {
            $blockers[] = __('purchases');
        }
        if (Expense::where('user_id', $user->id)->exists()) {
            $blockers[] = __('expenses');
        }
        if (CashRegisterSession::where('user_id', $user->id)->exists()) {
            $blockers[] = __('register sessions');
        }

        if ($blockers !== []) {
            return back()->with('error', __('This user owns existing :records and cannot be deleted. Deactivate the account instead.', [
                'records' => implode(', ', $blockers),
            ]));
        }

        $snapshot = $this->snapshot($user);
        $userId = $user->id;
        $user->delete();

        AuditLogger::log('deleted', User::class, $userId, $snapshot, null, __('User deleted'));

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('User deleted successfully.'));
    }

    /**
     * Shared options for the create / edit forms.
     */
    protected function formData(User $user): array
    {
        return [
            'user' => $user,
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'roles' => Role::orderBy('name')->get(),
            'locales' => Language::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name', 'code'),
            'themes' => ['light' => __('Light'), 'dark' => __('Dark')],
            'statuses' => self::statuses(),
        ];
    }

    /**
     * Validation shared by create and update. Passwords must be confirmed and are
     * optional when editing; they are always stored as hashes.
     */
    protected function validateUser(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where(fn ($query) => $query->where('guard_name', 'web'))],
            'status' => ['required', 'string', Rule::in(array_keys(self::statuses()))],
            'locale' => ['nullable', 'string', 'max:10'],
            'theme' => ['required', 'string', Rule::in(['light', 'dark'])],
            'password' => [
                $user ? 'nullable' : 'required',
                'confirmed',
                Password::defaults(),
            ],
        ], [], [
            'branch_id' => __('branch'),
            'role' => __('role'),
        ]);
    }

    /**
     * Auditable, non-sensitive representation of a user.
     */
    protected function snapshot(User $user): array
    {
        return $user->only(['name', 'email', 'phone', 'branch_id', 'status', 'locale', 'theme']);
    }
}
