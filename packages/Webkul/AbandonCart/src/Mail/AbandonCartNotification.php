<?php

namespace Webkul\AbandonCart\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Webkul\Checkout\Contracts\Cart;
use Webkul\Shop\Mail\Mailable;

class AbandonCartNotification extends Mailable
{
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(public Cart $cart) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            to: [
                new Address(
                    $this->cart->customer_email,
                ),
            ],
            subject: trans('abandon_cart::app.admin.customers.abandon-cart.mail.subject'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'abandon_cart::admin.emails.cart-abandon',
        );
    }
}