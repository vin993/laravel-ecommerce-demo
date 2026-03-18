<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.sales.orders.view.title', ['order_id' => $order->increment_id])
    </x-slot>

    <!-- Header -->
    <div class="grid">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            {!! view_render_event('bagisto.admin.sales.order.title.before', ['order' => $order]) !!}

            <div class="flex items-center gap-2.5">
                <p class="text-xl font-bold leading-6 text-gray-800 dark:text-white">
                    @lang('admin::app.sales.orders.view.title', ['order_id' => $order->increment_id])
                </p>

                <!-- Order Status -->
                <span class="label-{{ $order->status }} text-sm mx-1.5">
                    @lang("admin::app.sales.orders.view.$order->status")
                </span>

                @if($order->pending_payment_amount > 0)
                <span class="text-sm bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 px-3 py-1 rounded-full font-semibold">
                    Payment Due: ${{ number_format($order->pending_payment_amount, 2) }}
                </span>
                @endif
            </div>

            {!! view_render_event('bagisto.admin.sales.order.title.after', ['order' => $order]) !!}

            <div class="flex gap-2">
                <!-- Edit Button -->
                <a
                    href="{{ route('admin.sales.orders.edit', $order->id) }}"
                    class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                >
                    Edit Order
                </a>

                <!-- Back Button -->
                <a
                    href="{{ route('admin.sales.orders.index') }}"
                    class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                >
                    @lang('admin::app.account.edit.back-btn')
                </a>
            </div>
        </div>
    </div>

    <div class="mt-5 flex-wrap items-center justify-between gap-x-1 gap-y-2">
        <div class="flex gap-1.5">
            {!! view_render_event('bagisto.admin.sales.order.page_action.before', ['order' => $order]) !!}

            {{-- Reorder button hidden --}}
            {{-- @if (
                $order->canReorder()
                && bouncer()->hasPermission('sales.orders.create')
                && core()->getConfigData('sales.order_settings.reorder.admin')
            )
                <a
                    href="{{ route('admin.sales.orders.reorder', $order->id) }}"
                    class="transparent-button px-1 py-1.5 hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                >
                    <span class="icon-cart text-2xl"></span>

                    @lang('admin::app.sales.orders.view.reorder')
                </a>
            @endif --}}

            {{-- Print Invoice button - only show if order has invoices --}}
            @if ($order->invoices && $order->invoices->count() > 0)
                <a
                    href="{{ route('admin.sales.invoices.view_pdf', $order->invoices->first()->id) }}"
                    class="transparent-button px-1 py-1.5 hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                    target="_blank"
                >
                    <span class="icon-sales text-2xl"></span>
                    Print Invoice
                </a>
            @endif

            {{-- Ship button hidden --}}
            {{-- @if (
                $order->canShip()
                && bouncer()->hasPermission('sales.shipments.create')
            )
                @include('admin::sales.shipments.create')
            @endif --}}

            {{-- Refund button hidden --}}
            {{-- @if (
                $order->canRefund()
                && bouncer()->hasPermission('sales.refunds.create')
            )
                @include('admin::sales.refunds.create')
            @endif --}}

            @if (
                $order->canCancel()
                && bouncer()->hasPermission('sales.orders.cancel')
            )
               <form
                    method="POST"
                    ref="cancelOrderForm"
                    action="{{ route('admin.sales.orders.cancel', $order->id) }}"
                >
                    @csrf
                </form>

                <div
                    class="transparent-button px-1 py-1.5 hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                    @click="$emitter.emit('open-confirm-modal', {
                        message: '@lang('admin::app.sales.orders.view.cancel-msg')',
                        agree: () => {
                            this.$refs['cancelOrderForm'].submit()
                        }
                    })"
                >
                    <span
                        class="icon-cancel text-2xl"
                        role="presentation"
                        tabindex="0"
                    >
                    </span>

                    <a href="javascript:void(0);">
                        @lang('admin::app.sales.orders.view.cancel')
                    </a>
                </div>
            @endif

            {!! view_render_event('bagisto.admin.sales.order.page_action.after', ['order' => $order]) !!}
        </div>

        <!-- Order details -->
        <div class="mt-3.5 flex gap-2.5 max-xl:flex-wrap">
            <!-- Left Component -->
            <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
                {!! view_render_event('bagisto.admin.sales.order.left_component.before', ['order' => $order]) !!}

                <div class="box-shadow rounded bg-white dark:bg-gray-900">
                    <div class="flex justify-between p-4">
                        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                            @lang('Order Items') ({{ count($order->items) }})
                        </p>

                        <p class="text-base font-semibold text-gray-800 dark:text-white">
                            @lang('admin::app.sales.orders.view.grand-total', ['grand_total' => core()->formatBasePrice($order->base_grand_total + ($order->additional_shipping_amount ?? 0))])
                        </p>
                    </div>

                    <!-- Order items -->
                    <div class="grid">
                        @foreach ($order->items as $item)
                            {!! view_render_event('bagisto.admin.sales.order.list.before', ['order' => $order]) !!}

                            <div class="flex justify-between gap-2.5 border-b border-slate-300 px-4 py-6 dark:border-gray-800" style="background: #f9fafb;">
                                <div class="flex gap-2.5">
                                    @if($item?->product?->base_image_url)
                                        <img
                                            class="relative h-[60px] max-h-[60px] w-full max-w-[60px] rounded"
                                            src="{{ $item?->product->base_image_url }}"
                                        >
                                    @else
                                        <div class="relative h-[60px] max-h-[60px] w-full max-w-[60px] rounded border border-dashed border-gray-300 dark:border-gray-800 dark:mix-blend-exclusion dark:invert">
                                            <img src="{{ bagisto_asset('images/product-placeholders/front.svg') }}">

                                            <p class="absolute bottom-1.5 w-full text-center text-[6px] font-semibold text-gray-400">
                                                @lang('admin::app.sales.invoices.view.product-image')
                                            </p>
                                        </div>
                                    @endif

                                    <div class="grid place-content-start gap-1.5">
                                        <p class="break-all text-base font-semibold text-gray-800 dark:text-white">
                                            {{ $item->name }}
                                        </p>

                                        <div class="flex flex-col place-items-start gap-1.5">
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.amount-per-unit', [
                                                    'amount' => core()->formatBasePrice($item->base_price),
                                                    'qty'    => $item->qty_ordered,
                                                ])
                                            </p>

                                            @if (isset($item->additional['attributes']))
                                                @foreach ($item->additional['attributes'] as $attribute)
                                                    <p class="text-gray-600 dark:text-gray-300">
                                                        @if (
                                                            ! isset($attribute['attribute_type'])
                                                            || $attribute['attribute_type'] !== 'file'
                                                        )
                                                            {{ $attribute['attribute_name'] }} : {{ $attribute['option_label'] }}
                                                        @else
                                                            {{ $attribute['attribute_name'] }} :

                                                            <a
                                                                href="{{ Storage::url($attribute['option_label']) }}"
                                                                class="text-blue-600 hover:underline"
                                                                download="{{ File::basename($attribute['option_label']) }}"
                                                            >
                                                                {{ File::basename($attribute['option_label']) }}
                                                            </a>
                                                        @endif
                                                    </p>
                                                @endforeach
                                            @endif

                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.sku', ['sku' => $item->sku])
                                            </p>

                                            <p class="text-gray-600 dark:text-gray-300">
                                                {{ $item->qty_ordered ? trans('admin::app.sales.orders.view.item-ordered', ['qty_ordered' => $item->qty_ordered]) : '' }}

                                                {{ $item->qty_invoiced ? trans('admin::app.sales.orders.view.item-invoice', ['qty_invoiced' => $item->qty_invoiced]) : '' }}

                                                {{ $item->qty_shipped ? trans('admin::app.sales.orders.view.item-shipped', ['qty_shipped' => $item->qty_shipped]) : '' }}

                                                {{ $item->qty_refunded ? trans('admin::app.sales.orders.view.item-refunded', ['qty_refunded' => $item->qty_refunded]) : '' }}

                                                {{ $item->qty_canceled ? trans('admin::app.sales.orders.view.item-canceled', ['qty_canceled' => $item->qty_canceled]) : '' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid place-content-start gap-1">
                                    <div class="">
                                        <p class="flex items-center justify-end gap-x-1 text-base font-semibold text-gray-800 dark:text-white">
                                            {{ core()->formatBasePrice($item->base_total - $item->base_discount_amount) }}
                                        </p>
                                    </div>

                                    <div class="flex flex-col place-items-start items-end gap-1.5">
                                        @if (core()->getConfigData('sales.taxes.sales.display_prices') == 'including_tax')
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.price', ['price' => core()->formatBasePrice($item->base_price_incl_tax)])
                                            </p>
                                        @elseif (core()->getConfigData('sales.taxes.sales.display_prices') == 'both')
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.price-excl-tax', ['price' => core()->formatBasePrice($item->base_price)])
                                            </p>

                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.price-incl-tax', ['price' => core()->formatBasePrice($item->base_price_incl_tax)])
                                            </p>
                                        @else
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.price', ['price' => core()->formatBasePrice($item->base_price)])
                                            </p>
                                        @endif

                                        <p class="text-gray-600 dark:text-gray-300" style="display: none;">
                                            @lang('admin::app.sales.orders.view.tax', [
                                                'percent' => number_format($item->tax_percent, 2) . '%',
                                                'tax'     => core()->formatBasePrice($item->base_tax_amount)
                                            ])
                                        </p>

                                        @if ($order->base_discount_amount > 0)
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.discount', ['discount' => core()->formatBasePrice($item->base_discount_amount)])
                                            </p>
                                        @endif

                                        @if (core()->getConfigData('sales.taxes.sales.display_subtotal') == 'including_tax')
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.sub-total', ['sub_total' => core()->formatBasePrice($item->base_total_incl_tax)])
                                            </p>
                                        @elseif (core()->getConfigData('sales.taxes.sales.display_subtotal') == 'both')
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.sub-total-excl-tax', ['sub_total' => core()->formatBasePrice($item->base_total)])
                                            </p>

                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.sub-total-incl-tax', ['sub_total' => core()->formatBasePrice($item->base_total_incl_tax)])
                                            </p>
                                        @else
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.sub-total', ['sub_total' => core()->formatBasePrice($item->base_total)])
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {!! view_render_event('bagisto.admin.sales.order.list.after', ['order' => $order]) !!}

                        @endforeach

                        @if(isset($ariItems) && count($ariItems) > 0)
                            <div class="px-4 py-3" style="background: linear-gradient(135deg, #E13124 0%, #c12a1e 100%); border-top: 3px solid #c12a1e;">
                                <p class="text-base font-bold text-white flex items-center gap-2">
                                    <span class="icon-settings text-xl"></span>
                                    ARI PartStream Items ({{ count($ariItems) }})
                                </p>
                            </div>

                            @foreach ($ariItems as $ariItem)
                                <div class="flex justify-between gap-2.5 border-b border-slate-300 px-4 py-6 dark:border-gray-800" style="background: #fef2f2;">
                                    <div class="flex gap-2.5">
                                        <div class="relative h-[60px] max-h-[60px] w-full max-w-[60px] rounded border border-dashed border-gray-300 dark:border-gray-800 dark:mix-blend-exclusion dark:invert">
                                            <img src="{{ bagisto_asset('images/product-placeholders/front.svg') }}">
                                            <p class="absolute bottom-1.5 w-full text-center text-[6px] font-semibold text-gray-400">
                                                ARI PartStream
                                            </p>
                                        </div>

                                        <div class="grid place-content-start gap-1.5">
                                            <p class="break-all text-base font-semibold text-gray-800 dark:text-white">
                                                {{ $ariItem->name }}
                                            </p>

                                            <div class="flex flex-col place-items-start gap-1.5">
                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @lang('admin::app.sales.orders.view.amount-per-unit', [
                                                        'amount' => core()->formatBasePrice($ariItem->price),
                                                        'qty'    => $ariItem->quantity,
                                                    ])
                                                </p>

                                                @if($ariItem->brand)
                                                    <p class="text-gray-600 dark:text-gray-300">
                                                        Brand: {{ $ariItem->brand }}
                                                    </p>
                                                @endif

                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @lang('admin::app.sales.orders.view.sku', ['sku' => $ariItem->sku])
                                                </p>

                                                <p class="text-gray-600 dark:text-gray-300">
                                                    Supplier: <span class="font-semibold text-red-600">{{ strtoupper(str_replace('_', ' ', $ariItem->selected_supplier)) }}</span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid place-content-start gap-1">
                                        <div class="">
                                            <p class="flex items-center justify-end gap-x-1 text-base font-semibold text-gray-800 dark:text-white">
                                                {{ core()->formatBasePrice($ariItem->total) }}
                                            </p>
                                        </div>

                                        <div class="flex flex-col place-items-start items-end gap-1.5">
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.price', ['price' => core()->formatBasePrice($ariItem->price)])
                                            </p>

                                            <p class="text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.sub-total', ['sub_total' => core()->formatBasePrice($ariItem->total)])
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endif

                        @if(isset($fulfillmentBySupplier) && count($fulfillmentBySupplier) > 0)
                            <div class="mt-6 p-4" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 8px; border: 2px solid #0f3460;">
                                <h3 class="text-lg font-bold mb-4" style="color: #ffffff;">
                                    <i class="icon-truck"></i> Order Fulfillment Details
                                </h3>

                                @foreach ($fulfillmentBySupplier as $supplier => $details)
                                    <div class="mb-6 p-4" style="background: rgba(255,255,255,0.05); border-radius: 6px; border-left: 4px solid {{ $details->first()->status === 'success' ? '#10b981' : '#ef4444' }};">
                                        <h4 class="text-md font-semibold mb-3" style="color: #e94560;">
                                            {{ strtoupper(str_replace('_', ' ', $supplier)) }}
                                            <span class="text-xs px-2 py-1 rounded ml-2" style="background: {{ $details->first()->status === 'success' ? '#10b981' : '#ef4444' }}; color: white;">
                                                {{ strtoupper($details->first()->status) }}
                                            </span>
                                        </h4>

                                        <div class="text-sm space-y-2" style="color: #cbd5e1;">
                                            <p><strong style="color: #94a3b8;">Fulfillment Type:</strong> {{ $details->first()->fulfillment_type }}</p>

                                            @if($details->first()->external_po_number)
                                                <p><strong style="color: #94a3b8;">PO Number:</strong> <span style="color: #38bdf8;">{{ $details->first()->external_po_number }}</span></p>
                                            @endif

                                            @if($details->first()->external_order_id)
                                                <p><strong style="color: #94a3b8;">External Order ID:</strong> <span style="color: #38bdf8;">{{ $details->first()->external_order_id }}</span></p>
                                            @endif

                                            @if($details->first()->tracking_number)
                                                <p><strong style="color: #94a3b8;">Tracking:</strong> <span style="color: #38bdf8;">{{ $details->first()->tracking_number }}</span></p>
                                            @endif

                                            @if($details->first()->error_message)
                                                <div class="mt-2 p-2" style="background: rgba(239, 68, 68, 0.1); border-radius: 4px; border-left: 3px solid #ef4444;">
                                                    <p class="text-xs" style="color: #fca5a5;"><strong>Error:</strong> {{ $details->first()->error_message }}</p>
                                                </div>
                                            @endif

                                            <div class="mt-3">
                                                <p class="font-semibold mb-2" style="color: #94a3b8;">Items:</p>
                                                <table class="w-full text-xs">
                                                    <thead style="background: rgba(255,255,255,0.05);">
                                                        <tr>
                                                            <th class="text-left p-2" style="color: #94a3b8;">SKU</th>
                                                            <th class="text-left p-2" style="color: #94a3b8;">Qty</th>
                                                            <th class="text-right p-2" style="color: #94a3b8;">Price</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($details as $detail)
                                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                                                <td class="p-2" style="color: #e2e8f0;">{{ $detail->item_sku }}</td>
                                                                <td class="p-2" style="color: #e2e8f0;">{{ $detail->item_quantity }}</td>
                                                                <td class="text-right p-2" style="color: #e2e8f0;">{{ core()->formatBasePrice($detail->item_price) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="mt-3">
                                                <button onclick="toggleDetails{{$loop->index}}()" class="text-xs px-3 py-1 rounded" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid #3b82f6;">
                                                    View API Response
                                                </button>
                                                <div id="details{{$loop->index}}" style="display: none;" class="mt-2 p-2 text-xs" style="background: rgba(0,0,0,0.3); border-radius: 4px; overflow-x: auto; color: #94a3b8;">
                                                    <pre style="white-space: pre-wrap; word-wrap: break-word; color: #cbd5e1;">{{ json_encode(is_string($details->first()->response_data) ? json_decode($details->first()->response_data) : $details->first()->response_data, JSON_PRETTY_PRINT) }}</pre>
                                                </div>
                                                <script>
                                                    function toggleDetails{{$loop->index}}() {
                                                        var x = document.getElementById("details{{$loop->index}}");
                                                        if (x.style.display === "none") {
                                                            x.style.display = "block";
                                                        } else {
                                                            x.style.display = "none";
                                                        }
                                                    }
                                                </script>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-auto justify-end p-4">
                        <div class="grid max-w-max gap-2 text-sm">

                            {!! view_render_event('bagisto.admin.sales.order.view.subtotal.before') !!}

                            <!-- Sub Total -->
                            @if (core()->getConfigData('sales.taxes.sales.display_subtotal') == 'including_tax')
                                <div class="flex w-full justify-between gap-x-5">
                                    <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                        @lang('admin::app.sales.orders.view.summary-sub-total-incl-tax')
                                    </p>

                                    <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($order->base_sub_total_incl_tax) }}
                                    </p>
                                </div>
                            @elseif (core()->getConfigData('sales.taxes.sales.display_subtotal') == 'both')
                                <div class="flex w-full justify-between gap-x-5">
                                    <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                        @lang('admin::app.sales.orders.view.summary-sub-total-excl-tax')
                                    </p>

                                    <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($order->base_sub_total) }}
                                    </p>
                                </div>

                                <div class="flex w-full justify-between gap-x-5">
                                    <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                        @lang('admin::app.sales.orders.view.summary-sub-total-incl-tax')
                                    </p>

                                    <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($order->base_sub_total_incl_tax) }}
                                    </p>
                                </div>
                            @else
                                <div class="flex w-full justify-between gap-x-5">
                                    <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                        @lang('admin::app.sales.orders.view.summary-sub-total')
                                    </p>

                                    <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($order->base_sub_total) }}
                                    </p>
                                </div>
                            @endif

                            {!! view_render_event('bagisto.admin.sales.order.view.subtotal.after') !!}

                            {!! view_render_event('bagisto.admin.sales.order.view.shipping.before') !!}

                            <!-- Shipping And Handling -->
                            @if ($haveStockableItems = $order->haveStockableItems() || $order->base_shipping_amount > 0 || ($order->additional_shipping_amount ?? 0) > 0)
                                @if (core()->getConfigData('sales.taxes.sales.display_subtotal') == 'including_tax')
                                    @if(isset($order->additional_shipping_amount) && $order->additional_shipping_amount > 0)
                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                Original Shipping (incl. tax)
                                            </p>

                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->base_shipping_amount_incl_tax) }}
                                            </p>
                                        </div>

                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                Additional Shipping
                                            </p>

                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->additional_shipping_amount) }}
                                            </p>
                                        </div>

                                        @if($order->additional_shipping_invoice_status)
                                        <div class="flex w-full justify-between gap-x-5 bg-yellow-50 dark:bg-yellow-900 p-2 rounded mb-2">
                                            <p class="!leading-5 text-xs text-yellow-800 dark:text-yellow-200">
                                                Stripe Invoice Status:
                                            </p>

                                            <p class="font-semibold !leading-5 text-xs text-yellow-800 dark:text-yellow-200">
                                                {{ ucfirst(str_replace('_', ' ', $order->additional_shipping_invoice_status)) }}
                                                @if($order->additional_shipping_invoice_status === 'open')
                                                <br><span class="text-xs">(Pending Payment)</span>
                                                @endif
                                            </p>
                                        </div>
                                        @endif

                                        <div class="flex w-full justify-between gap-x-5 bg-gray-100 dark:bg-gray-800 p-2 rounded">
                                            <p class="font-semibold !leading-5 text-gray-800 dark:text-white">
                                                @lang('admin::app.sales.orders.view.shipping-and-handling-incl-tax')
                                            </p>

                                            <p class="font-semibold !leading-5 text-gray-800 dark:text-white">
                                                {{ core()->formatBasePrice($order->base_shipping_amount_incl_tax + $order->additional_shipping_amount) }}
                                            </p>
                                        </div>
                                    @else
                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.shipping-and-handling-incl-tax')
                                            </p>

                                            <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->base_shipping_amount_incl_tax) }}
                                            </p>
                                        </div>
                                    @endif
                                @elseif (core()->getConfigData('sales.taxes.sales.display_shipping_amount') == 'both')
                                    @if(isset($order->additional_shipping_amount) && $order->additional_shipping_amount > 0)
                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                Original Shipping (excl. tax)
                                            </p>

                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->base_shipping_amount) }}
                                            </p>
                                        </div>

                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                Original Shipping (incl. tax)
                                            </p>

                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->base_shipping_amount_incl_tax) }}
                                            </p>
                                        </div>

                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                Additional Shipping
                                            </p>

                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->additional_shipping_amount) }}
                                            </p>
                                        </div>

                                        @if($order->additional_shipping_invoice_status)
                                        <div class="flex w-full justify-between gap-x-5 bg-yellow-50 dark:bg-yellow-900 p-2 rounded mb-2">
                                            <p class="!leading-5 text-xs text-yellow-800 dark:text-yellow-200">
                                                Stripe Invoice Status:
                                            </p>

                                            <p class="font-semibold !leading-5 text-xs text-yellow-800 dark:text-yellow-200">
                                                {{ ucfirst(str_replace('_', ' ', $order->additional_shipping_invoice_status)) }}
                                                @if($order->additional_shipping_invoice_status === 'open')
                                                <br><span class="text-xs">(Pending Payment)</span>
                                                @endif
                                            </p>
                                        </div>
                                        @endif

                                        <div class="flex w-full justify-between gap-x-5 bg-gray-100 dark:bg-gray-800 p-2 rounded">
                                            <p class="font-semibold !leading-5 text-gray-800 dark:text-white">
                                                Total Shipping (incl. tax)
                                            </p>

                                            <p class="font-semibold !leading-5 text-gray-800 dark:text-white">
                                                {{ core()->formatBasePrice($order->base_shipping_amount_incl_tax + $order->additional_shipping_amount) }}
                                            </p>
                                        </div>
                                    @else
                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.shipping-and-handling-excl-tax')
                                            </p>

                                            <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->base_shipping_amount) }}
                                            </p>
                                        </div>

                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.shipping-and-handling-incl-tax')
                                            </p>

                                            <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->base_shipping_amount_incl_tax) }}
                                            </p>
                                        </div>
                                    @endif
                                @else
                                    <!-- Show breakdown if there's additional shipping -->
                                    @if(isset($order->additional_shipping_amount) && $order->additional_shipping_amount > 0)
                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                Original Shipping
                                            </p>

                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->base_shipping_amount) }}
                                            </p>
                                        </div>

                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                Additional Shipping
                                            </p>

                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->additional_shipping_amount) }}
                                            </p>
                                        </div>

                                        @if($order->additional_shipping_invoice_status)
                                        <div class="flex w-full justify-between gap-x-5 bg-yellow-50 dark:bg-yellow-900 p-2 rounded mb-2">
                                            <p class="!leading-5 text-xs text-yellow-800 dark:text-yellow-200">
                                                Stripe Invoice Status:
                                            </p>

                                            <p class="font-semibold !leading-5 text-xs text-yellow-800 dark:text-yellow-200">
                                                {{ ucfirst(str_replace('_', ' ', $order->additional_shipping_invoice_status)) }}
                                                @if($order->additional_shipping_invoice_status === 'open')
                                                <br><span class="text-xs">(Pending Payment)</span>
                                                @endif
                                            </p>
                                        </div>
                                        @endif

                                        <!-- Total Shipping -->
                                        <div class="flex w-full justify-between gap-x-5 bg-gray-100 dark:bg-gray-800 p-2 rounded">
                                            <p class="font-semibold !leading-5 text-gray-800 dark:text-white">
                                                @lang('admin::app.sales.orders.view.shipping-and-handling')
                                            </p>

                                            <p class="font-semibold !leading-5 text-gray-800 dark:text-white">
                                                {{ core()->formatBasePrice($order->base_shipping_amount + $order->additional_shipping_amount) }}
                                            </p>
                                        </div>
                                    @else
                                        <!-- No additional shipping, show original only -->
                                        <div class="flex w-full justify-between gap-x-5">
                                            <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                                @lang('admin::app.sales.orders.view.shipping-and-handling')
                                            </p>

                                            <p class="font-semibold !leading-5 text-gray-600 dark:text-gray-300">
                                                {{ core()->formatBasePrice($order->base_shipping_amount) }}
                                            </p>
                                        </div>
                                    @endif
                                @endif
                            @endif

                            {!! view_render_event('bagisto.admin.sales.order.view.shipping.after') !!}

                            {!! view_render_event('bagisto.admin.sales.order.view.tax-amount.before') !!}

                            <!-- Tax Amount -->
                            <div class="flex w-full justify-between gap-x-5">
                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.summary-tax')
                                </p>

                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    {{ core()->formatBasePrice($order->base_tax_amount) }}
                                </p>
                            </div>

                            {!! view_render_event('bagisto.admin.sales.order.view.tax-amount.after') !!}

                            {!! view_render_event('bagisto.admin.sales.order.view.discount.before') !!}

                            <!-- Discount -->
                            <div class="flex w-full justify-between gap-x-5">
                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.summary-discount')
                                </p>

                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    {{ core()->formatBasePrice($order->base_discount_amount) }}
                                </p>
                            </div>

                            {!! view_render_event('bagisto.admin.sales.order.view.discount.after') !!}

                            {!! view_render_event('bagisto.admin.sales.order.view.grand-total.before') !!}

                            <!-- Grand Total -->
                            <div class="flex w-full justify-between gap-x-5">
                                <p class="text-base font-semibold !leading-5 text-gray-800 dark:text-white">
                                    @lang('admin::app.sales.orders.view.summary-grand-total')
                                </p>

                                <p class="text-base font-semibold !leading-5 text-gray-800 dark:text-white">
                                    {{ core()->formatBasePrice($order->base_grand_total + ($order->additional_shipping_amount ?? 0)) }}
                                </p>
                            </div>

                            {!! view_render_event('bagisto.admin.sales.order.view.grand-total.after') !!}

                            {!! view_render_event('bagisto.admin.sales.order.view.total-paid.before') !!}

                            <!-- Total Paid -->
                            <div class="flex w-full justify-between gap-x-5">
                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.total-paid')
                                </p>

                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    {{ core()->formatBasePrice($order->base_grand_total_invoiced) }}
                                </p>
                            </div>

                            {!! view_render_event('bagisto.admin.sales.order.view.total-paid.after') !!}

                            {!! view_render_event('bagisto.admin.sales.order.view.total-refunded.before') !!}

                            <!-- Total Refund -->
                            <div class="flex w-full justify-between gap-x-5">
                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.total-refund')
                                </p>

                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    {{ core()->formatBasePrice($order->base_grand_total_refunded) }}
                                </p>
                            </div>

                            {!! view_render_event('bagisto.admin.sales.order.view.total-refunded.after') !!}

                            {!! view_render_event('bagisto.admin.sales.order.view.total-due.before') !!}

                            <!-- Total Due -->
                            <div class="flex w-full justify-between gap-x-5 font-semibold">
                                <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.total-due')
                                </p>

                                @if($order->status !== 'canceled')
                                    @php
                                        // Calculate total due accounting for additional shipping
                                        $actualGrandTotal = $order->base_grand_total + ($order->additional_shipping_amount ?? 0);
                                        $totalDue = $actualGrandTotal - $order->base_grand_total_invoiced - $order->base_grand_total_refunded;
                                        // If there's pending payment, add it (in case additional shipping hasn't been paid yet)
                                        if (isset($order->pending_payment_amount) && $order->pending_payment_amount > 0) {
                                            $totalDue = $order->pending_payment_amount;
                                        }
                                    @endphp
                                    <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice($totalDue) }}
                                    </p>
                                @else
                                    <p class="!leading-5 text-gray-600 dark:text-gray-300">
                                        {{ core()->formatBasePrice(0.00) }}
                                    </p>
                                @endif
                            </div>

                            {!! view_render_event('bagisto.admin.sales.order.view.total-due.after') !!}

                        </div>
                    </div>
                </div>

                <!-- Customer's comment form -->
                <div class="box-shadow rounded bg-white dark:bg-gray-900">
                    <p class="p-4 pb-0 text-base font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.sales.orders.view.comments')
                    </p>

                    <x-admin::form action="{{ route('admin.sales.orders.comment', $order->id) }}">
                        <div class="p-4">
                            <div class="mb-2.5">
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.control
                                        type="textarea"
                                        id="comment"
                                        name="comment"
                                        rules="required"
                                        :label="trans('admin::app.sales.orders.view.comments')"
                                        :placeholder="trans('admin::app.sales.orders.view.write-your-comment')"
                                        rows="3"
                                    />

                                    <x-admin::form.control-group.error control-name="comment" />
                                </x-admin::form.control-group>
                            </div>

                            <div class="flex items-center justify-between">
                                <label
                                    class="flex w-max cursor-pointer select-none items-center gap-1 p-1.5"
                                    for="customer_notified"
                                >
                                    <input
                                        type="checkbox"
                                        name="customer_notified"
                                        id="customer_notified"
                                        value="1"
                                        class="peer hidden"
                                    >

                                    <span
                                        class="icon-uncheckbox peer-checked:icon-checked cursor-pointer rounded-md text-2xl peer-checked:text-blue-600"
                                        role="button"
                                        tabindex="0"
                                    >
                                    </span>

                                    <p class="flex cursor-pointer items-center gap-x-1 font-semibold text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100">
                                        @lang('admin::app.sales.orders.view.notify-customer')
                                    </p>
                                </label>

                                <button
                                    type="submit"
                                    class="secondary-button"
                                    aria-label="{{ trans('admin::app.sales.orders.view.submit-comment') }}"
                                >
                                    @lang('admin::app.sales.orders.view.submit-comment')
                                </button>
                            </div>
                        </div>
                    </x-admin::form>

                    <span class="block w-full border-b dark:border-gray-800"></span>

                    <!-- Comment List -->
                    @foreach ($order->comments()->orderBy('id', 'desc')->get() as $comment)
                        <div class="grid gap-1.5 p-4">
                            <p class="break-all text-base leading-6 text-gray-800 dark:text-white">
                                {{ $comment->comment }}
                            </p>

                            <!-- Notes List Title and Time -->
                            <p class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                                <span class="icon-clock text-xl"></span>
                                {{ core()->formatDate($comment->created_at, 'F d, Y g:i A') }}
                            </p>
                        </div>

                        <span class="block w-full border-b dark:border-gray-800"></span>
                    @endforeach
                </div>

                {!! view_render_event('bagisto.admin.sales.order.left_component.after', ['order' => $order]) !!}
            </div>

            <!-- Right Component -->
            <div class="flex w-[360px] max-w-full flex-col gap-2 max-sm:w-full">
                {!! view_render_event('bagisto.admin.sales.order.right_component.before', ['order' => $order]) !!}

                <!-- Customer and address information -->
                <x-admin::accordion>
                    <x-slot:header>
                        <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                            @lang('admin::app.sales.orders.view.customer')
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div class="{{ $order->billing_address ? 'pb-4' : '' }}">
                            <div class="flex flex-col gap-1.5">
                                <p class="font-semibold text-gray-800 dark:text-white">
                                    {{ $order->customer_full_name }}
                                </p>

                                {!! view_render_event('bagisto.admin.sales.order.customer_full_name.after', ['order' => $order]) !!}

                                <p class="text-gray-600 dark:text-gray-300">
                                    {{ $order->customer_email }}
                                </p>

                                {!! view_render_event('bagisto.admin.sales.order.customer_email.after', ['order' => $order]) !!}

                                <p class="text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.customer-group') : {{ $order->is_guest ? core()->getGuestCustomerGroup()?->name : ($order->customer->group->name ?? '') }}
                                </p>

                                {!! view_render_event('bagisto.admin.sales.order.customer_group.after', ['order' => $order]) !!}
                            </div>
                        </div>

                        <!-- Billing Address -->
                        @if ($order->billing_address)
                            <span class="block w-full border-b dark:border-gray-800"></span>

                            <div class="{{ $order->shipping_address ? 'pb-4' : '' }}">

                                <div class="flex items-center justify-between">
                                    <p class="py-4 text-base font-semibold text-gray-600 dark:text-gray-300">
                                        @lang('admin::app.sales.orders.view.billing-address')
                                    </p>
                                </div>

                                @include ('admin::sales.address', ['address' => $order->billing_address])

                                {!! view_render_event('bagisto.admin.sales.order.billing_address.after', ['order' => $order]) !!}
                            </div>
                        @endif

                        <!-- Shipping Address -->
                        @if ($order->shipping_address)
                            <span class="block w-full border-b dark:border-gray-800"></span>

                            <div class="flex items-center justify-between">
                                <p class="py-4 text-base font-semibold text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.shipping-address')
                                </p>
                            </div>

                            @include ('admin::sales.address', ['address' => $order->shipping_address])

                            {!! view_render_event('bagisto.admin.sales.order.shipping_address.after', ['order' => $order]) !!}
                        @endif
                    </x-slot>
                </x-admin::accordion>

                <!-- Order Information -->
                <x-admin::accordion>
                    <x-slot:header>
                        <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                            @lang('admin::app.sales.orders.view.order-information')
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div class="flex w-full justify-start gap-5">
                            <div class="flex flex-col gap-y-1.5">
                                <p class="text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.order-date')
                                </p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.order-status')
                                </p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.channel')
                                </p>
                            </div>

                            <div class="flex flex-col gap-y-1.5">
                                {!! view_render_event('bagisto.admin.sales.order.created_at.before', ['order' => $order]) !!}

                                <!-- Order Date -->
                                <p class="text-gray-600 dark:text-gray-300">
                                    {{core()->formatDate($order->created_at) }}
                                </p>

                                {!! view_render_event('bagisto.admin.sales.order.created_at.after', ['order' => $order]) !!}

                                <!-- Order Status -->
                                <p class="text-gray-600 dark:text-gray-300">
                                    {{$order->status_label}}
                                </p>

                                {!! view_render_event('bagisto.admin.sales.order.status_label.after', ['order' => $order]) !!}

                                <!-- Order Channel -->
                                <p class="text-gray-600 dark:text-gray-300">
                                    {{$order->channel_name}}
                                </p>

                                {!! view_render_event('bagisto.admin.sales.order.channel_name.after', ['order' => $order]) !!}
                            </div>
                        </div>
                    </x-slot>
                </x-admin::accordion>

                @if($order->customer_notes)
                <x-admin::accordion>
                    <x-slot:header>
                        <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                            Customer Order Notes
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div class="flex w-full justify-start">
                            <div class="w-full p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                <p class="text-gray-800 dark:text-white whitespace-pre-wrap">{{ $order->customer_notes }}</p>
                            </div>
                        </div>
                    </x-slot>
                </x-admin::accordion>
                @endif

                <!-- Payment and Shipping Information-->
                <x-admin::accordion>
                    <x-slot:header>
                        <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                            @lang('admin::app.sales.orders.view.payment-and-shipping')
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div>
                            <!-- Payment method -->
                            <p class="font-semibold text-gray-800 dark:text-white">
                                {{ $order->payment->method_title ?? core()->getConfigData('sales.payment_methods.' . $order->payment->method . '.title') }}
                                @if($order->payment->method === 'paypal')
                                    <span class="text-blue-600">💳</span>
                                @elseif($order->payment->method === 'stripe')
                                    <span class="text-indigo-600">💳</span>
                                @endif
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('admin::app.sales.orders.view.payment-method')
                            </p>

                            @php
                                $paymentAdditional = is_string($order->payment->additional)
                                    ? json_decode($order->payment->additional, true)
                                    : $order->payment->additional;
                                $transactionId = $paymentAdditional['transaction_id'] ?? null;
                            @endphp

                            @if($transactionId)
                                <p class="pt-4 font-semibold text-gray-800 dark:text-white">
                                    {{ $transactionId }}
                                </p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    Transaction ID
                                </p>
                            @endif

                            <!-- Currency -->
                            <p class="pt-4 font-semibold text-gray-800 dark:text-white">
                                {{ $order->order_currency_code }}
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('admin::app.sales.orders.view.currency')
                            </p>

                            @php $additionalDetails = \Webkul\Payment\Payment::getAdditionalDetails($order->payment->method); @endphp

                            <!-- Additional details -->
                            @if (! empty($additionalDetails))
                                <p class="pt-4 font-semibold text-gray-800 dark:text-white">
                                    {{ $additionalDetails['title'] }}
                                </p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    {{ $additionalDetails['value'] }}
                                </p>
                            @endif

                            {!! view_render_event('bagisto.admin.sales.order.payment-method.after', ['order' => $order]) !!}
                        </div>

                        <!-- Shipping Method and Price Details -->
                        @if ($order->shipping_address)
                            <span class="mt-4 block w-full border-b dark:border-gray-800"></span>

                            <div class="pt-4">
                                <p class="font-semibold text-gray-800 dark:text-white">
                                    {{ $order->shipping_title }}
                                </p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.shipping-method')
                                </p>

                                <p class="pt-4 font-semibold text-gray-800 dark:text-white">
                                    {{ core()->formatBasePrice($order->base_shipping_amount) }}
                                </p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.sales.orders.view.shipping-price')
                                </p>
                            </div>

                            {!! view_render_event('bagisto.admin.sales.order.shipping-method.after', ['order' => $order]) !!}
                        @endif
                    </x-slot>
                </x-admin::accordion>

                <!-- Invoice Information-->
                <x-admin::accordion>
                    <x-slot:header>
                        <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                            @lang('admin::app.sales.orders.view.invoices') ({{ count($order->invoices) }})
                        </p>
                    </x-slot>

                    <x-slot:content>
                        @forelse ($order->invoices as $index => $invoice)
                            <div class="grid gap-y-2.5">
                                <div>
                                    <p class="font-semibold text-gray-800 dark:text-white">
                                        @lang('admin::app.sales.orders.view.invoice-id', ['invoice' => $invoice->increment_id ?? $invoice->id])
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        {{ core()->formatDate($invoice->created_at, 'd M, Y H:i:s a') }}
                                    </p>
                                </div>

                                <div class="flex gap-2.5">
                                    <a
                                        href="{{ route('admin.sales.invoices.view', $invoice->id) }}"
                                        class="text-sm text-blue-600 transition-all hover:underline"
                                    >
                                        @lang('admin::app.sales.orders.view.view')
                                    </a>

                                    <a
                                        href="{{ route('admin.sales.invoices.print', $invoice->id) }}"
                                        class="text-sm text-blue-600 transition-all hover:underline"
                                    >
                                        @lang('admin::app.sales.orders.view.download-pdf')
                                    </a>
                                </div>
                            </div>

                            @if ($index < count($order->invoices) - 1)
                                <span class="mb-4 mt-4 block w-full border-b dark:border-gray-800"></span>
                            @endif
                        @empty
                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('admin::app.sales.orders.view.no-invoice-found')
                            </p>
                        @endforelse
                    </x-slot>
                </x-admin::accordion>

                <!-- Shipment Information-->
                <x-admin::accordion>
                    <x-slot:header>
                        <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                            @lang('admin::app.sales.orders.view.shipments') ({{ count($order->shipments) }})
                        </p>
                    </x-slot>

                    <x-slot:content>
                        @forelse ($order->shipments as $shipment)
                            <div class="grid gap-y-2.5">
                                <div>
                                    <!-- Shipment Id -->
                                    <p class="font-semibold text-gray-800 dark:text-white">
                                        @lang('admin::app.sales.orders.view.shipment', ['shipment' => $shipment->id])
                                    </p>

                                    <!-- Shipment Created -->
                                    <p class="text-gray-600 dark:text-gray-300">
                                        {{ core()->formatDate($shipment->created_at, 'd M, Y H:i:s a') }}
                                    </p>
                                </div>

                                <div class="flex gap-2.5">
                                    <a
                                        href="{{ route('admin.sales.shipments.view', $shipment->id) }}"
                                        class="text-sm text-blue-600 transition-all hover:underline"
                                    >
                                        @lang('admin::app.sales.orders.view.view')
                                    </a>
                                </div>
                            </div>
                        @empty
                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('admin::app.sales.orders.view.no-shipment-found')
                            </p>
                        @endforelse
                    </x-slot>
                </x-admin::accordion>

                <!-- Refund Information -->
                <x-admin::accordion>
                    <x-slot:header>
                        <p class="p-2.5 text-base font-semibold text-gray-600 dark:text-gray-300">
                            @lang('admin::app.sales.orders.view.refund')
                        </p>
                    </x-slot>

                    <x-slot:content>
                        @forelse ($order->refunds as $refund)
                            <div class="grid gap-y-2.5">
                                <div>
                                    <p class="font-semibold text-gray-800 dark:text-white">
                                        @lang('admin::app.sales.orders.view.refund-id', ['refund' => $refund->id])
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        {{ core()->formatDate($refund->created_at, 'd M, Y H:i:s a') }}
                                    </p>

                                    <p class="mt-4 font-semibold text-gray-800 dark:text-white">
                                        @lang('admin::app.sales.orders.view.name')
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        {{ $refund->order->customer_full_name }}
                                    </p>

                                    <p class="mt-4 font-semibold text-gray-800 dark:text-white">
                                        @lang('admin::app.sales.orders.view.status')
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        @lang('admin::app.sales.orders.view.refunded')

                                        <span class="font-semibold text-gray-800 dark:text-white">
                                            {{ core()->formatBasePrice($refund->base_grand_total) }}
                                        </span>
                                    </p>
                                </div>

                                <div class="flex gap-2.5">
                                    <a
                                        href="{{ route('admin.sales.refunds.view', $refund->id) }}"
                                        class="text-sm text-blue-600 transition-all hover:underline"
                                    >
                                        @lang('admin::app.sales.orders.view.view')
                                    </a>
                                </div>
                            </div>
                        @empty
                            <p class="text-gray-600 dark:text-gray-300">
                                @lang('admin::app.sales.orders.view.no-refund-found')
                            </p>
                        @endforelse
                    </x-slot>
                </x-admin::accordion>

                {!! view_render_event('bagisto.admin.sales.order.right_component.after', ['order' => $order]) !!}
            </div>
        </div>
    </div>
</x-admin::layouts>
