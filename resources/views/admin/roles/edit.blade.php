<x-app-layout>
    <x-slot:title>{{ __('Edit :name', ['name' => $role->name]) }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Edit role')" :subtitle="$role->name" :back="route('admin.roles.index')" />

    @include('admin.roles._form', ['mode' => 'edit'])
</x-app-layout>
