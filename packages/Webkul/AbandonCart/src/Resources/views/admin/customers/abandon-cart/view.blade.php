<x-admin::layouts>
   <!-- Page Title -->
   <x-slot:title>
       @lang('abandon_cart::app.admin.customers.abandon-cart.view-title', ['abandon_cart_id' => $cart->id])
    </x-slot>

    <div class="grid">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <div class="flex items-center gap-2.5">
                <p class="text-xl font-bold leading-6 text-gray-800 dark:text-white">
                    @lang('abandon_cart::app.admin.customers.abandon-cart.view-title', ['abandon_cart_id' => $cart->id])
                </p>
            </div>    

            <div class="flex items-center gap-2.5">
                <a
                   href="{{ route('admin.customers.abandon-cart.index') }}"
                   class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                >
                    @lang('admin::app.customers.customers.view.back-btn')
                </a>
            
                <a 
                    href="{{ route('admin.sales.abandon-cart.mail', $cart->id) }}" 
                    class="primary-button"
                >
                    @lang('abandon_cart::app.admin.datagrid.send-mail')
                </a>
            </div>
        </div>
    </div>

    <!--Content -->
    <div class="mt-3.5 flex gap-2.5 max-xl:flex-wrap">
        <!-- Left Component -->
        <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
            <div class="box-shadow rounded bg-white dark:bg-gray-900">
                <div class="flex justify-between p-4">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                        @lang('abandon_cart::app.admin.customers.abandon-cart.products-info')
                    </p>   
                </div>

                <!-- Product Details -->
                <div class="relative my-3 px-3 overflow-x-auto">
                    <table class="w-full text-sm text-left max-w-[800px]">
                        <thead class="text-sm text-gray-600 dark:text-gray-300 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                            <tr class="m-3">
                                <th class="p-3">
                                    @lang('abandon_cart::app.admin.customers.abandon-cart.sku')
                                </th>

                                <th class="p-3">
                                    @lang('abandon_cart::app.admin.customers.abandon-cart.product-name')
                                </th>

                                <th class="p-3">
                                    @lang('abandon_cart::app.admin.customers.abandon-cart.qty')
                                </th>

                                <th class="p-3">
                                    @lang('abandon_cart::app.admin.customers.abandon-cart.price')
                                </th>

                                <th class="p-3">
                                    @lang('abandon_cart::app.admin.customers.abandon-cart.subtotal')
                                </th>

                                <th class="p-3">
                                    @lang('abandon_cart::app.admin.customers.abandon-cart.tax-percent')
                                </th>

                                <th class="p-3">
                                    @lang('abandon_cart::app.admin.customers.abandon-cart.tax-amount')
                                </th>

                                @if ($cart->base_discount_amount > 0)
                                    <th class="p-3">
                                        @lang('abandon_cart::app.admin.customers.abandon-cart.discount-amount')
                                    </th>
                                @endif

                                <th class="p-3">
                                    @lang('abandon_cart::app.admin.customers.abandon-cart.grand-total')
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($cart->items as $item)
                                <tr class="bg-white dark:bg-gray-900 border-b transition-all hover:bg-gray-50 dark:hover:bg-gray-950 dark:border-gray-800">
                                    <td class="p-3 text-gray-600 dark:text-gray-300">
                                        {{ $item->sku }}
                                    </td>

                                    <td class="p-3 text-gray-600 dark:text-gray-300">
                                        {{ $item->name }}

                                        @if (isset($item->additional['attributes']))
                                            <div class="item-options">
                                                @foreach ($item->additional['attributes'] as $attribute)
                                                    <b>{{ $attribute['attribute_name'] }} :
                                                    </b>{{ $attribute['option_label'] }}</br>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>

                                    <td class="p-3 text-gray-600 dark:text-gray-300">
                                    {{ $item->quantity }}
                                    </td>

                                    <td class="p-3 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($item->base_price) }}
                                    </td>

                                    <td class="p-3 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($item->base_total) }}
                                    </td>

                                    <td class="p-3 text-gray-600 dark:text-gray-300">
                                        {{ $item->tax_percent }}%
                                    </td>

                                    <td class="p-3 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($item->base_tax_amount) }}
                                    </td>

                                    @if ($cart->base_discount_amount > 0)
                                        <td class="p-3 text-gray-600 dark:text-gray-300">
                                            {{ core()->formatBasePrice($item->base_discount_amount) }}
                                        </td>
                                    @endif

                                    <td class="p-3 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($item->base_total + $item->base_tax_amount - $item->base_discount_amount) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                   </table>
                </div>

                <div class="mt-4 flex w-full justify-end gap-2.5 p-4">
                    <div class="flex flex-col gap-y-1.5">
                        <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                            @lang('admin::app.sales.orders.view.summary-sub-total')
                        </p>

                        <p class="!leading-5 text-gray-600 dark:text-gray-300">
                            @lang('admin::app.sales.orders.view.summary-tax')
                        </p>

                        @if ($haveStockableItems = $cart->haveStockableItems())
                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                @lang('admin::app.sales.orders.view.shipping-and-handling')
                            </p>
                        @endif

                        @if ($cart->base_discount_amount > 0)
                           <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                @lang('admin::app.admin.customers.abandon-cart.discount-amount')

                                @if ($cart->coupon_code)
                                    ({{ $cart->coupon_code }})
                                @endif
                            </p>
                        @endif

                        <p class="text-base font-semibold !leading-5 text-gray-800 dark:text-white">
                            @lang('admin::app.sales.orders.view.summary-grand-total')
                        </p>
                    </div>

                    <div class="flex flex-col gap-y-1.5">
                        <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                            {{ core()->formatBasePrice($cart->base_sub_total) }}
                        </p>

                        <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                            {{ core()->formatBasePrice($cart->base_tax_total) }}
                        </p>

                        @if ($cart->haveStockableItems())
                            <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                @if (isset($cart->selected_shipping_rate))
                                    {{ core()->formatBasePrice($cart->selected_shipping_rate->base_price) }}
                                @else
                                    {{ core()->formatBasePrice($cart->base_shipping_amount) }}
                                @endif
                            </p>
                        @endif

                        @if ($cart->base_discount_amount > 0)
                           <p class="text-gray-600 dark:text-gray-300 font-semibold">
                               {{ core()->formatBasePrice($cart->base_discount_amount) }}
                            </p>
                        @endif

                        <p class="text-base font-semibold !leading-5 text-gray-800 dark:text-white">
                            {{ core()->formatBasePrice($cart->base_grand_total) }}
                        </p>
                    </div>
                </div>
            </div>

            @if (count($mails))
                <div class="box-shadow rounded bg-white dark:bg-gray-900">
                    <div class="flex justify-between p-4">
                        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                            @lang('abandon_cart::app.admin.customers.abandon-cart.view.mail-info') ({{ count($mails) }})
                        </p>   
                    </div>

                    <!-- Product Details -->
                    <div class="relative my-3 px-3 overflow-x-auto">
                        <table class="w-full text-sm text-left max-w-[800px]">
                            <thead class="text-sm text-gray-600 dark:text-gray-300 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                                <tr class="m-3">
                                    <th class="p-3">
                                        @lang('abandon_cart::app.admin.customers.abandon-cart.view.sender-mail')
                                    </th>

                                    <th class="p-3">
                                        @lang('abandon_cart::app.admin.customers.abandon-cart.view.mail-type')
                                    </th>

                                    <th class="p-3">
                                        @lang('abandon_cart::app.admin.customers.abandon-cart.view.create-at')
                                    </th>

                                    <th class="p-3">
                                        @lang('abandon_cart::app.admin.customers.abandon-cart.view.time-ago')
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach ($mails ?? [] as $mail)
                                    <tr class="bg-white dark:bg-gray-900 border-b transition-all hover:bg-gray-50 dark:hover:bg-gray-950 dark:border-gray-800">
                                        <td class="p-3 text-gray-600 dark:text-gray-300">
                                            {{ $mail->sender_mail }}
                                        </td>

                                        <td class="p-3 text-gray-600 dark:text-gray-300">
                                            {{ ucfirst($mail->mail_type) }}
                                        </td>

                                        <td class="p-3 text-gray-600 dark:text-gray-300">
                                            {{ $mail->created_at }}
                                        </td>

                                        <td class="p-3 text-gray-600 dark:text-gray-300">
                                            {{ $mail->created_at->diffForHumans() }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
        
        <!-- Right Component -->
        <div class="flex w-[360px] max-w-full flex-col gap-2 max-sm:w-full">
            <x-admin::accordion>
                <x-slot:header>
                    <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                        @lang('abandon_cart::app.admin.customers.abandon-cart.account-info')
                    </p>
                </x-slot:header>

                <x-slot:content>
                    <div class="pb-4">
                        <div class="flex flex-col gap-1.5">
                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('abandon_cart::app.admin.customers.abandon-cart.customer-name', ['first-name' => $cart->customer_first_name, 'last-name' => $cart->customer_last_name])
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5 mt-4">
                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('abandon_cart::app.admin.customers.abandon-cart.email',['email' => $cart->customer_email])
                            </p>
                        </div>
                    </div>
                </x-slot:content>
            </x-admin::accordion>

            <x-admin::accordion>
               <x-slot:header>
                    <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                        @lang('abandon_cart::app.admin.customers.abandon-cart.cart-info')
                    </p>
                </x-slot:header>

                <x-slot:content>
                    <div class="pb-4">
                        <div class="flex flex-col gap-1.5">
                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('abandon_cart::app.admin.customers.abandon-cart.date',['date' => $cart->created_at])
                            </p>
                        </div>
                        
                        <div class="flex flex-col gap-1.5 mt-4">
                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('abandon_cart::app.admin.customers.abandon-cart.mail-sent')

                                @if ($cart->is_mail_sent)
                                    @lang('abandon_cart::app.admin.datagrid.yes')
                                @else
                                    @lang('abandon_cart::app.admin.datagrid.no')
                                @endif
                            </p>
                        </div>
                    </div>
                </x-slot:content>
            </x-admin::accordion>
        </div>
    </div>
</x-admin::layouts>