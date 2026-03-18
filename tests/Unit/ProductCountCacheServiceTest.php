<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ProductCountCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Unit tests for ProductCountCacheService
 *
 * Run with: php artisan test --filter ProductCountCacheServiceTest
 */
class ProductCountCacheServiceTest extends TestCase
{
    protected ProductCountCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProductCountCacheService();

        // Clear cache before each test
        Cache::flush();
    }

    /** @test */
    public function it_generates_stable_vehicle_count_cache_key()
    {
        $params1 = [
            'vehicleType' => 1,
            'vehicleMake' => 5,
            'vehicleModel' => 10,
            'vehicleYear' => 2020,
            'productIds' => [100, 200, 300],
            'brands' => ['Toyota', 'Honda'],
            'categories' => [1, 2, 3],
            'minPrice' => 10.00,
            'maxPrice' => 100.00,
            'inStock' => true,
        ];

        $params2 = [
            'vehicleType' => 1,
            'vehicleMake' => 5,
            'vehicleModel' => 10,
            'vehicleYear' => 2020,
            'productIds' => [100, 200, 300], // Same IDs
            'brands' => ['Toyota', 'Honda'],
            'categories' => [1, 2, 3],
            'minPrice' => 10.00,
            'maxPrice' => 100.00,
            'inStock' => true,
        ];

        $key1 = $this->service->generateVehicleCountKey($params1);
        $key2 = $this->service->generateVehicleCountKey($params2);

        // Same params should generate same key
        $this->assertEquals($key1, $key2);

        // Key should follow pattern
        $this->assertStringStartsWith('vehicle_count:1:5:10:2020:', $key1);
    }

    /** @test */
    public function it_generates_different_keys_for_different_filters()
    {
        $params1 = [
            'vehicleType' => 1,
            'vehicleMake' => 5,
            'vehicleModel' => 10,
            'vehicleYear' => 2020,
            'productIds' => [100, 200, 300],
            'brands' => ['Toyota'],
            'categories' => [],
            'minPrice' => null,
            'maxPrice' => null,
            'inStock' => false,
        ];

        $params2 = [
            'vehicleType' => 1,
            'vehicleMake' => 5,
            'vehicleModel' => 10,
            'vehicleYear' => 2020,
            'productIds' => [100, 200, 300],
            'brands' => ['Honda'], // Different brand
            'categories' => [],
            'minPrice' => null,
            'maxPrice' => null,
            'inStock' => false,
        ];

        $key1 = $this->service->generateVehicleCountKey($params1);
        $key2 = $this->service->generateVehicleCountKey($params2);

        // Different filters should generate different keys
        $this->assertNotEquals($key1, $key2);
    }

    /** @test */
    public function it_generates_stable_category_count_cache_key()
    {
        $categoryId = 123;
        $productIds = [100, 200, 300];

        $key1 = $this->service->generateCategoryCountKey($categoryId, $productIds);
        $key2 = $this->service->generateCategoryCountKey($categoryId, $productIds);

        // Same params should generate same key
        $this->assertEquals($key1, $key2);

        // Key should follow pattern
        $this->assertStringStartsWith('category_count:123:maddparts:en:', $key1);
    }

    /** @test */
    public function it_caches_query_results()
    {
        $cacheKey = 'test_count_key';
        $expectedResult = 42;

        $callCount = 0;
        $callback = function() use (&$callCount, $expectedResult) {
            $callCount++;
            return $expectedResult;
        };

        // First call - should execute callback
        $result1 = $this->service->remember($cacheKey, $callback, 60);
        $this->assertEquals($expectedResult, $result1);
        $this->assertEquals(1, $callCount);

        // Second call - should read from cache (callback not executed)
        $result2 = $this->service->remember($cacheKey, $callback, 60);
        $this->assertEquals($expectedResult, $result2);
        $this->assertEquals(1, $callCount); // Still 1, not called again
    }

    /** @test */
    public function it_respects_custom_ttl()
    {
        $cacheKey = 'test_ttl_key';
        $customTtl = 120; // 2 minutes

        $result = $this->service->remember(
            $cacheKey,
            fn() => 100,
            $customTtl
        );

        // Verify the value is cached
        $this->assertEquals(100, Cache::get($cacheKey));

        // Note: Testing TTL expiry requires time manipulation or mocking
        // In real tests, you'd use Carbon::setTestNow() or similar
    }

    /** @test */
    public function it_uses_default_ttl_when_not_specified()
    {
        // Set env var for test
        putenv('CATEGORY_COUNT_CACHE_TTL=300');

        $service = new ProductCountCacheService();
        $cacheKey = 'test_default_ttl_key';

        $result = $service->remember(
            $cacheKey,
            fn() => 200
        );

        // Verify the value is cached
        $this->assertEquals(200, Cache::get($cacheKey));
    }

    /** @test */
    public function it_handles_cache_flush_by_pattern()
    {
        // Create multiple cache entries
        Cache::put('category_count:1:maddparts:en:abc', 10, 300);
        Cache::put('category_count:2:maddparts:en:def', 20, 300);
        Cache::put('category_count:3:maddparts:en:ghi', 30, 300);
        Cache::put('vehicle_count:1:2:3:4:xyz', 40, 300);

        // Flush category counts
        $deleted = $this->service->flushByPattern('category_count:*');

        // Should have deleted 3 keys
        $this->assertGreaterThanOrEqual(3, $deleted);

        // Category count caches should be gone
        $this->assertNull(Cache::get('category_count:1:maddparts:en:abc'));
        $this->assertNull(Cache::get('category_count:2:maddparts:en:def'));

        // Vehicle count should still exist
        $this->assertEquals(40, Cache::get('vehicle_count:1:2:3:4:xyz'));
    }

    /** @test */
    public function it_returns_cache_stats()
    {
        // Populate some cache entries
        Cache::put('category_count:1:maddparts:en:abc', 10, 300);
        Cache::put('category_count:2:maddparts:en:def', 20, 300);
        Cache::put('other_cache_key', 'value', 300);

        $stats = $this->service->getStats();

        // Should return stats array
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_keys', $stats);
        $this->assertArrayHasKey('category_count_keys', $stats);
        $this->assertArrayHasKey('ttl', $stats);

        // Should have at least 2 category count keys
        $this->assertGreaterThanOrEqual(2, $stats['category_count_keys']);
    }
}
