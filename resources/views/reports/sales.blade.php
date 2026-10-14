<x-app-layout>
    <x-slot:title>{{ __('Sales analysis') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Sales analysis')" :subtitle="__('Revenue, best sellers and how customers paid.')" :back="route('reports.index')">
        <x-slot:actions>
            <x-ui.button variant="outline" icon="print" onclick="window.print()">{{ __('Print') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="card mb-4">
        <form method="GET" action="{{ route('reports.sales') }}">
            <div class="card-body">
                <div class="field-bar">
                    <div style="flex: 1 1 190px;">
                        <x-ui.input name="from" type="date" id="from" :label="__('From')" :value="$filters['from_raw']" />
                    </div>
                    <div style="flex: 1 1 190px;">
                        <x-ui.input name="to" type="date" id="to" :label="__('Until')" :value="$filters['to_raw']" />
                    </div>
                    <div>
                        <x-ui.button type="submit" icon="filter">{{ __('Apply') }}</x-ui.button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="trending-up" :label="__('Revenue')" :value="\App\Support\Formatter::money($summary['revenue'])"
                            :hint="trans_choice(':count sale|:count sales', $summary['count'], ['count' => \App\Support\Formatter::number($summary['count'])])" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="money" :label="__('Collected')" :value="\App\Support\Formatter::money($summary['collected'])" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="alert" :label="__('Outstanding')" :value="\App\Support\Formatter::money($summary['due'])" />
        </div>
    </div>

    <div class="flex gap-4" style="flex-wrap: wrap; align-items: flex-start;">
        {{-- Daily --}}
        <div style="flex: 2 1 460px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Daily performance') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('Day') }}</th>
                                <th class="num">{{ __('Invoices') }}</th>
                                <th class="num">{{ __('Revenue') }}</th>
                                <th class="num">{{ __('Gross profit') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($daily as $day)
                                <tr>
                                    <td class="text-nowrap">{{ \App\Support\Formatter::date($day->day) }}</td>
                                    <td class="num financial">{{ \App\Support\Formatter::number($day->invoices, 0) }}</td>
                                    <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($day->revenue) }}</td>
                                    <td class="num financial">{{ \App\Support\Formatter::money($day->profit) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <x-ui.empty-state icon="sales" :title="__('No sales in this period')"
                                                        :text="__('Widen the date range to see daily performance.')" />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {!! $daily->links('partials.pagination') !!}
            </div>
        </div>

        <div style="flex: 1 1 320px;">
            {{-- Top products --}}
            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">{{ __('Top selling products') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="num">{{ __('Qty') }}</th>
                                <th class="num">{{ __('Revenue') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($topProducts as $product)
                                <tr>
                                    <td>{{ $product->product_name }}</td>
                                    <td class="num financial">{{ \App\Support\Formatter::number($product->qty, 2) }}</td>
                                    <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($product->revenue) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ __('No sales in this period.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Payment mix --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Payment mix') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('Method') }}</th>
                                <th class="num">{{ __('Payments') }}</th>
                                <th class="num">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($paymentMix as $row)
                                <tr>
                                    <td>{{ $row->paymentMethod?->name ?? '—' }}</td>
                                    <td class="num financial">{{ \App\Support\Formatter::number($row->payments, 0) }}</td>
                                    <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($row->total) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ __('No payments recorded in this period.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
