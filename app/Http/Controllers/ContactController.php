<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Webkul\Shop\Mail\ContactUs;

class ContactController extends Controller
{
    public function send(ContactRequest $request)
    {
        Log::info('Contact controller reached', [
            'data' => $request->only(['name', 'email', 'contact', 'message'])
        ]);

        try {
            $contactData = $request->only([
                'name',
                'email',
                'contact',
                'message',
            ]);

            Log::info('Attempting to send contact email', [
                'to' => env('CONTACT_ADMIN_EMAIL'),
                'from' => $contactData['email']
            ]);

            Mail::send(new ContactUs($contactData));

            Log::info('Contact email sent successfully');

            return redirect()->route('shop.home.contact')->with('success', 'Thank you for contacting us! We will get back to you soon.');
        } catch (\Exception $e) {
            Log::error('Contact form error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return redirect()->route('shop.home.contact')->with('error', 'Unable to send message. Please try again or email us directly.');
        }
    }
}
