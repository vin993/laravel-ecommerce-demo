<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminAdditionalShippingPaid extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $invoiceId;
    public $amountPaid;
    public $ariItems;
    public $orderItems;

    public function __construct($order, $invoiceId, $amountPaid)
    {
        $this->order = $order;
        $this->invoiceId = $invoiceId;
        $this->amountPaid = $amountPaid;

        // Fetch regular order items
        $this->orderItems = \DB::table('order_items')
            ->where('order_id', $order->id)
            ->get();

        // Fetch ARI/OEM items
        $this->ariItems = \DB::table('ari_partstream_order_items')
            ->where('order_id', $order->id)
            ->get();
    }

    public function build()
    {
        return $this->subject('Additional Shipping Payment Received - Order #' . $this->order->increment_id)
                    ->view('emails.admin-additional-shipping-paid')
                    ->with([
                        'order' => $this->order,
                        'invoiceId' => $this->invoiceId,
                        'amountPaid' => $this->amountPaid,
                        'orderItems' => $this->orderItems,
                        'ariItems' => $this->ariItems
                    ]);
    }
}
