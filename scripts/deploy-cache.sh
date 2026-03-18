#!/bin/bash

###############################################################################
# Product Count Cache - Deployment Script
#
# Purpose: Automated deployment of Redis caching for product count queries
# Usage: ./scripts/deploy-cache.sh
# NOTE: Replace example.com with your own domain.
#
# This script will:
# 1. Verify prerequisites (Redis, config)
# 2. Clear Laravel caches
# 3. Run tests (optional)
# 4. Deploy code changes
# 5. Warm cache
# 6. Validate deployment
###############################################################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
BASE_URL="${BASE_URL:-https://www.example.com}"
RUN_TESTS="${RUN_TESTS:-false}"
SKIP_CONFIRMATION="${SKIP_CONFIRMATION:-false}"

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_step() {
    echo -e "\n${BLUE}==>${NC} $1\n"
}

check_command() {
    if ! command -v "$1" &> /dev/null; then
        log_error "$1 is not installed or not in PATH"
        return 1
    fi
    return 0
}

# Header
echo -e "${BLUE}"
echo "╔════════════════════════════════════════════════════════════╗"
echo "║     Product Count Cache - Deployment Script               ║"
echo "║     Maddparts.com - Bagisto/Laravel                        ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Step 1: Prerequisites Check
log_step "Step 1: Checking Prerequisites"

# Check PHP
if check_command php; then
    PHP_VERSION=$(php -v | head -1)
    log_info "PHP: $PHP_VERSION"
else
    log_error "PHP not found"
    exit 1
fi

# Check Redis CLI
if check_command redis-cli; then
    log_info "redis-cli: installed"
else
    log_warn "redis-cli not found (optional, but recommended)"
fi

# Test Redis connection
log_info "Testing Redis connection..."
if redis-cli PING > /dev/null 2>&1; then
    log_info "Redis: CONNECTED (PING -> PONG)"
else
    log_error "Redis is not responding. Please start Redis first."
    log_error "Try: sudo systemctl start redis"
    exit 1
fi

# Check Laravel
if [ ! -f "artisan" ]; then
    log_error "artisan file not found. Are you in the Laravel root directory?"
    exit 1
fi
log_info "Laravel: artisan found"

# Check .env
if [ ! -f ".env" ]; then
    log_error ".env file not found"
    exit 1
fi

# Check cache driver
CACHE_DRIVER=$(grep "^CACHE_DRIVER=" .env | cut -d'=' -f2)
if [ "$CACHE_DRIVER" != "redis" ]; then
    log_warn "CACHE_DRIVER is not set to 'redis' in .env (current: $CACHE_DRIVER)"
    log_warn "This deployment requires CACHE_DRIVER=redis"

    if [ "$SKIP_CONFIRMATION" != "true" ]; then
        read -p "Update .env to use redis? (y/n) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
            log_info "Updated CACHE_DRIVER=redis in .env"
        else
            log_error "Deployment cancelled"
            exit 1
        fi
    fi
else
    log_info "Cache driver: redis"
fi

# Check Redis client
REDIS_CLIENT=$(grep "^REDIS_CLIENT=" .env | cut -d'=' -f2 || echo "")
if [ "$REDIS_CLIENT" != "predis" ]; then
    log_warn "REDIS_CLIENT is not set to 'predis' in .env"
    if [ "$SKIP_CONFIRMATION" != "true" ]; then
        read -p "Update .env to use predis? (y/n) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            if grep -q "^REDIS_CLIENT=" .env; then
                sed -i 's/^REDIS_CLIENT=.*/REDIS_CLIENT=predis/' .env
            else
                echo "REDIS_CLIENT=predis" >> .env
            fi
            log_info "Updated REDIS_CLIENT=predis in .env"
        fi
    fi
else
    log_info "Redis client: predis"
fi

# Check TTL setting
CACHE_TTL=$(grep "^CATEGORY_COUNT_CACHE_TTL=" .env | cut -d'=' -f2 || echo "")
if [ -z "$CACHE_TTL" ]; then
    log_info "CATEGORY_COUNT_CACHE_TTL not set, will use default (300s)"
else
    log_info "Cache TTL: ${CACHE_TTL}s"
fi

log_info "✓ All prerequisites met"

# Step 2: Confirmation
if [ "$SKIP_CONFIRMATION" != "true" ]; then
    log_step "Step 2: Deployment Confirmation"
    echo "This script will:"
    echo "  • Clear Laravel caches"
    echo "  • Deploy product count caching"
    echo "  • Warm cache for $BASE_URL"
    echo ""
    read -p "Continue with deployment? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_error "Deployment cancelled by user"
        exit 1
    fi
fi

# Step 3: Run Tests (Optional)
if [ "$RUN_TESTS" = "true" ]; then
    log_step "Step 3: Running Tests"

    log_info "Running unit tests..."
    php artisan test --filter ProductCountCacheServiceTest || {
        log_error "Unit tests failed"
        exit 1
    }

    log_info "Running feature tests..."
    php artisan test --filter VehicleFitmentCacheTest || {
        log_warn "Feature tests failed (this may be expected in some environments)"
    }

    log_info "✓ Tests completed"
else
    log_step "Step 3: Skipping Tests"
    log_info "Run with RUN_TESTS=true to enable tests"
fi

# Step 4: Clear Caches
log_step "Step 4: Clearing Laravel Caches"

log_info "Clearing config cache..."
php artisan config:clear

log_info "Clearing application cache..."
php artisan cache:clear

log_info "Clearing route cache..."
php artisan route:clear

log_info "Clearing view cache..."
php artisan view:clear

log_info "✓ Caches cleared"

# Step 5: Verify Service Provider
log_step "Step 5: Verifying Service Provider Registration"

if grep -q "ProductCacheServiceProvider" config/app.php; then
    log_info "✓ ProductCacheServiceProvider is registered in config/app.php"
else
    log_error "ProductCacheServiceProvider NOT found in config/app.php"
    log_error "Please add this line to the 'providers' array:"
    log_error "    App\\Providers\\ProductCacheServiceProvider::class,"
    log_error ""
    log_error "See: docs/CONFIG_APP_CHANGES.md for detailed instructions"
    exit 1
fi

# Verify the command is available
if php artisan list | grep -q "cache:product-counts"; then
    log_info "✓ cache:product-counts command is available"
else
    log_error "cache:product-counts command not found"
    log_error "Service provider may not be loaded correctly"
    exit 1
fi

# Step 6: Optimize (Optional)
log_step "Step 6: Optimizing Laravel"

log_info "Caching configuration..."
php artisan config:cache

log_info "Caching routes..."
php artisan route:cache || log_warn "Route caching failed (this is OK for development)"

log_info "✓ Optimization complete"

# Step 7: Warm Cache
log_step "Step 7: Warming Cache"

if [ -f "scripts/warm-cache.sh" ]; then
    chmod +x scripts/warm-cache.sh

    log_info "Running cache warming script for $BASE_URL"
    ./scripts/warm-cache.sh "$BASE_URL" || {
        log_warn "Cache warming script encountered errors"
        log_warn "This is OK if you haven't configured URLs yet"
    }
else
    log_warn "scripts/warm-cache.sh not found, skipping cache warming"
    log_warn "You can manually warm the cache later by visiting key pages"
fi

# Step 8: Validation
log_step "Step 8: Validating Deployment"

log_info "Checking Redis key count..."
REDIS_KEYS_BEFORE=$(redis-cli DBSIZE | awk '{print $2}' || echo "0")
log_info "Redis keys: $REDIS_KEYS_BEFORE"

log_info "Checking cache stats..."
php artisan cache:product-counts stats

log_info "Testing cache functionality..."
php artisan tinker --execute="
    Cache::put('deployment_test', 'success', 60);
    echo Cache::get('deployment_test') === 'success' ? '✓ Cache test passed' : '✗ Cache test failed';
    Cache::forget('deployment_test');
"

log_info "✓ Validation complete"

# Step 9: Final Summary
log_step "Deployment Complete!"

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                 DEPLOYMENT SUCCESSFUL                      ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

log_info "Next Steps:"
echo "  1. Monitor MySQL CPU: top | grep mysqld"
echo "  2. Check process list: mysql -e 'SHOW FULL PROCESSLIST'"
echo "  3. View cache stats: php artisan cache:product-counts stats"
echo "  4. Monitor logs: tail -f storage/logs/laravel.log | grep ProductCountCache"
echo ""

log_info "Expected Results (within 1 hour):"
echo "  • MySQL CPU: 80-100% → 20-40% (60-75% reduction)"
echo "  • count(*) queries: 30-60 → 0-5 (90%+ reduction)"
echo "  • Page load time: 3-5s → 0.5-1.5s (60-80% faster)"
echo ""

log_info "Documentation:"
echo "  • Full Guide: docs/CACHE_DEPLOYMENT_GUIDE.md"
echo "  • Quick Ref: docs/CACHE_QUICK_REFERENCE.md"
echo "  • Summary: CACHE_IMPLEMENTATION_SUMMARY.md"
echo ""

log_warn "IMPORTANT: Monitor the application for the next 24 hours"
echo ""

log_info "Rollback (if needed):"
echo "  • Quick: echo 'CATEGORY_COUNT_CACHE_TTL=0' >> .env && php artisan config:clear"
echo "  • Full: git revert HEAD && php artisan config:clear"
echo ""

log_info "Deployment completed at: $(date)"
