<?php

namespace App\Models;

use Webkul\Customer\Models\Customer as BaseCustomer;
use App\Notifications\CustomerResetPasswordNotification;

class Customer extends BaseCustomer
{
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CustomerResetPasswordNotification($token));
    }
}
