<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Webkul\Admin\DataGrids\Catalog\ProductDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\InventoryRequest;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Requests\ProductForm;
use Webkul\Admin\Http\Resources\AttributeResource;
use Webkul\Admin\Http\Resources\ProductResource;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Core\Rules\Slug;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Product\Helpers\ProductType;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Repositories\ProductDownloadableLinkRepository;
use Webkul\Product\Repositories\ProductDownloadableSampleRepository;
use Webkul\Product\Repositories\ProductInventoryRepository;
use Webkul\Product\Repositories\ProductRepository;

class ProductController extends Controller
{
    /*
    * Using const variable for status
    */
    const ACTIVE_STATUS = 1;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected AttributeFamilyRepository $attributeFamilyRepository,
        protected ProductAttributeValueRepository $productAttributeValueRepository,
        protected ProductDownloadableLinkRepository $productDownloadableLinkRepository,
        protected ProductDownloadableSampleRepository $productDownloadableSampleRepository,
        protected ProductInventoryRepository $productInventoryRepository,
        protected ProductRepository $productRepository,
        protected CustomerRepository $customerRepository,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(ProductDataGrid::class)->process();
        }

        $families = $this->attributeFamilyRepository->all();

        return view('admin::catalog.products.index', compact('families'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $families = $this->attributeFamilyRepository->all();

        $configurableFamily = null;

        if ($familyId = request()->get('family')) {
            $configurableFamily = $this->attributeFamilyRepository->find($familyId);
        }

        return view('admin::catalog.products.create', compact('families', 'configurableFamily'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store()
    {
        $this->validate(request(), [
            'type'                => 'required',
            'attribute_family_id' => 'required',
            'sku'                 => ['required', 'unique:products,sku', new Slug],
            'super_attributes'    => 'array|min:1',
            'super_attributes.*'  => 'array|min:1',
        ]);

        if (
            ProductType::hasVariants(request()->input('type'))
            && ! request()->has('super_attributes')
        ) {
            $configurableFamily = $this->attributeFamilyRepository
                ->find(request()->input('attribute_family_id'));

            return new JsonResponse([
                'data' => [
                    'attributes' => AttributeResource::collection($configurableFamily->configurable_attributes),
                ],
            ]);
        }

        Event::dispatch('catalog.product.create.before');

        $product = $this->productRepository->create(request()->only([
            'type',
            'attribute_family_id',
            'sku',
            'super_attributes',
            'family',
        ]));

        Event::dispatch('catalog.product.create.after', $product);

        session()->flash('success', trans('admin::app.catalog.products.create-success'));

        return new JsonResponse([
            'data' => [
                'redirect_url' => route('admin.catalog.products.edit', $product->id),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return \Illuminate\View\View
     */
    public function edit(int $id)
    {
        $product = $this->productRepository->findOrFail($id);

        return view('admin::catalog.products.edit', compact('product'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update(ProductForm $request, int $id)
    {
        Event::dispatch('catalog.product.update.before', $id);

        $product = $this->productRepository->update(request()->all(), $id);

        Event::dispatch('catalog.product.update.after', $product);

        session()->flash('success', trans('admin::app.catalog.products.update-success'));

        return redirect()->route('admin.catalog.products.index');
    }

    /**
     * Update inventories.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateInventories(InventoryRequest $inventoryRequest, int $id)
    {
        $product = $this->productRepository->findOrFail($id);

        Event::dispatch('catalog.product.update.before', $id);

        $this->productInventoryRepository->saveInventories(request()->all(), $product);

        Event::dispatch('catalog.product.update.after', $product);

        return response()->json([
            'message'      => __('admin::app.catalog.products.saved-inventory-message'),
            'updatedTotal' => $this->productInventoryRepository->where('product_id', $product->id)->sum('qty'),
        ]);
    }

    /**
     * Uploads downloadable file.
     *
     * @return \Illuminate\Http\Response
     */
    public function uploadLink(int $id)
    {
        return response()->json(
            $this->productDownloadableLinkRepository->upload(request()->all(), $id)
        );
    }

    /**
     * Copy a given Product.
     *
     * @return \Illuminate\Http\Response
     */
    public function copy(int $id)
    {
        try {
            Event::dispatch('catalog.product.create.before');

            $product = $this->productRepository->copy($id);

            Event::dispatch('catalog.product.create.after', $product);
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());

            return redirect()->to(route('admin.catalog.products.index'));
        }

        return response()->json([
            'message' => trans('admin::app.catalog.products.product-copied'),
        ]);
    }

    /**
     * Uploads downloadable sample file.
     *
     * @return \Illuminate\Http\Response
     */
    public function uploadSample(int $id)
    {
        return response()->json(
            $this->productDownloadableSampleRepository->upload(request()->all(), $id)
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            Event::dispatch('catalog.product.delete.before', $id);

            $this->productRepository->delete($id);

            Event::dispatch('catalog.product.delete.after', $id);

            return new JsonResponse([
                'message' => trans('admin::app.catalog.products.delete-success'),
            ]);
        } catch (\Exception $e) {
            report($e);
        }

        return new JsonResponse([
            'message' => trans('admin::app.catalog.products.delete-failed'),
        ], 500);
    }

    /**
     * Mass delete the products.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $productIds = $massDestroyRequest->input('indices');

        try {
            foreach ($productIds as $productId) {
                $product = $this->productRepository->find($productId);

                if (isset($product)) {
                    Event::dispatch('catalog.product.delete.before', $productId);

                    $this->productRepository->delete($productId);

                    Event::dispatch('catalog.product.delete.after', $productId);
                }
            }

            return new JsonResponse([
                'message' => trans('admin::app.catalog.products.index.datagrid.mass-delete-success'),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mass update the products.
     */
    public function massUpdate(MassUpdateRequest $massUpdateRequest): JsonResponse
    {
        $productIds = $massUpdateRequest->input('indices');

        foreach ($productIds as $productId) {
            Event::dispatch('catalog.product.update.before', $productId);

            $product = $this->productRepository->update([
                'status'  => $massUpdateRequest->input('value'),
            ], $productId, ['status']);

            Event::dispatch('catalog.product.update.after', $product);
        }

        return new JsonResponse([
            'message' => trans('admin::app.catalog.products.index.datagrid.mass-update-success'),
        ], 200);
    }

    /**
     * To be manually invoked when data is seeded into products.
     *
     * @return \Illuminate\Http\Response
     */
    public function sync()
    {
        Event::dispatch('products.datagrid.sync', true);

        return redirect()->route('admin.catalog.products.index');
    }

    /**
     * Result of search product.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function search()
    {
        $query = request('query');
        $channelId = $this->customerRepository->find(request('customer_id'))->channel_id ?? null;

        // Clean query for SKU search (remove special characters)
        $cleanQuery = preg_replace('/[^a-zA-Z0-9]/', '', $query);

        // Split query into words for multi-word search
        $searchWords = array_filter(explode(' ', trim($query)));

        // Convert to lowercase for case-insensitive filtering
        $lowerQuery = strtolower(trim($query));

        // Use ProductFlat for direct database search with enhanced logic
        $productsQuery = \Webkul\Product\Models\ProductFlat::where('channel', core()->getRequestedChannel()->code)
            ->where('locale', app()->getLocale())
            ->where('status', 1);

        // Apply channel filter if provided
        if ($channelId) {
            $productsQuery->whereHas('product.channels', function($q) use ($channelId) {
                $q->where('channel_id', $channelId);
            });
        }

        // Multi-word search logic with SKU search
        $productsQuery->where(function($q) use ($query, $cleanQuery, $searchWords) {
            // First: try exact phrase match in name or SKU
            $q->where(function($exactMatch) use ($query, $cleanQuery) {
                $exactMatch->where('name', 'like', '%' . $query . '%')
                           ->orWhere('sku', 'like', '%' . $query . '%')
                           ->orWhereRaw('REPLACE(REPLACE(REPLACE(sku, "-", ""), " ", ""), "_", "") LIKE ?', ['%' . $cleanQuery . '%']);
            });

            // Second: if multi-word query, search for products containing ALL words
            if (count($searchWords) > 1) {
                $q->orWhere(function($multiWord) use ($searchWords) {
                    foreach ($searchWords as $word) {
                        $multiWord->where(function($wordQuery) use ($word) {
                            $wordQuery->where('name', 'like', '%' . $word . '%')
                                     ->orWhere('sku', 'like', '%' . $word . '%');
                        });
                    }
                });
            }
        });

        // Apply type filter if provided
        if (request()->has('type')) {
            $productsQuery->where('type', request('type'));
        }

        // Smart filtering for specific search terms (oil, tire, helmet)
        if ($lowerQuery === 'oil' || $lowerQuery === 'oils') {
            // For "oil" searches, require specific oil types AND exclude accessories
            $productsQuery->where(function($oilFilter) {
                $oilFilter->whereRaw('LOWER(name) LIKE ?', ['%engine oil%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%motor oil%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%transmission oil%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%gear oil%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%hydraulic oil%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%brake oil%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%fork oil%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%shock oil%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%coolant%'])
                         ->orWhereRaw('LOWER(name) LIKE ?', ['%lubricant%']);
            })
            ->whereRaw('LOWER(name) NOT LIKE ?', ['% kit%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%kit %'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%kits%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%seal%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%filter%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%cap%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%plug%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%gasket%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%o-ring%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%hose%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%tube%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%pipe%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%spout%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%funnel%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%pan%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%drain%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%deflector%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%dipstick%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%gauge%']);
        } elseif ($lowerQuery === 'tire' || $lowerQuery === 'tires' || $lowerQuery === 'tyre' || $lowerQuery === 'tyres') {
            // For "tire" searches, require tire-specific terms AND exclude accessories
            $productsQuery->where(function($tireFilter) {
                $tireFilter->whereRaw('LOWER(name) LIKE ?', ['%tire%'])
                          ->orWhereRaw('LOWER(name) LIKE ?', ['%tyre%']);
            })
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%lever%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%iron%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%tool%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%kit%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%gauge%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%pump%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%valve%']);
        } elseif ($lowerQuery === 'helmet' || $lowerQuery === 'helmets') {
            // For "helmet" searches, require helmet-specific terms AND exclude accessories
            $productsQuery->where(function($helmetFilter) {
                $helmetFilter->whereRaw('LOWER(name) LIKE ?', ['%helmet%']);
            })
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%visor%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%shield%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%strap%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%pad%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%liner%'])
            ->whereRaw('LOWER(name) NOT LIKE ?', ['%lock%']);
        }

        // Get products with relations
        $products = $productsQuery->with([
            'product.attribute_family',
            'product.images',
            'product.inventories',
        ])
        ->orderBy('created_at', 'desc')
        ->limit(20)
        ->get()
        ->map(function($flat) {
            return $flat->product;
        })
        ->filter();

        return ProductResource::collection($products);
    }

    /**
     * Download image or file.
     *
     * @param  int  $productId
     * @param  int  $attributeId
     * @return \Illuminate\Http\Response
     */
    public function download($productId, $attributeId)
    {
        $productAttribute = $this->productAttributeValueRepository->findOneWhere([
            'product_id'   => $productId,
            'attribute_id' => $attributeId,
        ]);

        return Storage::download($productAttribute['text_value']);
    }
}
