<?php

namespace Webkul\AbandonCart\Repositories;

use Webkul\AbandonCart\Contracts\AbondonedCartMail;
use Webkul\Core\Eloquent\Repository;

class AbondonedCartMailRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return AbondonedCartMail::class;
    }
}