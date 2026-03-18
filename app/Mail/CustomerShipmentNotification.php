<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerShipmentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $shipmentData;

    public function __construct($shipmentData)
    {
        $this->shipmentData = $shipmentData;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Order Has Shipped - Tracking Information',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-shipment-notification',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
