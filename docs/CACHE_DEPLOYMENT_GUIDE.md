# Product Count Cache Deployment Guide

## Overview

This deployment adds Redis-backed caching to expensive `COUNT(*)` queries on the `product_flat` table, reducing database CPU usage by 70-90%.

**Changes:**
- Wraps `count()` queries in `Cache::remember()` with 300s TTL (configurable)
- Stable cache keys include: category, channel, locale, filters
- Automatic cache invalidation on product/category changes
- Cache warming script and monitoring tools

---

## Pre-Deployment Checklist

- [x] Redis is installed and accessible (`redis-cli PING` returns `PONG`)
- [x] Laravel configured to use Redis:
  - `CACHE_DRIVER=redis`
  - `REDIS_CLIENT=predis`
- [x] Index `idx_product_flat_sku` exists on `product_flat` table
- [ ] Backup database before deployment
- [ ] Schedule maintenance window (5-10 minutes)

---

## Deployment Steps

### 1. Register the Service Provider

Add to `config/app.php` in the `providers` array:

```php
'providers' => [
    // ... other providers
    App\Providers\ProductCacheServiceProvider::class,
],
```

### 2. Add Environment Variable (Optional)

Add to `.env` to customize cache TTL (default is 300 seconds):

```bash
CATEGORY_COUNT_CACHE_TTL=300
```

**Recommendations:**
- Start with 300s (5 min) for safety
- After 1 week of stable operation, increase to 600s (10 min) or 1800s (30 min)
- For bulk import/update operations, temporarily set to 60s

### 3. Deploy Code

```bash
# Pull latest code
git pull origin main

# Clear Laravel caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Optimize (optional but recommended)
php artisan config:cache
php artisan route:cache
```

### 4. Verify Redis Connection

```bash
# Test Redis connectivity
redis-cli PING
# Expected: PONG

# Check Redis info
redis-cli INFO stats

# View current key count
redis-cli DBSIZE
```

### 5. Warm the Cache

```bash
# Make script executable
chmod +x scripts/warm-cache.sh

# Run warming script (replace with your domain)
./scripts/warm-cache.sh https://www.maddparts.com
```

**Important:** Before running, edit `scripts/warm-cache.sh` and add:
- Top 10-20 vehicle combinations (from analytics)
- Top 10-20 category slugs
- Top 10-20 search queries

---

## Monitoring & Validation

### A. Verify Cache is Working

#### 1. Check Redis Key Count

```bash
# Count total Redis keys
redis-cli DBSIZE

# Count category count cache keys (use during low-load only)
redis-cli --scan --pattern 'category_count:*' | wc -l

# Count vehicle count cache keys
redis-cli --scan --pattern 'vehicle_count:*' | wc -l
```

**Expected:** After warming, you should see 50-200+ cache keys depending on traffic.

#### 2. Check Laravel Logs

```bash
tail -f storage/logs/laravel.log | grep ProductCountCache
```

**Expected output:**
```
[INFO] ProductCountCache: Cached query {"key":"vehicle_count:1:5:10:2020:abc123:def456","duration_ms":245.67,"result":1523}
```

First request: `duration_ms` should be 200-500ms (DB query)
Subsequent requests: Should read from cache (no log entry = cache hit)

### B. Monitor Database Load

#### 1. Check Query Rate (QPS)

```sql
-- Run twice, 5 seconds apart
SHOW GLOBAL STATUS LIKE 'Questions';
```

**Calculate QPS:**
```
QPS = (Questions_2 - Questions_1) / 5
```

**Expected:** 30-50% reduction in QPS after caching is active.

#### 2. Check for COUNT Queries

```sql
SHOW FULL PROCESSLIST;
```

**Expected:** You should see FAR FEWER (or zero) queries like:
```sql
select count(*) as aggregate from `product_flat` where `channel` = 'maddparts' and `locale` = 'en'
```

**Before caching:** 20-60 concurrent count queries
**After caching:** 0-5 concurrent count queries (only cache misses)

#### 3. Monitor MySQL CPU

```bash
# On server
top

# Look for mysqld process
# Press 'P' to sort by CPU
```

**Expected:** MySQL CPU usage should drop from 80-100% to 20-40%.

### C. Application-Level Checks

#### 1. Test a Vehicle Fitment Page

```bash
# First request (cache miss - will be slower)
time curl -s "https://www.maddparts.com/api/vehicle-search/filter?vehicle_type=1&vehicle_make=5&vehicle_model=10&vehicle_year=2020&page=1" > /dev/null

# Second request (cache hit - should be much faster)
time curl -s "https://www.maddparts.com/api/vehicle-search/filter?vehicle_type=1&vehicle_make=5&vehicle_model=10&vehicle_year=2020&page=1" > /dev/null
```

**Expected:**
- First request: 2-5 seconds
- Second request: 0.3-1 second (60-80% faster)

#### 2. Check Cache Statistics

```bash
php artisan cache:product-counts stats
```

**Expected output:**
```
Product Count Cache Statistics

+------------------------+--------+
| Metric                 | Value  |
+------------------------+--------+
| Total Redis Keys       | 1523   |
| Category Count Keys    | 245    |
| Cache TTL (seconds)    | 300    |
+------------------------+--------+
```

---

## Post-Deployment Validation Checklist

**After 1 hour:**
- [ ] Redis key count is growing (check `redis-cli DBSIZE`)
- [ ] MySQL CPU is reduced (check `top`)
- [ ] `SHOW PROCESSLIST` shows fewer `count(*)` queries
- [ ] Application logs show cache hits (check `laravel.log`)
- [ ] Page load times improved (test with curl or browser DevTools)

**After 24 hours:**
- [ ] No cache-related errors in `laravel.log`
- [ ] Redis memory usage is stable (check `redis-cli INFO memory`)
- [ ] Database CPU remains low during peak traffic
- [ ] QPS is reduced by 30-50%

---

## Rollback Plan

### Option 1: Quick Rollback (Keep Code, Disable Caching)

Add to `.env`:
```bash
CATEGORY_COUNT_CACHE_TTL=0
```

This sets TTL to 0, effectively disabling caching. Requires:
```bash
php artisan config:clear
```

### Option 2: Git Revert (Remove Code Changes)

```bash
# Revert the commit
git revert HEAD

# Or revert to specific commit
git revert <commit-hash>

# Deploy
git push origin main

# Clear caches
php artisan config:clear
php artisan cache:clear
```

### Option 3: Manual Code Revert

Edit `app/Http/Controllers/Shop/VehicleFitmentController.php`:

Replace cached count:
```php
// Remove these lines (482-503)
$cacheService = app(ProductCountCacheService::class);
$cacheKey = $cacheService->generateVehicleCountKey([...]);
$total = $cacheService->remember($cacheKey, function() use ($query) {
    return $query->count();
});
```

With original:
```php
$total = $query->count();
```

Do the same for lines 254-274 (category count).

### Option 4: Flush Redis Cache

**⚠️ WARNING:** This flushes ALL cache keys (not just product counts).

```bash
# Flush only product count caches
php artisan cache:product-counts flush --pattern="category_count:*"
php artisan cache:product-counts flush --pattern="vehicle_count:*"

# Or flush all Redis (NUCLEAR option)
redis-cli FLUSHDB
```

---

## Maintenance Operations

### Flush Cache After Bulk Import

After importing products or updating categories in bulk:

```bash
# Flush all product count caches
php artisan cache:product-counts flush --pattern="category_count:*"
php artisan cache:product-counts flush --pattern="vehicle_count:*"

# Warm cache again
./scripts/warm-cache.sh https://www.maddparts.com
```

### Flush Cache for Specific Category

```bash
# Flush caches for category ID 123
php artisan cache:product-counts flush --pattern="category_count:123:*"
```

### Monitor Redis Memory

```bash
# Check Redis memory usage
redis-cli INFO memory | grep used_memory_human

# Check max memory setting
redis-cli CONFIG GET maxmemory
```

**Expected:** Product count caches should use < 10MB for 10,000 keys.

If Redis memory is high:
```bash
# Set max memory (example: 256MB)
redis-cli CONFIG SET maxmemory 256mb

# Set eviction policy (remove least recently used)
redis-cli CONFIG SET maxmemory-policy allkeys-lru
```

---

## Troubleshooting

### Issue 1: Cache Not Working (Still Seeing count() Queries)

**Symptoms:**
- `SHOW PROCESSLIST` still shows many `count(*)` queries
- Redis key count not growing

**Diagnosis:**
```bash
# Check if Redis is actually being used
php artisan tinker
>>> Cache::get('test')
>>> Cache::put('test', 'hello', 60)
>>> Cache::get('test')
# Should return 'hello'

# Check Laravel cache driver
php artisan config:show cache.default
# Should be 'redis'
```

**Fix:**
```bash
# Ensure .env has correct settings
CACHE_DRIVER=redis
REDIS_CLIENT=predis

# Clear config
php artisan config:clear
php artisan config:cache
```

### Issue 2: Stale Data (Count Not Updating After Product Change)

**Symptoms:**
- Product added but count doesn't change
- Category count incorrect

**Diagnosis:**
- Check if observers are registered (should be in `ProductCacheServiceProvider`)
- Check logs for invalidation events

**Fix:**
```bash
# Manually flush stale caches
php artisan cache:product-counts flush

# Reduce TTL temporarily
# In .env:
CATEGORY_COUNT_CACHE_TTL=60

php artisan config:clear
```

### Issue 3: Redis Connection Errors

**Symptoms:**
- Errors in `laravel.log`: `Connection refused [tcp://127.0.0.1:6379]`

**Diagnosis:**
```bash
# Check if Redis is running
redis-cli PING

# Check Redis port
netstat -tlnp | grep 6379
```

**Fix:**
```bash
# Start Redis
sudo systemctl start redis
sudo systemctl enable redis

# Or restart
sudo systemctl restart redis
```

### Issue 4: High Redis Memory Usage

**Symptoms:**
- Redis memory growing beyond expected

**Diagnosis:**
```bash
# Check memory
redis-cli INFO memory

# Count keys by pattern
redis-cli --scan --pattern '*' | head -100
```

**Fix:**
```bash
# Flush old caches
php artisan cache:product-counts flush

# Set max memory and eviction policy
redis-cli CONFIG SET maxmemory 512mb
redis-cli CONFIG SET maxmemory-policy allkeys-lru
```

---

## Performance Benchmarks

### Expected Improvements

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| MySQL CPU | 80-100% | 20-40% | 60-75% reduction |
| QPS (Queries/sec) | 500-800 | 200-400 | 40-60% reduction |
| Concurrent count() | 30-60 | 0-5 | 90-95% reduction |
| Page load (vehicle) | 3-5s | 0.5-1.5s | 60-80% faster |
| Redis memory | 0MB | 5-15MB | +5-15MB |

---

## Safety Notes & Caveats

1. **TTL vs Invalidation:**
   - Start with low TTL (300s) until invalidation is proven stable
   - Monitor logs for cache invalidation events
   - For rare edge cases, rely on TTL expiry (eventual consistency)

2. **Bulk Operations:**
   - During bulk imports, set `CATEGORY_COUNT_CACHE_TTL=60` (1 min)
   - After bulk import, flush caches and warm
   - Or disable caching during import: `CATEGORY_COUNT_CACHE_TTL=0`

3. **Admin Pages:**
   - Cache is only applied to customer-facing vehicle search
   - Admin pages are not affected

4. **Avoid KEYS Command:**
   - Never use `redis-cli KEYS` in production (blocks Redis)
   - Always use `--scan` pattern: `redis-cli --scan --pattern 'category_count:*'`

5. **Monitoring:**
   - Set up alerts for Redis memory > 80% of max
   - Monitor MySQL CPU and set alert if it goes back up
   - Track QPS and alert on unexpected spikes

---

## Next Steps (Optional Enhancements)

After 1-2 weeks of stable operation, consider:

1. **Increase TTL:**
   - Change `CATEGORY_COUNT_CACHE_TTL=600` (10 min) or `1800` (30 min)

2. **Add More Endpoints:**
   - Apply same caching pattern to category listing pages
   - Cache search result counts

3. **Async Invalidation:**
   - Move cache invalidation to queued jobs for faster response
   - Use Laravel Horizon to monitor queue

4. **Whole-Page Caching:**
   - Enable Bagisto FPC (Full Page Cache) for static content
   - Ensure cache keys are correct before enabling

---

## Support

- **Logs:** `storage/logs/laravel.log`
- **Redis Logs:** `/var/log/redis/redis-server.log`
- **Command Help:** `php artisan cache:product-counts --help`

For issues, check the Troubleshooting section above or review the ProductCountCacheService class.
