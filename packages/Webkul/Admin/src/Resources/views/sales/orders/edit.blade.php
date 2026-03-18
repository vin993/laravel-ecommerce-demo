<x-admin::layouts>
    <x-slot:title>
        Edit Order {{ $order->increment_id }}
    </x-slot>

    <style>
        div.cursor-pointer.px-2\.5.pb-3\.5.text-base.font-medium.text-gray-300 {
            color: #1F2937 !important;
            font-weight: 600 !important;
        }
        div.cursor-pointer.px-2\.5.pb-3\.5.text-base.font-medium.text-blue-600 {
            color: #2563EB !important;
            font-weight: 700 !important;
        }
        .dark div.cursor-pointer.px-2\.5.pb-3\.5.text-base.font-medium.text-gray-300 {
            color: #F3F4F6 !important;
        }
        .dark div.cursor-pointer.px-2\.5.pb-3\.5.text-base.font-medium.text-blue-600 {
            color: #60A5FA !important;
        }
    </style>

    <script>
        function applyTabStyles() {
            const tabElements = document.querySelectorAll('.cursor-pointer.text-gray-300, .cursor-pointer.text-blue-600');
            tabElements.forEach(function(tab) {
                if (tab.classList.contains('text-blue-600')) {
                    tab.style.color = '#2563EB';
                    tab.style.fontWeight = '700';
                } else {
                    tab.style.color = '#1F2937';
                    tab.style.fontWeight = '600';
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(applyTabStyles, 100);

            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('cursor-pointer')) {
                    setTimeout(applyTabStyles, 50);
                }
            });

            const observer = new MutationObserver(function() {
                applyTabStyles();
            });

            setTimeout(function() {
                const tabContainer = document.querySelector('.flex.justify-center.gap-4');
                if (tabContainer) {
                    observer.observe(tabContainer, {
                        attributes: true,
                        subtree: true,
                        attributeFilter: ['class']
                    });
                }
            }, 200);
        });
    </script>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold leading-6 text-gray-800 dark:text-white">
            Edit Order #{{ $order->increment_id }}
        </p>

        <div class="flex gap-x-2.5 items-center">
            <a
                href="{{ route('admin.sales.orders.view', $order->id) }}"
                class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
            >
                Cancel
            </a>
        </div>
    </div>

    <div class="mt-5">
        <x-admin::tabs position="left">
            <x-admin::tabs.item title="Address Details" :isSelected="true">
                <form method="POST" action="{{ route('admin.sales.orders.update', $order->id) }}" ref="addressForm">
                    @csrf
                    <input type="hidden" name="form_type" value="address">

                    <div class="flex flex-1 flex-col gap-2">
                        <div class="box-shadow rounded bg-white dark:bg-gray-900 p-4">
                            <p class="mb-4 text-base font-semibold text-black-800 dark:text-white">
                                Customer Information
                            </p>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Customer Name
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="customer_name"
                                            :value="old('customer_name', $order->customer_full_name)"
                                        />

                                        <x-admin::form.control-group.error control-name="customer_name" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Customer Email
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="email"
                                            name="customer_email"
                                            :value="old('customer_email', $order->customer_email)"
                                        />

                                        <x-admin::form.control-group.error control-name="customer_email" />
                                    </x-admin::form.control-group>
                                </div>
                            </div>
                        </div>

                        @if ($order->billing_address)
                        <div class="box-shadow rounded bg-white dark:bg-gray-900 p-4 mt-4">
                            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                                Billing Address
                            </p>

                            <div class="grid grid-cols-2 gap-4">
                                <div class="col-span-2">
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Name
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="billing_name"
                                            :value="old('billing_name', trim($order->billing_address->first_name . ' ' . $order->billing_address->last_name))"
                                        />

                                        <x-admin::form.control-group.error control-name="billing_name" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="col-span-2">
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Address
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="billing_address1"
                                            :value="old('billing_address1', $order->billing_address->address)"
                                        />

                                        <x-admin::form.control-group.error control-name="billing_address1" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            City
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="billing_city"
                                            :value="old('billing_city', $order->billing_address->city)"
                                        />

                                        <x-admin::form.control-group.error control-name="billing_city" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            State
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="billing_state"
                                            :value="old('billing_state', $order->billing_address->state)"
                                        />

                                        <x-admin::form.control-group.error control-name="billing_state" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Zip Code
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="billing_postcode"
                                            :value="old('billing_postcode', $order->billing_address->postcode)"
                                        />

                                        <x-admin::form.control-group.error control-name="billing_postcode" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Country
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="billing_country"
                                            :value="old('billing_country', $order->billing_address->country)"
                                        />

                                        <x-admin::form.control-group.error control-name="billing_country" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Phone
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="billing_phone"
                                            :value="old('billing_phone', $order->billing_address->phone)"
                                        />

                                        <x-admin::form.control-group.error control-name="billing_phone" />
                                    </x-admin::form.control-group>
                                </div>
                            </div>
                        </div>
                        @endif

                        @if ($order->shipping_address)
                        <div class="box-shadow rounded bg-white dark:bg-gray-900 p-4 mt-4">
                            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                                Shipping Address
                            </p>

                            <div class="grid grid-cols-2 gap-4">
                                <div class="col-span-2">
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Name
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="shipping_name"
                                            :value="old('shipping_name', trim($order->shipping_address->first_name . ' ' . $order->shipping_address->last_name))"
                                        />

                                        <x-admin::form.control-group.error control-name="shipping_name" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="col-span-2">
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Address
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="shipping_address1"
                                            :value="old('shipping_address1', $order->shipping_address->address)"
                                        />

                                        <x-admin::form.control-group.error control-name="shipping_address1" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            City
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="shipping_city"
                                            :value="old('shipping_city', $order->shipping_address->city)"
                                        />

                                        <x-admin::form.control-group.error control-name="shipping_city" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            State
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="shipping_state"
                                            :value="old('shipping_state', $order->shipping_address->state)"
                                        />

                                        <x-admin::form.control-group.error control-name="shipping_state" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Zip Code
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="shipping_postcode"
                                            :value="old('shipping_postcode', $order->shipping_address->postcode)"
                                        />

                                        <x-admin::form.control-group.error control-name="shipping_postcode" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Country
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="shipping_country"
                                            :value="old('shipping_country', $order->shipping_address->country)"
                                        />

                                        <x-admin::form.control-group.error control-name="shipping_country" />
                                    </x-admin::form.control-group>
                                </div>

                                <div>
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Phone
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="shipping_phone"
                                            :value="old('shipping_phone', $order->shipping_address->phone)"
                                        />

                                        <x-admin::form.control-group.error control-name="shipping_phone" />
                                    </x-admin::form.control-group>
                                </div>
                            </div>
                        </div>
                        @endif

                        @if($order->shipstation_order_id)
                        <div class="box-shadow rounded bg-white dark:bg-gray-900 p-4 mt-4">
                            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                                ShipStation Integration
                            </p>

                            <div class="flex items-center gap-2 mb-3">
                                <input
                                    type="checkbox"
                                    name="update_shipstation"
                                    id="update_shipstation"
                                    value="1"
                                    checked
                                >
                                <label for="update_shipstation" class="text-sm font-medium text-gray-600 dark:text-gray-300">
                                    Also update this order in ShipStation
                                </label>
                            </div>

                            <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1 mb-3">
                                <p><strong>ShipStation Order ID:</strong> {{ $order->shipstation_order_id }}</p>
                                <p><strong>ShipStation Order Number:</strong> {{ $order->shipstation_order_number ?? 'N/A' }}</p>
                            </div>

                            @if(isset($shipstationOrder))
                            <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-800 rounded">
                                <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Current ShipStation Address:</p>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Billing Address</p>
                                        <div class="text-xs text-gray-600 dark:text-gray-400 space-y-0.5">
                                            <p>{{ $shipstationOrder['billTo']['name'] ?? 'N/A' }}</p>
                                            @if(!empty($shipstationOrder['billTo']['company']))
                                            <p>{{ $shipstationOrder['billTo']['company'] }}</p>
                                            @endif
                                            <p>{{ $shipstationOrder['billTo']['street1'] ?? '' }}</p>
                                            @if(!empty($shipstationOrder['billTo']['street2']))
                                            <p>{{ $shipstationOrder['billTo']['street2'] }}</p>
                                            @endif
                                            <p>{{ $shipstationOrder['billTo']['city'] ?? '' }}, {{ $shipstationOrder['billTo']['state'] ?? '' }} {{ $shipstationOrder['billTo']['postalCode'] ?? '' }}</p>
                                            <p>{{ $shipstationOrder['billTo']['country'] ?? 'US' }}</p>
                                            <p>{{ $shipstationOrder['billTo']['phone'] ?? '' }}</p>
                                        </div>
                                    </div>

                                    <div>
                                        <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Shipping Address</p>
                                        <div class="text-xs text-gray-600 dark:text-gray-400 space-y-0.5">
                                            <p>{{ $shipstationOrder['shipTo']['name'] ?? 'N/A' }}</p>
                                            @if(!empty($shipstationOrder['shipTo']['company']))
                                            <p>{{ $shipstationOrder['shipTo']['company'] }}</p>
                                            @endif
                                            <p>{{ $shipstationOrder['shipTo']['street1'] ?? '' }}</p>
                                            @if(!empty($shipstationOrder['shipTo']['street2']))
                                            <p>{{ $shipstationOrder['shipTo']['street2'] }}</p>
                                            @endif
                                            <p>{{ $shipstationOrder['shipTo']['city'] ?? '' }}, {{ $shipstationOrder['shipTo']['state'] ?? '' }} {{ $shipstationOrder['shipTo']['postalCode'] ?? '' }}</p>
                                            <p>{{ $shipstationOrder['shipTo']['country'] ?? 'US' }}</p>
                                            <p>{{ $shipstationOrder['shipTo']['phone'] ?? '' }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>
                        @endif

                        <div class="flex justify-end gap-2 mt-4">
                            <button
                                type="submit"
                                class="primary-button"
                            >
                                Save Address Changes
                            </button>
                        </div>
                    </div>
                </form>
            </x-admin::tabs.item>

            <x-admin::tabs.item title="Shipping Adjustment">
                <form method="POST" action="{{ route('admin.sales.orders.update', $order->id) }}" id="shippingForm" ref="shippingForm">
                    @csrf
                    <input type="hidden" name="form_type" value="shipping">

                    <div class="flex flex-1 flex-col gap-2">
                        <div class="box-shadow rounded bg-white dark:bg-gray-900 p-4">
                            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                                Shipping Rate Adjustment
                            </p>

                            <div class="grid grid-cols-2 gap-4">
                                <div class="col-span-2 bg-blue-50 dark:bg-blue-900 p-3 rounded">
                                    <p class="text-sm font-semibold text-blue-800 dark:text-blue-200 mb-2">
                                        Current Shipping Information
                                    </p>
                                    <div class="text-xs text-blue-700 dark:text-blue-300 space-y-1">
                                        <p><strong>Original Shipping:</strong> ${{ number_format($order->base_shipping_amount, 2) }}</p>
                                        @if($order->additional_shipping_amount > 0)
                                        <p><strong>Additional Shipping:</strong> ${{ number_format($order->additional_shipping_amount, 2) }}</p>
                                        <p><strong>Total Shipping:</strong> ${{ number_format($order->base_shipping_amount + $order->additional_shipping_amount, 2) }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="col-span-2">
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            Additional Shipping Amount
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="number"
                                            name="additional_shipping_amount"
                                            id="additional_shipping_amount"
                                            :value="old('additional_shipping_amount', number_format($order->additional_shipping_amount ?? 0, 2, '.', ''))"
                                            step="0.01"
                                            min="0"
                                            placeholder="0.00"
                                        />

                                        <x-admin::form.control-group.error control-name="additional_shipping_amount" />
                                        <span id="shipping_amount_error" class="text-red-600 text-xs mt-1" style="display: none;"></span>
                                    </x-admin::form.control-group>
                                </div>

                                <div class="col-span-2">
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label>
                                            Reason for Adjustment (Optional)
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="textarea"
                                            name="shipping_adjustment_reason"
                                            :value="old('shipping_adjustment_reason')"
                                            rows="3"
                                            placeholder="e.g., Oversized item requires freight shipping"
                                        />

                                        <x-admin::form.control-group.error control-name="shipping_adjustment_reason" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="col-span-2 bg-yellow-50 dark:bg-yellow-900 p-3 rounded">
                                    <p class="text-xs text-yellow-800 dark:text-yellow-200">
                                        <strong>Note:</strong> When you add additional shipping amount:
                                    </p>
                                    <ul class="text-xs text-yellow-700 dark:text-yellow-300 mt-2 ml-4 list-disc space-y-1">
                                        <li>A Stripe invoice will be created for the additional amount</li>
                                        <li>Customer will receive an email with payment link</li>
                                        <li>Order status will update to "Pending Payment"</li>
                                        <li>Once paid, order will automatically update to "Processing"</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 mt-4">
                            <button
                                type="submit"
                                class="primary-button"
                            >
                                Save Shipping Adjustment
                            </button>
                        </div>
                    </div>
                </form>
            </x-admin::tabs.item>
        </x-admin::tabs>
    </div>

    @push('scripts')
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                const shippingForm = document.getElementById('shippingForm');
                const amountInput = document.getElementById('additional_shipping_amount');
                const errorSpan = document.getElementById('shipping_amount_error');

                if (amountInput) {
                    amountInput.addEventListener('input', function() {
                        const value = this.value;
                        if (value && value.includes('.')) {
                            const parts = value.split('.');
                            if (parts[1] && parts[1].length > 2) {
                                this.value = parseFloat(value).toFixed(2);
                            }
                        }
                    });
                }

                if (shippingForm) {
                    shippingForm.addEventListener('submit', function(e) {
                        const amount = parseFloat(amountInput.value);

                        errorSpan.style.display = 'none';
                        errorSpan.textContent = '';

                        if (!amountInput.value || amountInput.value.trim() === '') {
                            e.preventDefault();
                            errorSpan.textContent = 'Please enter an additional shipping amount.';
                            errorSpan.style.display = 'block';
                            amountInput.focus();
                            return false;
                        }

                        if (isNaN(amount)) {
                            e.preventDefault();
                            errorSpan.textContent = 'Please enter a valid number.';
                            errorSpan.style.display = 'block';
                            amountInput.focus();
                            return false;
                        }

                        if (amount < 0) {
                            e.preventDefault();
                            errorSpan.textContent = 'Amount cannot be negative.';
                            errorSpan.style.display = 'block';
                            amountInput.focus();
                            return false;
                        }

                        if (amount === 0) {
                            e.preventDefault();
                            errorSpan.textContent = 'Please enter an amount greater than 0.';
                            errorSpan.style.display = 'block';
                            amountInput.focus();
                            return false;
                        }

                        const decimalPart = amountInput.value.split('.')[1];
                        if (decimalPart && decimalPart.length > 2) {
                            amountInput.value = amount.toFixed(2);
                        }

                        return true;
                    });
                }
            });
        </script>
    @endpush

</x-admin::layouts>
