<?php

namespace App\Payment;

class PaypalPaymentMethod
{
    protected $code = 'paypal';

    public function getCode()
    {
        return $this->code;
    }

    public function getTitle()
    {
        return 'PayPal';
    }

    public function getDescription()
    {
        return 'Pay securely with PayPal';
    }

    public function getSortOrder()
    {
        return 2;
    }

    public function getImage()
    {
        return '';
    }

    public function isAvailable()
    {
        return true;
    }

    public function getRedirectUrl()
    {
        return '';
    }

    public function getAdditionalDetails()
    {
        return [
            'title' => 'PayPal Payment',
            'value' => 'Secure payment via PayPal',
            'description' => 'Payment processed through PayPal',
            'logo' => asset('images/paypal-logo.png'),
        ];
    }
}
