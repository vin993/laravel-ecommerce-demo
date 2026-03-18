# Product Count Cache - Quick Reference Card

## 🚀 Deployment (5 Minutes)

```bash
# 1. Add service provider to config/app.php
#    Add: App\Providers\ProductCacheServiceProvider::class

# 2. Set TTL in .env (optional)
echo "CATEGORY_COUNT_CACHE_TTL=300" >> .env

# 3. Clear caches
php artisan config:clear && php artisan cache:clear

# 4. Warm cache
chmod +x scripts/warm-cache.sh
./scripts/warm-cache.sh https://www.maddparts.com
```

---

## 📊 Monitoring Commands

### Check if Caching is Working
```bash
# Before: Many count(*) queries
mysql -u root -p -e "SHOW FULL PROCESSLIST" | grep "count(*)"

# After: Few or zero count(*) queries (after cache warms)
```

### Redis Stats
```bash
# Check Redis is running
redis-cli PING

# Count cache keys
redis-cli DBSIZE

# View cache stats
php artisan cache:product-counts stats

# Monitor Redis memory
redis-cli INFO memory | grep used_memory_human
```

### Database Load
```bash
# MySQL CPU (should drop 60-80%)
top | grep mysqld

# Query rate (run twice, 5s apart)
mysql -e "SHOW GLOBAL STATUS LIKE 'Questions'"
# QPS = (Questions_2 - Questions_1) / 5
```

---

## 🧹 Maintenance

### Flush Caches
```bash
# Flush all product count caches
php artisan cache:product-counts flush --pattern="category_count:*"
php artisan cache:product-counts flush --pattern="vehicle_count:*"

# Flush specific category
php artisan cache:product-counts flush --pattern="category_count:123:*"

# Nuclear option (all Redis keys)
redis-cli FLUSHDB
```

### After Bulk Import
```bash
# 1. Temporarily lower TTL
echo "CATEGORY_COUNT_CACHE_TTL=60" >> .env
php artisan config:clear

# 2. Do bulk import
# ... your import process ...

# 3. Flush caches
php artisan cache:product-counts flush

# 4. Restore TTL
echo "CATEGORY_COUNT_CACHE_TTL=300" >> .env
php artisan config:clear

# 5. Warm cache
./scripts/warm-cache.sh https://www.maddparts.com
```

---

## 🔄 Rollback

### Quick Disable (Keep Code)
```bash
# Set TTL to 0 (disables caching)
echo "CATEGORY_COUNT_CACHE_TTL=0" >> .env
php artisan config:clear
```

### Git Revert
```bash
git revert HEAD
git push origin main
php artisan config:clear && php artisan cache:clear
```

---

## 🧪 Testing

### Manual Test
```bash
# First request (slow, cache miss)
time curl -s "https://www.maddparts.com/api/vehicle-search/filter?vehicle_type=1&vehicle_make=5&vehicle_model=10&vehicle_year=2020" > /dev/null

# Second request (fast, cache hit)
time curl -s "https://www.maddparts.com/api/vehicle-search/filter?vehicle_type=1&vehicle_make=5&vehicle_model=10&vehicle_year=2020" > /dev/null
```

### Run Unit Tests
```bash
php artisan test --filter ProductCountCacheServiceTest
php artisan test --filter VehicleFitmentCacheTest
php artisan test --filter CacheInvalidationTest
```

---

## 📈 Expected Results

| Metric | Before | After |
|--------|--------|-------|
| MySQL CPU | 80-100% | 20-40% |
| count(*) queries | 30-60 | 0-5 |
| Page load (vehicle) | 3-5s | 0.5-1.5s |
| QPS | 500-800 | 200-400 |

---

## 🚨 Troubleshooting

### Cache Not Working
```bash
# Check cache driver
php artisan tinker
>>> config('cache.default')
# Should be 'redis'

# Test Redis
php artisan tinker
>>> Cache::put('test', 'hello', 60)
>>> Cache::get('test')
# Should return 'hello'
```

### Stale Data
```bash
# Flush and warm
php artisan cache:product-counts flush
./scripts/warm-cache.sh https://www.maddparts.com

# Or reduce TTL temporarily
echo "CATEGORY_COUNT_CACHE_TTL=60" >> .env
php artisan config:clear
```

### Redis Down
```bash
# Check status
sudo systemctl status redis

# Start Redis
sudo systemctl start redis
sudo systemctl enable redis

# Test connection
redis-cli PING
```

---

## 📝 Key Files

| File | Purpose |
|------|---------|
| [app/Services/ProductCountCacheService.php](app/Services/ProductCountCacheService.php) | Cache service |
| [app/Http/Controllers/Shop/VehicleFitmentController.php](app/Http/Controllers/Shop/VehicleFitmentController.php) | Cached count queries (lines 482-503, 254-274) |
| [app/Observers/ProductCacheObserver.php](app/Observers/ProductCacheObserver.php) | Product invalidation |
| [app/Observers/CategoryCacheObserver.php](app/Observers/CategoryCacheObserver.php) | Category invalidation |
| [app/Providers/ProductCacheServiceProvider.php](app/Providers/ProductCacheServiceProvider.php) | Registers observers |
| [scripts/warm-cache.sh](scripts/warm-cache.sh) | Cache warming |

---

## 🔧 Cache Keys Format

```
# Vehicle count
vehicle_count:{type}:{make}:{model}:{year}:{productIdsHash}:{filtersHash}

# Category count
category_count:{categoryId}:{channel}:{locale}:{productIdsHash}

# Examples
vehicle_count:1:5:10:2020:abc123def:456789ghi
category_count:1217:maddparts:en:xyz789abc
```

---

## ⚙️ Configuration

### .env Variables
```bash
# Required
CACHE_DRIVER=redis
REDIS_CLIENT=predis

# Optional
CATEGORY_COUNT_CACHE_TTL=300  # Default: 300 seconds (5 min)
```

### Recommended TTL by Stage
- **Initial deployment:** 300s (5 min) - Safe, frequent updates
- **After 1 week stable:** 600s (10 min) - Balance speed/freshness
- **Mature/low churn:** 1800s (30 min) - Max performance
- **During bulk import:** 60s (1 min) - Quick invalidation

---

## 📞 Support

**Logs:**
- Laravel: `storage/logs/laravel.log`
- Redis: `/var/log/redis/redis-server.log`

**Search logs for:**
```bash
# Cache hits/misses
tail -f storage/logs/laravel.log | grep ProductCountCache

# Invalidation events
tail -f storage/logs/laravel.log | grep "CacheObserver"

# Errors
tail -f storage/logs/laravel.log | grep ERROR
```

**Helpful Commands:**
```bash
# View recent cache logs
tail -100 storage/logs/laravel.log | grep ProductCountCache

# Monitor Redis commands (live)
redis-cli MONITOR

# Check Redis slow queries
redis-cli SLOWLOG GET 10
```
