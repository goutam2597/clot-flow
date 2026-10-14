@php
    $can = fn ($perm) => auth()->check() && auth()->user()->can($perm);

    /*
     * Is the current screen part of this link?
     *
     * A bare wildcard is not enough on its own. `sales.*` also matches
     * `sales.returns`, so on the Returns screen both "Sales" and "Returns &
     * Refunds" lit up at once — two highlighted items in a navigation that
     * exists precisely to answer "where am I". Any link with a more specific
     * sibling therefore has to name what it gives up.
     */
    $currentRoute = request()->route()?->getName();

    $linkActive = function (array $patterns, array $except = []) use ($currentRoute) {
        if ($currentRoute === null) {
            return false;
        }

        foreach ($except as $pattern) {
            if (\Illuminate\Support\Str::is($pattern, $currentRoute)) {
                return false;
            }
        }

        foreach ($patterns as $pattern) {
            if (\Illuminate\Support\Str::is($pattern, $currentRoute)) {
                return true;
            }
        }

        return false;
    };

    /** A section heading is emphasised when anything inside it is current. */
    $sectionHas = function (array $patterns) use ($currentRoute) {
        if ($currentRoute === null) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (\Illuminate\Support\Str::is($pattern, $currentRoute)) {
                return true;
            }
        }

        return false;
    };
@endphp

<aside class="sidebar">
    <div class="sidebar-brand">
        @include('partials.brand-logo', ['variant' => 'sidebar'])
    </div>

    <nav class="sidebar-nav" aria-label="{{ __('Main navigation') }}">
        @if ($can('dashboard.view'))
        <div class="sidebar-section">
            <a href="{{ route('dashboard') }}" class="sidebar-link {{ $linkActive(['dashboard']) ? 'active' : '' }}">
                <x-ui.icon name="dashboard" /><span class="sidebar-link-label">{{ __('Dashboard') }}</span>
            </a>
        </div>
        @endif

        @if ($can('pos.access') || $can('sales.view'))
        <div class="sidebar-section">
            <div class="sidebar-section-title">{{ __('Sales') }}</div>
            @if ($can('pos.access'))
            <a href="{{ route('pos.index') }}" class="sidebar-link {{ $linkActive(['pos.*']) ? 'active' : '' }}">
                <x-ui.icon name="pos" /><span class="sidebar-link-label">{{ __('Point of Sale') }}</span>
            </a>
            @endif
            @if ($can('sales.view'))
            <a href="{{ route('sales.index') }}" class="sidebar-link {{ $linkActive(['sales.*'], ['sales.returns*']) ? 'active' : '' }}">
                <x-ui.icon name="receipt" /><span class="sidebar-link-label">{{ __('Sales') }}</span>
            </a>
            <a href="{{ route('sales.returns') }}" class="sidebar-link {{ $linkActive(['sales.returns*']) ? 'active' : '' }}">
                <x-ui.icon name="refresh" /><span class="sidebar-link-label">{{ __('Returns & Refunds') }}</span>
            </a>
            @endif
        </div>
        @endif

        @if ($can('products.view') || $can('inventory.view'))
        <div class="sidebar-section">
            <div class="sidebar-section-title">{{ __('Catalog & Stock') }}</div>
            @if ($can('products.view'))
            {{-- Categories are a separate route but the same job, so the Products item
                 owns both; otherwise the Categories screen has no highlight. --}}
            <a href="{{ route('products.index') }}" class="sidebar-link {{ $linkActive(['products.*', 'categories.*']) ? 'active' : '' }}">
                <x-ui.icon name="box" /><span class="sidebar-link-label">{{ __('Products') }}</span>
            </a>
            @endif
            @if ($can('inventory.view'))
            <a href="{{ route('inventory.index') }}" class="sidebar-link {{ $linkActive(['inventory.*'], ['inventory.transfers*']) ? 'active' : '' }}">
                <x-ui.icon name="package" /><span class="sidebar-link-label">{{ __('Inventory') }}</span>
            </a>
            <a href="{{ route('inventory.transfers') }}" class="sidebar-link {{ $linkActive(['inventory.transfers*']) ? 'active' : '' }}">
                <x-ui.icon name="truck" /><span class="sidebar-link-label">{{ __('Stock Transfers') }}</span>
            </a>
            @endif
        </div>
        @endif

        @if ($can('purchases.view') || $can('customers.view') || $can('suppliers.view'))
        <div class="sidebar-section">
            <div class="sidebar-section-title">{{ __('Contacts') }}</div>
            @if ($can('customers.view'))
            <a href="{{ route('customers.index') }}" class="sidebar-link {{ $linkActive(['customers.*']) ? 'active' : '' }}">
                <x-ui.icon name="users" /><span class="sidebar-link-label">{{ __('Customers') }}</span>
            </a>
            @endif
            @if ($can('suppliers.view'))
            <a href="{{ route('suppliers.index') }}" class="sidebar-link {{ $linkActive(['suppliers.*']) ? 'active' : '' }}">
                <x-ui.icon name="supplier" /><span class="sidebar-link-label">{{ __('Suppliers') }}</span>
            </a>
            @endif
            @if ($can('purchases.view'))
            <a href="{{ route('purchases.index') }}" class="sidebar-link {{ $linkActive(['purchases.*']) ? 'active' : '' }}">
                <x-ui.icon name="truck" /><span class="sidebar-link-label">{{ __('Purchases') }}</span>
            </a>
            @endif
        </div>
        @endif

        @if ($can('accounting.view') || $can('expenses.view') || $can('invoices.view'))
        <div class="sidebar-section">
            <div class="sidebar-section-title">{{ __('Finance') }}</div>
            @if ($can('invoices.view'))
            <a href="{{ route('invoices.index') }}" class="sidebar-link {{ $linkActive(['invoices.*']) ? 'active' : '' }}">
                <x-ui.icon name="file" /><span class="sidebar-link-label">{{ __('Invoices') }}</span>
            </a>
            @endif
            @if ($can('expenses.view'))
            <a href="{{ route('expenses.index') }}" class="sidebar-link {{ $linkActive(['expenses.*']) ? 'active' : '' }}">
                <x-ui.icon name="wallet" /><span class="sidebar-link-label">{{ __('Expenses') }}</span>
            </a>
            @endif
            @if ($can('accounting.view'))
            <a href="{{ route('accounting.index') }}" class="sidebar-link {{ $linkActive(['accounting.*']) ? 'active' : '' }}">
                <x-ui.icon name="calculator" /><span class="sidebar-link-label">{{ __('Accounting') }}</span>
            </a>
            @endif
        </div>
        @endif

        @if ($can('reports.view'))
        <div class="sidebar-section">
            <div class="sidebar-section-title">{{ __('Insights') }}</div>
            <a href="{{ route('reports.index') }}" class="sidebar-link {{ $linkActive(['reports.*']) ? 'active' : '' }}">
                <x-ui.icon name="chart" /><span class="sidebar-link-label">{{ __('Reports') }}</span>
            </a>
        </div>
        @endif

        @if ($can('users.manage') || $can('settings.manage') || $can('roles.manage'))
        <div class="sidebar-section">
            <div class="sidebar-section-title">{{ __('Administration') }}</div>
            @if ($can('branches.manage'))
            <a href="{{ route('branches.index') }}" class="sidebar-link {{ $linkActive(['branches.*']) ? 'active' : '' }}">
                <x-ui.icon name="warehouse" /><span class="sidebar-link-label">{{ __('Branches') }}</span>
            </a>
            @endif
            @if ($can('users.manage'))
            <a href="{{ route('admin.users.index') }}" class="sidebar-link {{ $linkActive(['admin.users.*']) ? 'active' : '' }}">
                <x-ui.icon name="user" /><span class="sidebar-link-label">{{ __('Users') }}</span>
            </a>
            @endif
            @if ($can('roles.manage'))
            <a href="{{ route('admin.roles.index') }}" class="sidebar-link {{ $linkActive(['admin.roles.*']) ? 'active' : '' }}">
                <x-ui.icon name="shield" /><span class="sidebar-link-label">{{ __('Roles & Permissions') }}</span>
            </a>
            @endif
            @if ($can('settings.manage'))
            {{-- The sync log is an operations screen reached from Settings, so it
                 highlights the same item rather than none of them. --}}
            <a href="{{ route('admin.settings.index') }}" class="sidebar-link {{ $linkActive(['admin.settings.*', 'sync.*']) ? 'active' : '' }}">
                <x-ui.icon name="settings" /><span class="sidebar-link-label">{{ __('Settings') }}</span>
            </a>
            <a href="{{ route('admin.languages.index') }}" class="sidebar-link {{ $linkActive(['admin.languages.*']) ? 'active' : '' }}">
                <x-ui.icon name="globe" /><span class="sidebar-link-label">{{ __('Languages') }}</span>
            </a>
            @endif
            @if ($can('audit.view'))
            <a href="{{ route('admin.audit.index') }}" class="sidebar-link {{ $linkActive(['admin.audit.*']) ? 'active' : '' }}">
                <x-ui.icon name="briefcase" /><span class="sidebar-link-label">{{ __('Audit Logs') }}</span>
            </a>
            @endif
        </div>
        @endif
    </nav>
</aside>
<div class="sidebar-backdrop" data-sidebar-close></div>

