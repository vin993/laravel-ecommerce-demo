<?php

namespace Webkul\AbandonCart\Listeners;

use Webkul\Checkout\Repositories\CartRepository;

class Order
{
    /**
     * The constant for one abandon cart.
     *
     * @var int
     */
    public const ZERO = 0;

    /**
     * Create a new event instance.
     * 
     * @return void
     */
    public function __construct(
        protected CartRepository $cartRepository,
    ) {
    }

    /**
     * Disable abandon cart after order.
     *
     * @param  \Webkul\Sales\Contracts\Order $order
     * @return void
     */
    public function placeAfter($order)
    {
        $this->cartRepository->findOneWhere(['id' => $order->cart_id])->update(['is_abandoned' => self::ZERO]);
    }
}