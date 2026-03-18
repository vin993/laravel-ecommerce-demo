<?php

namespace Webkul\Shop\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactUs extends Mailable
{
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(public $contactUs) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $contactEmails = env('CONTACT_ADMIN_EMAIL', config('mail.from.address', 'admin@example.com'));
        $contactName = env('CONTACT_ADMIN_NAME', config('mail.from.name', 'Admin'));

        $emailArray = array_map('trim', explode(',', $contactEmails));

        $toAddresses = [];
        foreach ($emailArray as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $toAddresses[] = new Address($email, $contactName);
            }
        }

        if (empty($toAddresses)) {
            $toAddresses[] = new Address(config('mail.from.address', 'admin@example.com'), $contactName);
        }

        return new Envelope(
            to: $toAddresses,
            subject: trans('shop::app.emails.contact-us.inquiry-from').' '.$this->contactUs['name'].' '.trans('shop::app.emails.contact-us.contact-from'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-us',
            with: [
                'contactUs' => $this->contactUs,
            ],
        );
    }
}
