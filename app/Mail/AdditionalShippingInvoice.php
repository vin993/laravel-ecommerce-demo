<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdditionalShippingInvoice extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $invoiceResult;
    public $ariItems;

    public function __construct($order, $invoiceResult)
    {
        $this->order = $order;
        $this->invoiceResult = $invoiceResult;

        $this->ariItems = \DB::table('ari_partstream_order_items')
            ->where('order_id', $order->id)
            ->get();
    }

    public function build()
    {
        return $this->subject('Additional Shipping Charges for Order #' . $this->order->increment_id)
                    ->view('emails.additional-shipping-invoice')
                    ->with([
                        'order' => $this->order,
                        'invoiceResult' => $this->invoiceResult,
                        'ariItems' => $this->ariItems
                    ]);
    }
}
