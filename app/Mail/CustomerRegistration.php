<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class CustomerRegistration extends Mailable
{
    use Queueable, SerializesModels;

    public $customer;

    public function __construct($customer)
    {
        $this->customer = $customer;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [new Address($this->customer->email, $this->customer->first_name)],
            replyTo: [new Address(env('MAIL_FROM_ADDRESS', 'info@maddparts.com'), env('MAIL_FROM_NAME', 'MaddParts'))],
            subject: 'Welcome to ' . config('app.name', 'Madd Parts') . '!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'maddparts::emails.customers.registration',
        );
    }
}
