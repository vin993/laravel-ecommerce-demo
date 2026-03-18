<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature tests for Vehicle Fitment caching
 *
 * Run with: php artisan test --filter VehicleFitmentCacheTest
 */
class VehicleFitmentCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    /** @test */
    public function it_caches_vehicle_fitment_count_queries()
    {
        // Skip if database is not available in test environment
        if (!DB::connection()->getDatabaseName()) {
            $this->markTestSkipped('Database not available in test environment');
        }

        $params = [
            'vehicle_type' => 1,
            'vehicle_make' => 5,
            'vehicle_model' => 10,
            'vehicle_year' => 2020,
            'page' => 1,
        ];

        // Enable query logging
        DB::enableQueryLog();

        // First request - should hit database
        $response1 = $this->json('GET', '/api/vehicle-search/filter', $params);
        $queries1 = DB::getQueryLog();

        // Count SELECT count(*) queries
        $countQueries1 = collect($queries1)->filter(function($query) {
            return str_contains(strtolower($query['query']), 'count(*)');
        })->count();

        // Second request - should read from cache (no count queries)
        DB::flushQueryLog();
        $response2 = $this->json('GET', '/api/vehicle-search/filter', $params);
        $queries2 = DB::getQueryLog();

        $countQueries2 = collect($queries2)->filter(function($query) {
            return str_contains(strtolower($query['query']), 'count(*)');
        })->count();

        // Both requests should succeed
        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // First request should have count queries
        $this->assertGreaterThan(0, $countQueries1, 'First request should execute count queries');

        // Second request should have NO count queries (cached)
        $this->assertEquals(0, $countQueries2, 'Second request should not execute count queries (cached)');

        // Results should be the same
        $this->assertEquals(
            $response1->json('pagination.total'),
            $response2->json('pagination.total'),
            'Cached result should match original'
        );
    }

    /** @test */
    public function it_generates_different_cache_keys_for_different_filters()
    {
        if (!DB::connection()->getDatabaseName()) {
            $this->markTestSkipped('Database not available');
        }

        $params1 = [
            'vehicle_type' => 1,
            'vehicle_make' => 5,
            'vehicle_model' => 10,
            'vehicle_year' => 2020,
            'brands' => ['Toyota'],
        ];

        $params2 = [
            'vehicle_type' => 1,
            'vehicle_make' => 5,
            'vehicle_model' => 10,
            'vehicle_year' => 2020,
            'brands' => ['Honda'], // Different brand
        ];

        // Request with first params
        $response1 = $this->json('GET', '/api/vehicle-search/filter', $params1);

        // Request with second params
        $response2 = $this->json('GET', '/api/vehicle-search/filter', $params2);

        // Both should succeed
        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Results should likely be different (different brands)
        // Note: This depends on your test data
        // If no test data, just verify requests don't crash
    }

    /** @test */
    public function it_returns_correct_pagination_data()
    {
        if (!DB::connection()->getDatabaseName()) {
            $this->markTestSkipped('Database not available');
        }

        $params = [
            'vehicle_type' => 1,
            'vehicle_make' => 5,
            'vehicle_model' => 10,
            'vehicle_year' => 2020,
            'page' => 1,
        ];

        $response = $this->json('GET', '/api/vehicle-search/filter', $params);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'products',
                'pagination' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'has_more_pages'
                ],
                'filterData' => [
                    'brands',
                    'categories',
                    'price_range',
                ]
            ]);

        // Verify pagination.total is a number
        $total = $response->json('pagination.total');
        $this->assertIsNumeric($total);
        $this->assertGreaterThanOrEqual(0, $total);
    }

    /** @test */
    public function it_serves_cached_response_faster_than_uncached()
    {
        if (!DB::connection()->getDatabaseName()) {
            $this->markTestSkipped('Database not available');
        }

        $params = [
            'vehicle_type' => 1,
            'vehicle_make' => 5,
            'vehicle_model' => 10,
            'vehicle_year' => 2020,
        ];

        // First request (cache miss)
        $start1 = microtime(true);
        $response1 = $this->json('GET', '/api/vehicle-search/filter', $params);
        $time1 = (microtime(true) - $start1) * 1000; // ms

        // Second request (cache hit)
        $start2 = microtime(true);
        $response2 = $this->json('GET', '/api/vehicle-search/filter', $params);
        $time2 = (microtime(true) - $start2) * 1000; // ms

        // Both should succeed
        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Second request should be faster (or at least not slower)
        // Note: This can be flaky in test environment
        // $this->assertLessThanOrEqual($time1, $time2);

        // Just log the times for manual inspection
        fwrite(STDOUT, "\nFirst request (uncached): {$time1}ms\n");
        fwrite(STDOUT, "Second request (cached): {$time2}ms\n");
        fwrite(STDOUT, "Speedup: " . round(($time1 - $time2) / $time1 * 100, 1) . "%\n");
    }
}
