<?php

namespace Webkul\Shop\Listeners;

use Webkul\Shop\Mail\Order\InvoicedNotification;

class Invoice extends Base
{
    /**
     * After order is created
     *
     * @param  \Webkul\Sale\Contracts\Invoice  $invoice
     * @return void
     */
    public function afterCreated($invoice)
    {
        try {
            $isManualSend = property_exists($invoice, 'email')
                && isset($invoice->email)
                && !empty($invoice->email);

            \Log::info('Invoice email listener triggered', [
                'invoice_id' => $invoice->id,
                'isManualSend' => $isManualSend,
                'email_sent_flag' => $invoice->email_sent,
                'custom_email' => $invoice->email ?? null,
            ]);

            if (! core()->getConfigData('emails.general.notifications.emails.general.notifications.new_invoice')) {
                \Log::warning('Invoice email disabled in config');
                return;
            }

            if (!$isManualSend && $invoice->email_sent == 1) {
                \Log::info('Skipping email - already sent and not manual send');
                return;
            }

            \Log::info('Sending invoice email', [
                'to_email' => $invoice->email ?? $invoice->order->customer_email,
            ]);

            $this->prepareMail($invoice, new InvoicedNotification($invoice));

            if (!$isManualSend) {
                $invoice->query()->update(['email_sent' => 1]);
            }

            \Log::info('Invoice email sent successfully');
        } catch (\Exception $e) {
            \Log::error('Invoice email error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);
        }
    }
}
