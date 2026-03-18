<?php

namespace App\Payment;

class StripePaymentMethod
{
    /**
     * Payment method code
     */
    protected $code = 'stripe';

    /**
     * Get payment method code
     *
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Get payment method title
     *
     * @return string
     */
    public function getTitle()
    {
        return 'Stripe Payment';
    }

    /**
     * Get payment method description
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Pay securely with credit/debit card via Stripe';
    }

    /**
     * Get sort order
     *
     * @return int
     */
    public function getSortOrder()
    {
        return 1;
    }

    /**
     * Get payment method image
     *
     * @return string
     */
    public function getImage()
    {
        return '';
    }

    /**
     * Check if payment method is available
     *
     * @return bool
     */
    public function isAvailable()
    {
        return true;
    }

    /**
     * Get redirect URL (not used for Stripe as it's handled via JavaScript)
     *
     * @return string
     */
    public function getRedirectUrl()
    {
        return '';
    }

    /**
     * Get additional payment details for admin view
     *
     * @return array
     */
    public function getAdditionalDetails()
    {
        return [
            'title' => 'Stripe Payment',
            'value' => 'Secure card payment via Stripe',
            'description' => 'Payment processed through Stripe',
            'logo' => asset('images/stripe-logo.png'),
        ];
    }
}
