<?php

namespace Webkul\AbandonCart\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Webkul\AbandonCart\Mail\AbandonCartNotification;
use Webkul\AbandonCart\Repositories\AbondonedCartMailRepository;
use Webkul\Checkout\Models\Cart;
use Webkul\Checkout\Repositories\CartRepository;

class AbandonCartMail extends Command
{
    /**
     * The constant for one abandon cart.
     *
     * @var int
     */
    public const ONE = 1;

    /**
     * The constant for one abandon cart.
     *
     * @var int
     */
    public const TWO = 2;

    /**
     * The constant for zero abandon cart.
     *
     * @var int
     */
    public const ZERO = 0;

    /**
     * The constant for zero abandon cart.
     *
     * @var int
     */
    public const AUTO = 'auto';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'abandoncart-mail:send';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically send abandon cart mail to customers.';

    /**
     * Create a new command instance.
     *
     */
    public function __construct(
        protected AbondonedCartMailRepository $abandonedCartMailRepository,
        protected CartRepository $cartRepository,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $days = core()->getConfigData('abandon_cart.settings.general.days');

        $secondMailDays = core()->getConfigData('abandon_cart.settings.general.second-mail');

        $thirdMailDays = core()->getConfigData('abandon_cart.settings.general.third-mail');
        
        $successCount = 0;

        if (core()->getConfigData('abandon_cart.settings.general.status')) {
            $abandonedCarts = Cart::where([
                'is_abandoned' => self::ONE,
                'is_active'    => self::ONE,
                'is_guest'     => self::ZERO,
            ])
            ->whereBetween('created_at', [now()->subDays($days), now()])
            ->get();

            foreach ($abandonedCarts as $cart) {
                $cartMails = $this->abandonedCartMailRepository->findWhere([
                    'cart_id'   => $cart->id, 
                    'mail_type' => self::AUTO,
                ]);

                if (empty($cartMails->count())) {
                    $this->sendMail($cart);

                    $successCount++;

                    continue;
                }

                $cartMail = $cartMails->last();

                if (
                    $cartMails->count() == self::ONE
                    && now()->greaterThanOrEqualTo($cartMail->created_at->addDays($secondMailDays))
                ) {
                    $this->sendMail($cart);

                    $successCount++;

                    continue;
                }

                if (
                    $cartMails->count() == self::TWO
                    && now()->greaterThanOrEqualTo($cartMail->created_at->addDays($thirdMailDays))
                ) {
                    $this->sendMail($cart);

                    $successCount++;
                    
                    continue;
                }
            }

            $this->info(trans('abandon_cart::app.admin.customers.abandon-cart.mail.auto-mail', ['count' => $successCount]));
        }
    }

    /**
     * To send the mail according to the cart.
     * 
     * @param mixed $cart
     * @return void
     */
    public function sendMail($cart)
    {
        $this->prepareMail($cart, new AbandonCartNotification($cart));
    
        $data = [
            'sender_mail' => core()->getSenderEmailDetails()['email'],
            'cart_id'     => $cart->id,
            'mail_type'   => self::AUTO,
        ];

        $this->abandonedCartMailRepository->create($data);
        
        $this->cartRepository->findOneWhere(['id' => $cart->id])->update(['is_mail_sent' => self::ONE]);
    }
    
    /**
     * Prepare mail.
     *
     * @return void
     */
    protected function prepareMail($entity, $notification)
    {
        $customerLocale = $this->getLocale($entity);

        $previousLocale = core()->getCurrentLocale()->code;

        app()->setLocale($customerLocale);

        try {
            Mail::queue($notification);
        } catch (\Exception $e) {
            \Log::error('Error in Sending Email'.$e->getMessage());
        }

        app()->setLocale($previousLocale);
    }

    /**
     * @return string
     */
    protected function getLocale($object)
    {
        return $object->locale ?? 'en';
    }
}