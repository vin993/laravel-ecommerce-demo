<?php

namespace Webkul\AbandonCart\Listeners;

use Illuminate\Support\Facades\Mail;
use Webkul\Checkout\Repositories\CartRepository;

class Cart
{
    /**
     * The constant for one abandon cart.
     *
     * @var int
     */
    public const ONE = 1;

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
     * Set abandon cart.
     *
     * @param  \Webkul\Checkout\Contracts\Cart $cart
     * @return void
     */
    public function addAfter($cart)
    {
        if (core()->getConfigData('abandon_cart.settings.general.status')) {
            $this->cartRepository->findOneWhere(['id' => $cart->id])->update(['is_abandoned' => self::ONE]);
        }

        $this->cartRepository->findOneWhere(['id' => $cart->id])->update(['locale' => core()->getCurrentLocale()->code]);
    }
}