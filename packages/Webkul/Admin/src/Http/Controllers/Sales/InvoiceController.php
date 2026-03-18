<?php

namespace Webkul\Admin\Http\Controllers\Sales;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Webkul\Admin\DataGrids\Sales\OrderInvoiceDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Core\Traits\PDFHandler;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;

class InvoiceController extends Controller
{
    use PDFHandler;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected OrderRepository $orderRepository,
        protected InvoiceRepository $invoiceRepository,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(OrderInvoiceDataGrid::class)->process();
        }

        return view('admin::sales.invoices.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\View\View
     */
    public function create(int $orderId)
    {
        $order = $this->orderRepository->findOrFail($orderId);

        if ($order->payment->method === 'paypal_standard') {
            abort(404);
        }

        return view('admin::sales.invoices.create', compact('order'));
    }

    /**
     * (Store) a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(int $orderId)
    {
        $order = $this->orderRepository->findOrFail($orderId);

        if (! $order->canInvoice()) {
            session()->flash('error', trans('admin::app.sales.invoices.create.creation-error'));

            return redirect()->back();
        }

        $hasRegularItems = request()->has('invoice.items') && !empty(request()->input('invoice.items'));
        $hasAriItems = request()->has('invoice.ari_items') && !empty(request()->input('invoice.ari_items'));

        if (!$hasRegularItems && !$hasAriItems) {
            session()->flash('error', trans('admin::app.sales.invoices.create.product-error'));
            return redirect()->back();
        }

        if ($hasRegularItems) {
            $this->validate(request(), [
                'invoice.items'   => 'required|array',
                'invoice.items.*' => 'required|numeric|min:0',
            ]);

            if (! $this->invoiceRepository->haveProductToInvoice(request()->all())) {
                session()->flash('error', trans('admin::app.sales.invoices.create.product-error'));
                return redirect()->back();
            }

            if (! $this->invoiceRepository->isValidQuantity(request()->all())) {
                session()->flash('error', trans('admin::app.sales.invoices.create.invalid-qty'));
                return redirect()->back();
            }
        }

        if ($hasAriItems) {
            $this->validate(request(), [
                'invoice.ari_items'   => 'array',
                'invoice.ari_items.*' => 'required|numeric|min:0',
            ]);
        }

        $this->invoiceRepository->create(array_merge(request()->all(), [
            'order_id' => $orderId,
        ]));

        session()->flash('success', trans('admin::app.sales.invoices.create.create-success'));

        return redirect()->route('admin.sales.orders.view', $orderId);
    }

    /**
     * Show the view for the specified resource.
     *
     * @return \Illuminate\View\View
     */
    public function view(int $id)
    {
        $invoice = $this->invoiceRepository->findOrFail($id);

        return view('admin::sales.invoices.view', compact('invoice'));
    }

    /**
     * Send duplicate invoice.
     *
     * @return \Illuminate\Http\Response
     */
    public function sendDuplicateEmail(Request $request, int $id)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $invoice = $this->invoiceRepository->findOrFail($id);

        $invoice->email = request()->input('email');

        Event::dispatch('sales.invoice.send_duplicate_email', $invoice);

        session()->flash('success', trans('admin::app.sales.invoices.view.invoice-sent'));

        return redirect()->route('admin.sales.invoices.view', $invoice->id);
    }

    /**
     * Print and download the for the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function printInvoice(int $id)
    {
        $invoice = $this->invoiceRepository->findOrFail($id);

        return $this->downloadPDF(
            view('admin::sales.invoices.pdf', compact('invoice'))->render(),
            'invoice-'.$invoice->created_at->format('d-m-Y')
        );
    }

    /**
     * View invoice PDF inline for printing.
     *
     * @return \Illuminate\Http\Response
     */
    public function viewInvoicePDF(int $id)
    {
        $invoice = $this->invoiceRepository->findOrFail($id);

        $html = view('admin::sales.invoices.pdf', compact('invoice'))->render();
        $fileName = 'invoice-'.$invoice->created_at->format('d-m-Y');

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        // Generate PDF binary
        if (($direction = core()->getCurrentLocale()->direction) == 'rtl') {
            $mPDF = new \Mpdf\Mpdf([
                'margin_left'   => 0,
                'margin_right'  => 0,
                'margin_top'    => 0,
                'margin_bottom' => 0,
            ]);
            $mPDF->SetDirectionality($direction);
            $mPDF->SetDisplayMode('fullpage');
            $mPDF->WriteHTML($this->adjustArabicAndPersianContent($html));
            $pdfContent = $mPDF->Output('', 'S');
        } else {
            $pdfContent = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->adjustArabicAndPersianContent($html))
                ->setPaper('A4', 'portrait')
                ->set_option('defaultFont', 'Courier')
                ->output();
        }

        // Encode PDF as base64 for embedding
        $pdfBase64 = base64_encode($pdfContent);

        // Return HTML page with embedded PDF and auto-print
        // Using object tag with print-specific JavaScript
        $autoprint_html = '
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Invoice - '.$fileName.'</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body, html {
                    margin: 0;
                    padding: 0;
                    width: 100%;
                    height: 100vh;
                    overflow: hidden;
                }
                #pdfEmbed {
                    width: 100%;
                    height: 100%;
                    border: none;
                }
            </style>
        </head>
        <body>
            <embed id="pdfEmbed" src="data:application/pdf;base64,'.$pdfBase64.'" type="application/pdf" width="100%" height="100%">
            <script>
                // Auto-print when page loads
                window.addEventListener("load", function() {
                    setTimeout(function() {
                        window.print();
                    }, 1200);
                });

                // Also trigger on PDF load if possible
                document.addEventListener("DOMContentLoaded", function() {
                    setTimeout(function() {
                        window.print();
                    }, 1500);
                });
            </script>
        </body>
        </html>
        ';

        return response($autoprint_html)
            ->header('Content-Type', 'text/html');
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function massUpdateState(MassUpdateRequest $massUpdateRequest)
    {
        $invoiceIds = $massUpdateRequest->input('indices');

        $invoices = $this->invoiceRepository->findWhereIn('id', $invoiceIds);

        foreach ($invoices as $invoice) {
            $invoice->state = $massUpdateRequest->input('value');

            $invoice->save();
        }

        return new JsonResponse([
            'message' => trans('admin::app.sales.invoices.index.datagrid.mass-update-success'),
        ], 200);
    }
}
