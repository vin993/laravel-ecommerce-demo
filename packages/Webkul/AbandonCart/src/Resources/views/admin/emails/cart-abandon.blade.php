@component('shop::emails.layout')
   <div style="margin-bottom: 34px;">
        <p style="font-weight: bold;font-size: 20px;color: #121A26;line-height: 24px;margin-bottom: 24px">
            @lang('shop::app.emails.dear', ['customer_name' => $cart->customer_first_name]), 👋
        </p>
    </div>

    <p style="font-size: 16px;color: #384860;line-height: 24px;margin-bottom: 40px">
        @lang('abandon_cart::app.admin.customers.abandon-cart.mail.content')
    </p>

    <div style="padding: 30px;">
        <div>
            <table style="overflow-x: auto; border-collapse: collapse ;border-spacing: 0;width: 100%">
                <thead>
                    <tr style="background-color: #f2f2f2">
                        <th style="text-align: left;padding: 8px">
                            @lang('abandon_cart::app.admin.customers.abandon-cart.sku') 
                        </th>

                        <th style="text-align: left;padding: 8px">
                            @lang('abandon_cart::app.admin.customers.abandon-cart.product-name') 
                        </th>

                        <th style="text-align: left;padding: 8px">
                            @lang('abandon_cart::app.admin.customers.abandon-cart.price') 
                        </th>

                        <th style="text-align: left;padding: 8px">
                            @lang('abandon_cart::app.admin.customers.abandon-cart.qty') 
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($cart->items as $item)
                        <tr>
                            <td data-value="@lang('abandon_cart::app.admin.customers.abandon-cart.sku') " style="text-align: left;padding: 8px">
                               {{ $item->sku }}
                           </td>
                            <td data-value="@lang('abandon_cart::app.admin.customers.abandon-cart.product-name') " style="text-align: left;padding: 8px">
                                {{ $item->name }}

                                @if (isset($item->additional['attributes']))
                                    <div class="item-options">
                                        @foreach ($item->additional['attributes'] as $attribute)
                                            <b>{{ $attribute['attribute_name'] }} : </b>{{ $attribute['option_label'] }}</br>
                                        @endforeach
                                    </div>
                                @endif
                            </td>

                            <td data-value="@lang('abandon_cart::app.admin.customers.abandon-cart.price') " style="text-align: left;padding: 8px">
                                {{ core()->formatPrice($item->price, $cart->cart_currency_code) }}
                            </td>

                            <td data-value="@lang('abandon_cart::app.admin.customers.abandon-cart.qty') " style="text-align: left;padding: 8px">
                                {{ $item->quantity }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="font-size: 16px;color: #242424;line-height: 30px;float: right;width: 40%;margin-top: 20px;">
            <div>
                <span>
                    @lang('abandon_cart::app.admin.customers.abandon-cart.subtotal') 
                </span>

                <span style="float: right;">
                    {{ core()->formatPrice($cart->sub_total, $cart->cart_currency_code) }}
                </span>
            </div>

            <div>
                <span>
                    @lang('abandon_cart::app.admin.customers.abandon-cart.tax') 
                </span>

                <span style="float: right;">
                    {{ core()->formatPrice($cart->tax_amount, $cart->cart_currency_code) }}
                </span>
            </div>

            <div style="font-weight: bold">
                <span>
                    @lang('abandon_cart::app.admin.customers.abandon-cart.grand-total') 
                </span>

                <span style="float: right;">
                    {{ core()->formatPrice($cart->grand_total, $cart->cart_currency_code) }}
                </span>
            </div>
        </div>

        <div style="margin-top: 65px;font-size: 16px;color: #5E5E5E;line-height: 24px;display: inline-block">
            <p style="font-size: 16px;color: #5E5E5E;line-height: 24px;">
                @lang('abandon_cart::app.admin.customers.abandon-cart.mail.checkout-msg')

                <a href="{{ route('shop.checkout.cart.index')}}" style="background: #EF7162; color: #ffffff;padding: 6px 12px;border-radius: 3px;text-decoration: none;">
                    @lang('abandon_cart::app.admin.customers.abandon-cart.mail.place-order')
                </a>
            </p>

            <p style="font-size: 16px;color: #5E5E5E;line-height: 24px;">
                @lang('abandon_cart::app.admin.customers.abandon-cart.mail.thanks')
            </p>

            <p style="font-size: 16px;color: #5E5E5E;line-height: 24px;margin-top: 20px;">
                If you need any kind of help please contact us at <a href="mailto:customerservice@maddparts.com" style="color: #E13124; text-decoration: none;">customerservice@maddparts.com</a>
            </p>
        </div>
    </div>
@endcomponent