<x-app-layout>
    <x-slot:title>{{ __('New role') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('New role')" :subtitle="__('Name the role and choose exactly what it may reach.')"
                     :back="route('admin.roles.index')" />

    @include('admin.roles._form', ['mode' => 'create'])
</x-app-layout>
