<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class CustomerOrderConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $cartItems;
    public $ariItems;
    public $shippingData;
    public $totals;

    /**
     * Create a new message instance.
     */
    public function __construct($order, $cartItems, $ariItems, $shippingData, $totals)
    {
        $this->order = $order;
        $this->cartItems = $cartItems;
        $this->ariItems = $ariItems;
        $this->shippingData = $shippingData;
        $this->totals = $totals;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            to: [new Address($this->order->customer_email, $this->order->customer_first_name)],
            replyTo: [new Address(env('MAIL_FROM_ADDRESS', 'info@maddparts.com'), env('MAIL_FROM_NAME', 'MaddParts'))],
            subject: 'Order Confirmation - ' . $this->order->increment_id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'maddparts::emails.customer-order-confirmation',
        );
    }
}
