<?php

namespace Webkul\AbandonCart\DataGrids;

use Webkul\DataGrid\DataGrid;
use Illuminate\Support\Facades\DB;

class AbandonCartDataGrid extends DataGrid
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
     * Prepare query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function prepareQueryBuilder()
    {
        $hours = core()->getConfigData('abandon_cart.settings.general.hours');
        $days = core()->getConfigData('abandon_cart.settings.general.days');
        $threshold = now()->subHours($hours)->toDateTimeString();

        $queryBuilder = DB::table('cart')
            ->select('id', 'items_count', 'created_at', 'is_mail_sent')
            ->addSelect(DB::raw('CONCAT(' . DB::getTablePrefix() . 'cart.customer_first_name, " ", ' . DB::getTablePrefix() . 'cart.customer_last_name) as customer_name'))
            ->where('is_abandoned', self::ONE)
            ->where('is_active', self::ONE)
            ->where('is_guest', self::ZERO)
            ->whereRaw('(SELECT COUNT(*) FROM ' . DB::getTablePrefix() . 'cart_items WHERE cart_items.cart_id = cart.id AND cart_items.parent_id IS NULL) = cart.items_count')
            ->where('created_at', '<=', $threshold)
            ->whereBetween('created_at', [now()->subDays($days), now()]);

        $this->addFilter('customer_name', DB::raw('CONCAT(' . DB::getTablePrefix() . 'cart.customer_first_name, " ", ' .    DB::getTablePrefix() . 'cart.customer_last_name)'));
        $this->addFilter('is_mail_sent', 'cart.is_mail_sent');

       return $queryBuilder;
    }

    /**
     * Prepare Columns.
     *
     * @return void
     */
    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => trans('abandon_cart::app.admin.datagrid.id'),
            'type'       => 'integer',
            'searchable' => true,
            'sortable'   => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'      => 'customer_name',
            'label'      => trans('abandon_cart::app.admin.datagrid.customer-name'),
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'      => 'items_count',
            'label'      => trans('abandon_cart::app.admin.datagrid.no-of-items'),
            'type'       => 'integer',
            'searchable' => true,
            'sortable'   => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'           => 'created_at',
            'label'           => trans('abandon_cart::app.admin.datagrid.date'),
            'type'            => 'date',
            'searchable'      => false,
            'sortable'        => true,
            'filterable'      => true,
            'filterable_type' => 'date_range',
        ]);

        $this->addColumn([
            'index'      => 'is_mail_sent',
            'label'      => trans('abandon_cart::app.admin.datagrid.mail-sent'),
            'type'       => 'boolean',
            'searchable' => false,
            'sortable'   => true,
            'filterable' => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => [
                [
                    'label'  => trans('abandon_cart::app.admin.datagrid.yes'),
                    'value'  => self::ONE,
                ], [
                    'label'  => trans('abandon_cart::app.admin.datagrid.no'),
                    'value'  => self::ZERO,
                ],
            ],

            'closure'    => function ($row) {
                if ($row->is_mail_sent) {
                    return trans('abandon_cart::app.admin.datagrid.yes');
                }

                return trans('abandon_cart::app.admin.datagrid.no');
            },
        ]);
    }

    /**
     * Prepare actions.
     *
     * @return void
     */
    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-edit',
            'title'  => trans('abandon_cart::app.admin.datagrid.view'),
            'method' => 'GET',
            'url'    => function ($row) {
                return route('admin.customers.abandon-cart.view', $row->id);
            },
        ]);
    }

    /**
     * Prepare Mass Action.
     * 
     * @return void
     */
    public function prepareMassActions()
    {
        $this->addMassAction([
            'title'   => trans('abandon_cart::app.admin.datagrid.send-mail'),
            'method'  => 'POST',
            'url'     => route('admin.customers.abandon-cart.mass-notify'),
        ]);
    }
}