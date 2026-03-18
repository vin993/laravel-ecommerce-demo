<x-admin::layouts>
    <!-- Page Title-->
    <x-slot:title>
        @lang('abandon_cart::app.admin.customers.abandon-cart.title')
    </x-slot>

    <div class="flex items-center justify-between">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            @lang('abandon_cart::app.admin.customers.abandon-cart.title')
        </p>
    </div>
  
    {!! view_render_event('bagisto.admin.customers.abandon-cart.list.before') !!}

    <x-admin::datagrid src="{{ route('admin.customers.abandon-cart.index') }}" :isMultiRow="true"/>

    {!! view_render_event('bagisto.admin.customers.abandon-cart.list.before') !!}

</x-admin::layouts>