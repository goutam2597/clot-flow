<x-app-layout>
    <x-slot:title>{{ __('Expenses') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Expenses')" :subtitle="__('Recording an expense posts a balanced journal entry immediately, so reports and the ledger always agree.')">
        <x-slot:actions>
            <x-ui.button :href="route('expenses.categories')" variant="outline" icon="tag">{{ __('Categories') }}</x-ui.button>
            @can('expenses.manage')
                <x-ui.button icon="plus" data-modal-open="#record-expense">{{ __('Record expense') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="wallet" :label="__('Total in filter')" :value="\App\Support\Formatter::money($totals['amount'])"
                            :hint="trans_choice(':count record|:count records', $totals['count'], ['count' => \App\Support\Formatter::number($totals['count'])])" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="clock" :label="__('This month')" :value="\App\Support\Formatter::money($monthTotal)" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="calculator" :label="__('Recoverable tax')" :value="\App\Support\Formatter::money($totals['tax'])" />
        </div>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <form method="GET" action="{{ route('expenses.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 250px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search'] ?? ''"
                                    :placeholder="__('Reference, payee or description')" />
                    </div>
                    <div style="flex: 1 1 180px;">
                        <x-ui.select name="category_id" :label="__('Category')" :options="$categories" :value="$filters['category_id'] ?? ''"
                                     :placeholder="__('All categories')" />
                    </div>
                    <div style="flex: 1 1 180px;">
                        <x-ui.select name="payment_method_id" :label="__('Payment method')" :options="$paymentMethods"
                                     :value="$filters['payment_method_id'] ?? ''" :placeholder="__('Any method')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="from" type="date" :label="__('From')" :value="$filters['from'] ?? ''" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="to" type="date" :label="__('Until')" :value="$filters['to'] ?? ''" />
                    </div>
                </div>
            </div>
            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('expenses.index')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Reference') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Category') }}</th>
                    <th>{{ __('Payee') }}</th>
                    <th>{{ __('Paid via') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                    <th>{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($expenses as $expense)
                    <tr>
                        <td>
                            <div class="fw-semibold font-mono">{{ $expense->reference }}</div>
                            <div class="fs-xs text-muted">{{ $expense->branch?->name ?? '—' }}</div>
                        </td>
                        <td class="text-nowrap">{{ \App\Support\Formatter::date($expense->expense_date) }}</td>
                        <td>{{ $expense->category?->name ?? '—' }}</td>
                        <td>
                            <div>{{ $expense->payee ?: '—' }}</div>
                            @if ($expense->description)
                                <div class="fs-xs text-muted">{{ $expense->description }}</div>
                            @endif
                        </td>
                        <td class="fs-sm">{{ $expense->paymentMethod?->name ?? '—' }}</td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($expense->amount) }}</td>
                        <td>
                            <x-ui.badge :type="$expense->status === 'approved' ? 'success' : 'warning'">
                                {{ __(ucfirst($expense->status)) }}
                            </x-ui.badge>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-ui.empty-state icon="wallet" :title="__('No expenses match these filters')"
                                            :text="__('Record an expense and it will post to the ledger immediately.')">
                                @can('expenses.manage')
                                    <x-ui.button icon="plus" data-modal-open="#record-expense">{{ __('Record expense') }}</x-ui.button>
                                @endcan
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $expenses->links('partials.pagination') !!}

    @can('expenses.manage')
        <x-ui.modal id="record-expense" :title="__('Record an expense')" size="lg">
            <form method="POST" action="{{ route('expenses.store') }}" data-prevent-double>
                @csrf
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 1 1 220px;">
                        <x-ui.input name="amount" type="number" step="0.01" min="0.01" :label="__('Amount')" required
                                    :hint="__('Exclusive of tax unless your jurisdiction requires otherwise.')" />
                    </div>
                    <div style="flex: 1 1 180px;">
                        <x-ui.input name="tax_amount" type="number" step="0.01" min="0" :label="__('Tax')" />
                    </div>
                    <div style="flex: 1 1 180px;">
                        <x-ui.input name="expense_date" type="date" :label="__('Expense date')" required
                                    :value="old('expense_date', now()->toDateString())" />
                    </div>
                    <div style="flex: 1 1 220px;">
                        <x-ui.select name="expense_category_id" :label="__('Category')" :options="$categories"
                                     :value="old('expense_category_id')" :placeholder="__('Uncategorised')" />
                    </div>
                    <div style="flex: 1 1 200px;">
                        <x-ui.select name="branch_id" :label="__('Branch')" :options="$branches"
                                     :value="old('branch_id', auth()->user()->branch_id)" :placeholder="__('Your branch')" />
                    </div>
                    <div style="flex: 1 1 200px;">
                        <x-ui.select name="payment_method_id" :label="__('Paid via')" :options="$paymentMethods"
                                     :value="old('payment_method_id')" :placeholder="__('Not specified')" />
                    </div>
                    <div style="flex: 1 1 100%;">
                        <x-ui.select name="account_id" :label="__('Expense account')" :options="$accounts"
                                     :value="old('account_id')" :placeholder="__('Use the default operating expense account')"
                                     :hint="__('Leave blank to post to the default expense account in the chart of accounts.')" />
                    </div>
                    <div style="flex: 1 1 240px;">
                        <x-ui.input name="payee" :label="__('Payee')" :value="old('payee')" />
                    </div>
                    <div style="flex: 1 1 100%;">
                        <x-ui.input name="description" :label="__('Description')" :value="old('description')" />
                    </div>
                </div>

                <x-ui.alert type="info" class="mt-4">
                    {{ __('Saving posts a balanced journal entry (debit expense, credit cash) in the same transaction. The expense cannot be half-saved.') }}
                </x-ui.alert>

                <div class="flex gap-3 mt-4">
                    <x-ui.button type="submit" icon="check">{{ __('Record expense') }}</x-ui.button>
                    <x-ui.button variant="outline" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan
</x-app-layout>
