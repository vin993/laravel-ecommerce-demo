<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class AdminOrderNotification extends Mailable
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
        // Get admin emails from env (comma-separated)
        $adminEmails = env('ADMIN_ORDER_EMAILS', env('MAIL_FROM_ADDRESS', 'admin@maddparts.com'));
        $emailList = array_map('trim', explode(',', $adminEmails));

        $toAddresses = [];
        foreach ($emailList as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $toAddresses[] = new Address($email);
            }
        }

        return new Envelope(
            to: $toAddresses,
            subject: 'New Order Received - ' . $this->order->increment_id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'maddparts::emails.admin-order-notification',
        );
    }
}
