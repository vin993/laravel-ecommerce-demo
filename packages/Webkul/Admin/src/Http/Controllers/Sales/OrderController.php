<?php

namespace Webkul\Admin\Http\Controllers\Sales;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Admin\DataGrids\Sales\OrderDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Resources\AddressResource;
use Webkul\Admin\Http\Resources\CartResource;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Webkul\Sales\Repositories\OrderCommentRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;

class OrderController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected OrderRepository $orderRepository,
        protected OrderCommentRepository $orderCommentRepository,
        protected CartRepository $cartRepository,
        protected CustomerGroupRepository $customerGroupRepository,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(OrderDataGrid::class)->process();
        }

        $groups = $this->customerGroupRepository->findWhere([['code', '<>', 'guest']]);

        return view('admin::sales.orders.index', compact('groups'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\View\View
     */
    public function create(int $cartId)
    {
        $cart = $this->cartRepository->find($cartId);

        if (! $cart) {
            return redirect()->route('admin.sales.orders.index');
        }

        $addresses = AddressResource::collection($cart->customer->addresses);

        $cart = new CartResource($cart);

        return view('admin::sales.orders.create', compact('cart', 'addresses'));
    }

    /**
     * Store order
     */
    public function store(int $cartId)
    {
        $cart = $this->cartRepository->findOrFail($cartId);

        Cart::setCart($cart);

        if (Cart::hasError()) {
            return response()->json([
                'message' => trans('admin::app.sales.orders.create.error'),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        Cart::collectTotals();

        try {
            $this->validateOrder();
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $cart = Cart::getCart();

        if (! in_array($cart->payment->method, ['cashondelivery', 'moneytransfer'])) {
            return response()->json([
                'message' => trans('admin::app.sales.orders.create.payment-not-supported'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = (new OrderResource($cart))->jsonSerialize();

        $order = $this->orderRepository->create($data);

        Cart::removeCart($cart);

        session()->flash('order', trans('admin::app.sales.orders.create.order-placed-success'));

        return new JsonResource([
            'redirect'     => true,
            'redirect_url' => route('admin.sales.orders.view', $order->id),
        ]);
    }

    /**
     * Show the view for the specified resource.
     *
     * @return \Illuminate\View\View
     */
    public function view(int $id)
    {
        $order = $this->orderRepository->findOrFail($id);

        $ariItems = \DB::table('ari_partstream_order_items')
            ->where('order_id', $order->id)
            ->get();

        $fulfillmentDetails = \DB::table('order_fulfillment_details')
            ->where('order_id', $order->id)
            ->orderBy('supplier')
            ->orderBy('created_at')
            ->get();

        $fulfillmentBySupplier = $fulfillmentDetails->groupBy('supplier');

        return view('admin::sales.orders.view', compact('order', 'ariItems', 'fulfillmentDetails', 'fulfillmentBySupplier'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return \Illuminate\View\View
     */
    public function edit(int $id)
    {
        $order = $this->orderRepository->findOrFail($id);

        $shipstationOrder = null;

        if ($order->shipstation_order_id) {
            try {
                \Log::info('Attempting to fetch ShipStation order for editing', [
                    'order_id' => $id,
                    'shipstation_order_id' => $order->shipstation_order_id
                ]);

                $shipStationService = app(\App\Services\ShipStationService::class);
                $result = $shipStationService->getOrderById($order->shipstation_order_id);

                \Log::info('ShipStation fetch result', [
                    'success' => $result['success'] ?? false,
                    'has_order' => isset($result['order']),
                    'error' => $result['error'] ?? null
                ]);

                if ($result['success']) {
                    $shipstationOrder = $result['order'];
                    \Log::info('ShipStation order fetched successfully', [
                        'order_keys' => array_keys($shipstationOrder)
                    ]);
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to fetch ShipStation order for editing', [
                    'order_id' => $id,
                    'shipstation_order_id' => $order->shipstation_order_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return view('admin::sales.orders.edit', compact('order', 'shipstationOrder'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update(int $id)
    {
        $order = $this->orderRepository->findOrFail($id);
        $formType = request('form_type', 'address');

        if ($formType === 'shipping') {
            $validatedData = $this->validate(request(), [
                'additional_shipping_amount' => 'required|numeric|min:0.01|regex:/^\d+(\.\d{1,2})?$/',
                'shipping_adjustment_reason' => 'nullable|string|max:500'
            ], [
                'additional_shipping_amount.required' => 'Please enter an additional shipping amount.',
                'additional_shipping_amount.numeric' => 'Please enter a valid number.',
                'additional_shipping_amount.min' => 'Amount must be greater than 0.',
                'additional_shipping_amount.regex' => 'Amount can only have up to 2 decimal places.'
            ]);
        } else {
            $validatedData = $this->validate(request(), [
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email',
                'billing_name' => 'required|string|max:255',
                'billing_address1' => 'required|string|max:255',
                'billing_city' => 'required|string|max:255',
                'billing_state' => 'required|string|max:255',
                'billing_postcode' => 'required|string|max:20',
                'billing_country' => 'required|string|max:2',
                'billing_phone' => 'required|string|max:20',
                'shipping_name' => 'nullable|string|max:255',
                'shipping_address1' => 'nullable|string|max:255',
                'shipping_city' => 'nullable|string|max:255',
                'shipping_state' => 'nullable|string|max:255',
                'shipping_postcode' => 'nullable|string|max:20',
                'shipping_country' => 'nullable|string|max:2',
                'shipping_phone' => 'nullable|string|max:20',
                'update_shipstation' => 'sometimes|boolean'
            ]);
        }

        \DB::beginTransaction();

        try {
            if ($formType === 'address') {
            $nameParts = explode(' ', $validatedData['customer_name'], 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            \Log::info('Order Update - Customer Info', [
                'order_id' => $id,
                'customer_name' => $validatedData['customer_name'],
                'customer_email' => $validatedData['customer_email']
            ]);

            \DB::table('orders')->where('id', $id)->update([
                'customer_first_name' => $firstName,
                'customer_last_name' => $lastName,
                'customer_email' => $validatedData['customer_email'],
                'updated_at' => now()
            ]);

            $billingNameParts = explode(' ', $validatedData['billing_name'], 2);
            $billingFirstName = $billingNameParts[0] ?? '';
            $billingLastName = $billingNameParts[1] ?? '';

            $billingUpdateData = [
                'first_name' => $billingFirstName,
                'last_name' => $billingLastName,
                'address' => $validatedData['billing_address1'],
                'city' => $validatedData['billing_city'],
                'state' => $validatedData['billing_state'],
                'postcode' => $validatedData['billing_postcode'],
                'country' => $validatedData['billing_country'],
                'phone' => $validatedData['billing_phone'],
                'updated_at' => now()
            ];

            \Log::info('Order Update - Billing Address', [
                'order_id' => $id,
                'data' => $billingUpdateData
            ]);

            $billingUpdated = \DB::table('addresses')
                ->where('order_id', $id)
                ->where('address_type', 'order_billing')
                ->update($billingUpdateData);

            \Log::info('Order Update - Billing Address Result', [
                'order_id' => $id,
                'rows_affected' => $billingUpdated
            ]);

            if (isset($validatedData['shipping_name'])) {
                $shippingNameParts = explode(' ', $validatedData['shipping_name'], 2);
                $shippingFirstName = $shippingNameParts[0] ?? '';
                $shippingLastName = $shippingNameParts[1] ?? '';

                $shippingUpdateData = [
                    'first_name' => $shippingFirstName,
                    'last_name' => $shippingLastName,
                    'address' => $validatedData['shipping_address1'],
                    'city' => $validatedData['shipping_city'],
                    'state' => $validatedData['shipping_state'],
                    'postcode' => $validatedData['shipping_postcode'],
                    'country' => $validatedData['shipping_country'] ?? null,
                    'phone' => $validatedData['shipping_phone'],
                    'updated_at' => now()
                ];

                \Log::info('Order Update - Shipping Address', [
                    'order_id' => $id,
                    'data' => $shippingUpdateData
                ]);

                $shippingUpdated = \DB::table('addresses')
                    ->where('order_id', $id)
                    ->where('address_type', 'order_shipping')
                    ->update($shippingUpdateData);

                \Log::info('Order Update - Shipping Address Result', [
                    'order_id' => $id,
                    'rows_affected' => $shippingUpdated
                ]);
            }

            if (request('update_shipstation', false) && $order->shipstation_order_id) {
                try {
                    $shipStationService = app(\App\Services\ShipStationService::class);

                    $updateData = [
                        'customer_email' => $validatedData['customer_email'],
                        'billing_first_name' => $billingFirstName,
                        'billing_last_name' => $billingLastName,
                        'billing_address1' => $validatedData['billing_address1'],
                        'billing_city' => $validatedData['billing_city'],
                        'billing_state' => $validatedData['billing_state'],
                        'billing_postcode' => $validatedData['billing_postcode'],
                        'billing_country' => $validatedData['billing_country'],
                        'billing_phone' => $validatedData['billing_phone']
                    ];

                    if (isset($validatedData['shipping_name'])) {
                        $updateData = array_merge($updateData, [
                            'shipping_first_name' => $shippingFirstName,
                            'shipping_last_name' => $shippingLastName,
                            'shipping_address1' => $validatedData['shipping_address1'],
                            'shipping_city' => $validatedData['shipping_city'],
                            'shipping_state' => $validatedData['shipping_state'],
                            'shipping_postcode' => $validatedData['shipping_postcode'],
                            'shipping_country' => $validatedData['shipping_country'] ?? null,
                            'shipping_phone' => $validatedData['shipping_phone']
                        ]);
                    }

                    $result = $shipStationService->updateOrder($order->shipstation_order_id, $updateData);

                    if ($result['success']) {
                        session()->flash('success', 'Address updated successfully and synced to ShipStation.');
                    } else {
                        session()->flash('warning', 'Address updated successfully, but ShipStation sync failed: ' . $result['error']);
                    }
                } catch (\Exception $e) {
                    \Log::error('ShipStation sync error during order update', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);

                    session()->flash('warning', 'Address updated successfully, but ShipStation sync encountered an error.');
                }
            } else {
                session()->flash('success', 'Address updated successfully.');
            }
            }

            if ($formType === 'shipping') {
            $newShippingCharge = $validatedData['additional_shipping_amount'] ?? 0;
            $currentAdditionalShipping = $order->additional_shipping_amount ?? 0;

            if ($newShippingCharge > 0) {
                $stripeInvoiceService = app(\App\Services\StripeInvoiceService::class);

                $invoiceResult = $stripeInvoiceService->createShippingAdjustmentInvoice(
                    $order,
                    $newShippingCharge,
                    $validatedData['shipping_adjustment_reason'] ?? null
                );

                if ($invoiceResult['success']) {
                    // Add the new charge to the existing additional shipping amount
                    $totalAdditionalShipping = $currentAdditionalShipping + $newShippingCharge;

                    \DB::table('orders')->where('id', $id)->update([
                        'additional_shipping_amount' => $totalAdditionalShipping,
                        'additional_shipping_stripe_invoice_id' => $invoiceResult['invoice_id'],
                        'additional_shipping_invoice_status' => 'open',
                        'pending_payment_amount' => $newShippingCharge,
                        'status' => 'pending_payment',
                        'updated_at' => now()
                    ]);

                    $order->refresh();

                    // Update existing invoices to reflect the new additional shipping (as unpaid)
                    $invoices = \DB::table('invoices')->where('order_id', $id)->get();
                    foreach ($invoices as $invoice) {
                        // Get the current additional shipping in the invoice
                        $currentInvoiceAdditionalShipping = $invoice->additional_shipping_amount ?? 0;

                        // Calculate the base grand total without any additional shipping
                        $baseGrandTotalWithoutAdditional = $invoice->base_grand_total - $currentInvoiceAdditionalShipping;

                        // Calculate new grand total with updated additional shipping
                        $newGrandTotal = $baseGrandTotalWithoutAdditional + $totalAdditionalShipping;

                        \DB::table('invoices')->where('id', $invoice->id)->update([
                            'additional_shipping_amount' => $totalAdditionalShipping,
                            'grand_total' => $newGrandTotal,
                            'base_grand_total' => $newGrandTotal,
                            'updated_at' => now()
                        ]);
                    }

                    // Add order comment for the shipping adjustment
                    $comment = "Shipping adjustment added: $" . number_format($newShippingCharge, 2);
                    if (!empty($validatedData['shipping_adjustment_reason'])) {
                        $comment .= " - Reason: " . $validatedData['shipping_adjustment_reason'];
                    }

                    $this->orderCommentRepository->create([
                        'order_id' => $id,
                        'comment' => $comment,
                        'customer_notified' => false,
                    ]);

                    try {
                        \Mail::to($order->customer_email)->send(
                            new \App\Mail\AdditionalShippingInvoice($order, $invoiceResult)
                        );
                    } catch (\Exception $e) {
                        \Log::error('Failed to send additional shipping invoice email', [
                            'order_id' => $id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    \Log::info('Additional shipping invoice created', [
                        'order_id' => $id,
                        'amount' => $newShippingCharge,
                        'invoice_id' => $invoiceResult['invoice_id'],
                    ]);

                    session()->flash('success', 'Shipping adjustment created. Customer has been emailed the invoice.');
                } else {
                    \DB::rollBack();
                    session()->flash('error', 'Failed to create Stripe invoice: ' . $invoiceResult['error']);
                    return redirect()->back()->withInput();
                }
            } else {
                session()->flash('success', 'Shipping adjustment saved successfully.');
            }
            }

            \DB::commit();

            return redirect()->route('admin.sales.orders.view', $id);

        } catch (\Exception $e) {
            \DB::rollBack();

            \Log::error('Order update failed', [
                'order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            session()->flash('error', 'Failed to update order: ' . $e->getMessage());

            return redirect()->back()->withInput();
        }
    }

    /**
     * Reorder action for the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function reorder(int $id)
    {
        $order = $this->orderRepository->findOrFail($id);

        $cart = Cart::createCart([
            'customer'  => $order->customer,
            'is_active' => false,
        ]);

        Cart::setCart($cart);

        foreach ($order->items as $item) {
            try {
                Cart::addProduct($item->product, $item->additional);
            } catch (\Exception $e) {
                // do nothing
            }
        }

        return redirect()->route('admin.sales.orders.create', $cart->id);
    }

    /**
     * Cancel action for the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function cancel(int $id)
    {
        $result = $this->orderRepository->cancel($id);

        if ($result) {
            session()->flash('success', trans('admin::app.sales.orders.view.cancel-success'));
        } else {
            session()->flash('error', trans('admin::app.sales.orders.view.create-error'));
        }

        return redirect()->route('admin.sales.orders.view', $id);
    }

    /**
     * Add comment to the order
     *
     * @return \Illuminate\Http\Response
     */
    public function comment(int $id)
    {
        $validatedData = $this->validate(request(), [
            'comment'           => 'required',
            'customer_notified' => 'sometimes|sometimes',
        ]);

        $validatedData['order_id'] = $id;

        Event::dispatch('sales.order.comment.create.before');

        $comment = $this->orderCommentRepository->create($validatedData);

        Event::dispatch('sales.order.comment.create.after', $comment);

        session()->flash('success', trans('admin::app.sales.orders.view.comment-success'));

        return redirect()->route('admin.sales.orders.view', $id);
    }

    /**
     * Result of search product.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function search()
    {
        $orders = $this->orderRepository->scopeQuery(function ($query) {
            return $query->where('customer_email', 'like', '%'.urldecode(request()->input('query')).'%')
                ->orWhere('status', 'like', '%'.urldecode(request()->input('query')).'%')
                ->orWhere(DB::raw('CONCAT('.DB::getTablePrefix().'customer_first_name, " ", '.DB::getTablePrefix().'customer_last_name)'), 'like', '%'.urldecode(request()->input('query')).'%')
                ->orWhere('increment_id', request()->input('query'))
                ->orderBy('created_at', 'desc');
        })->paginate(10);

        foreach ($orders as $key => $order) {
            $orders[$key]['formatted_created_at'] = core()->formatDate($order->created_at, 'd M Y');

            $orders[$key]['status_label'] = $order->status_label;

            $orders[$key]['customer_full_name'] = $order->customer_full_name;
        }

        return response()->json($orders);
    }

    /**
     * Validate order before creation.
     *
     * @return void|\Exception
     */
    public function validateOrder()
    {
        $cart = Cart::getCart();

        if (! Cart::haveMinimumOrderAmount()) {
            throw new \Exception(trans('admin::app.sales.orders.create.minimum-order-error', [
                'amount' => core()->formatPrice(core()->getConfigData('sales.order_settings.minimum_order.minimum_order_amount') ?: 0),
            ]));
        }

        if (
            $cart->haveStockableItems()
            && ! $cart->shipping_address
        ) {
            throw new \Exception(trans('admin::app.sales.orders.create.check-shipping-address'));
        }

        if (! $cart->billing_address) {
            throw new \Exception(trans('admin::app.sales.orders.create.check-billing-address'));
        }

        if (
            $cart->haveStockableItems()
            && ! $cart->selected_shipping_rate
        ) {
            throw new \Exception(trans('admin::app.sales.orders.create.specify-shipping-method'));
        }

        if (! $cart->payment) {
            throw new \Exception(trans('admin::app.sales.orders.create.specify-payment-method'));
        }
    }
}
