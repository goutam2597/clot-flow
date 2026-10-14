<x-app-layout>
    <x-slot:title>{{ __('New user') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('New user')" :subtitle="__('Create an account and assign the role that defines what this person can reach.')"
                     :back="route('admin.users.index')" />

    @include('admin.users._form', ['mode' => 'create'])
</x-app-layout>
