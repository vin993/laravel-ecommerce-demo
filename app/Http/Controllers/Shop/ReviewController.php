<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Webkul\Product\Repositories\ProductReviewRepository;
use Webkul\Product\Repositories\ProductRepository;

class ReviewController extends Controller
{
    protected $productReviewRepository;
    protected $productRepository;

    public function __construct(
        ProductReviewRepository $productReviewRepository,
        ProductRepository $productRepository
    ) {
        $this->productReviewRepository = $productReviewRepository;
        $this->productRepository = $productRepository;
    }

    public function store(Request $request)
    {
        try {
            if (!auth()->guard('customer')->check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please login to write a review'
                ], 401);
            }

            $validated = $request->validate([
                'product_id' => 'required|integer',
                'rating' => 'required|integer|min:1|max:5',
                'title' => 'required|string|max:255',
                'comment' => 'required|string|max:1000'
            ]);

            $product = $this->productRepository->find($validated['product_id']);

            if (!$product) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Product not found'
                ], 404);
            }

            $customer = auth()->guard('customer')->user();

            $existingReview = $this->productReviewRepository->findWhere([
                'customer_id' => $customer->id,
                'product_id' => $validated['product_id']
            ])->first();

            if ($existingReview) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You have already reviewed this product'
                ], 400);
            }

            $review = $this->productReviewRepository->create([
                'customer_id' => $customer->id,
                'product_id' => $validated['product_id'],
                'name' => $customer->first_name . ' ' . $customer->last_name,
                'rating' => $validated['rating'],
                'title' => $validated['title'],
                'comment' => $validated['comment'],
                'status' => 'approved'
            ]);

            \Log::info('Review created successfully', [
                'review_id' => $review->id,
                'product_id' => $validated['product_id'],
                'customer_id' => $customer->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Review submitted successfully!'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Review validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Review submission error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Error submitting review: ' . $e->getMessage()
            ], 500);
        }
    }
}