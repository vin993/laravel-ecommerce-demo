<?php

namespace Webkul\AbandonCart\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Controller;
use Webkul\AbandonCart\DataGrids\AbandonCartDataGrid;
use Webkul\AbandonCart\Mail\AbandonCartNotification;
use Webkul\AbandonCart\Repositories\AbondonedCartMailRepository;
use Webkul\Checkout\Repositories\CartItemRepository;
use Webkul\Checkout\Repositories\CartRepository;

class AbandonCartController extends Controller
{
    /**
     * The constant for one abandon cart.
     *
     * @var int
     */
    public const ONE = 1;

    /**
     * The constant for zero abandon cart.
     *
     * @var int
     */
    public const ZERO = 0;

    /**
     * The constant for zero abandon cart.
     *
     * @var string
     */
    public const MANUAL = 'manual';

    /**
     * Create a new controller instance.
  
     * @return void
     */
    public function __construct(
        protected AbondonedCartMailRepository $abondonedCartMailRepository,
        protected CartItemRepository $cartItemRepository,
        protected CartRepository $cartRepository,
    ) {
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(AbandonCartDataGrid::class)->process();
        }

        return view('abandon_cart::admin.customers.abandon-cart.index');
    }

    /**
     * Show the abandon cart.
     *
     * @param  int  $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $cart = $this->cartRepository->findOneWhere([
            'id'           => $id,
            'is_abandoned' => self::ONE,
            'is_active'    => self::ONE,
            'is_guest'     => self::ZERO,
        ]);

        if (! $cart) {
            return redirect()->back();
        }

        if ($cart->items_count) {
            $countCartItems = $this->cartItemRepository->where([
                'cart_id'   => $cart->id,
                'parent_id' => NULL,
            ])->count();

            if ($cart->items_count != $countCartItems) {
                return redirect()->back();
            }
        }

        $mails = $this->abondonedCartMailRepository->where('cart_id', $cart->id)->orderBy('created_at', 'DESC')->get();

        return view('abandon_cart::admin.customers.abandon-cart.view', compact('cart', 'mails'));
    }

    /**
     * Send mail for abandon cart.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function sendMail($id)
    {
        try {
            $cart = $this->cartRepository->findOneWhere(['id' => $id]);

            if ($cart) {
                $this->prepareMail($cart, new AbandonCartNotification($cart));
                
                $cart->is_mail_sent = self::ONE;
                
                $cart->save();

                $data = [
                    'sender_mail' => core()->getSenderEmailDetails()['email'],
                    'cart_id'     => $cart->id,
                    'mail_type'   => self::MANUAL,
                ];

                $this->abondonedCartMailRepository->create($data);

                session()->flash('success', trans('abandon_cart::app.admin.customers.abandon-cart.mail.success'));

                return redirect()->back();
            }

        } catch (\Exception $e) {
            session()->flash('error', trans('abandon_cart::app.admin.customers.abandon-cart.mail.something-wrong'));

            return redirect()->back();
        }
    }
    
    /**
     * Mass Notify customers.
     */
    public function massNotify(): JsonResponse
    {
        $customerIds = request()->input('indices');

        foreach ($customerIds ?? [] as $customerId) {
            $cart = $this->cartRepository->findOneWhere(['id' => $customerId]);

            Mail::send(new AbandonCartNotification($cart));
            
            $cart->is_mail_sent = self::ONE;
            
            $cart->save();
                        
            $data = [
                'sender_mail' => core()->getSenderEmailDetails()['email'],
                'cart_id'     => $cart->id,
                'mail_type'   => self::MANUAL,
            ];

            $this->abondonedCartMailRepository->create($data);
        }

        return new JsonResponse([
            'message' => trans('abandon_cart::app.admin.customers.abandon-cart.mail.success'),
        ]);
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