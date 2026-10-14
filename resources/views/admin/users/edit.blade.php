<x-app-layout>
    <x-slot:title>{{ __('Edit :name', ['name' => $user->name]) }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="$user->name" :subtitle="__('Edit account details, role and status.')"
                     :back="route('admin.users.index')" />

    @include('admin.users._form', ['mode' => 'edit'])
</x-app-layout>
