#!/bin/bash

###############################################################################
# Product Count Cache Warming Script
#
# Purpose: Pre-populate Redis cache by curling top category/vehicle pages
# Usage: ./scripts/warm-cache.sh [base_url]
# Example: ./scripts/warm-cache.sh https://www.example.com
# NOTE: Replace example.com with your own domain.
#
# Run this after deploying cache changes or after bulk product updates
###############################################################################

set -e

BASE_URL="${1:-https://www.example.com}"
SLEEP_BETWEEN_REQUESTS=0.3  # seconds
MAX_PARALLEL=2              # concurrent requests

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if curl is available
if ! command -v curl &> /dev/null; then
    log_error "curl is not installed. Please install curl."
    exit 1
fi

# Function to warm a URL
warm_url() {
    local url="$1"
    local description="$2"

    log_info "Warming: $description"

    response=$(curl -s -o /dev/null -w "%{http_code}|%{time_total}" \
        -H "User-Agent: CacheWarmer/1.0" \
        -H "Accept: application/json" \
        "$url")

    http_code=$(echo "$response" | cut -d'|' -f1)
    time_total=$(echo "$response" | cut -d'|' -f2)

    if [ "$http_code" = "200" ]; then
        log_info "✓ $description - ${time_total}s"
    else
        log_warn "✗ $description - HTTP $http_code"
    fi

    sleep "$SLEEP_BETWEEN_REQUESTS"
}

# Start warming
log_info "Starting cache warming for $BASE_URL"
log_info "Sleep between requests: ${SLEEP_BETWEEN_REQUESTS}s"
echo ""

# 1. Warm home page
warm_url "$BASE_URL" "Home Page"

# 2. Warm top vehicle combinations
# Format: /vehicle-search?vehicle_type=X&vehicle_make=Y&vehicle_model=Z&vehicle_year=W
log_info "Warming top vehicle fitment pages..."

# Example vehicle combinations (replace with your top 10-20 combinations)
# Get these from your analytics or database query:
# SELECT vehicle_type_id, make_id, model_id, year_id, COUNT(*) as hits
# FROM page_views WHERE page LIKE '%vehicle-search%' GROUP BY ... ORDER BY hits DESC LIMIT 20

declare -a VEHICLES=(
    # "vehicle_type=1&vehicle_make=5&vehicle_model=10&vehicle_year=2020"
    # "vehicle_type=1&vehicle_make=5&vehicle_model=10&vehicle_year=2019"
    # Add your top vehicle combinations here
)

if [ ${#VEHICLES[@]} -eq 0 ]; then
    log_warn "No vehicle combinations defined. Add them to the VEHICLES array in this script."
else
    for vehicle_params in "${VEHICLES[@]}"; do
        warm_url "${BASE_URL}/api/vehicle-search/filter?${vehicle_params}&page=1" \
                 "Vehicle: $vehicle_params"
    done
fi

# 3. Warm top categories (if you have category pages)
log_info "Warming top category pages..."

declare -a CATEGORIES=(
    # "brake-pads"
    # "oil-filters"
    # "spark-plugs"
    # Add your top category slugs here
)

if [ ${#CATEGORIES[@]} -eq 0 ]; then
    log_warn "No categories defined. Add them to the CATEGORIES array in this script."
else
    for category in "${CATEGORIES[@]}"; do
        warm_url "${BASE_URL}/categories/${category}" "Category: $category"
    done
fi

# 4. Warm search pages (if applicable)
log_info "Warming search pages..."

declare -a SEARCH_QUERIES=(
    # "brake"
    # "filter"
    # "oil"
    # Add your top search queries here
)

if [ ${#SEARCH_QUERIES[@]} -eq 0 ]; then
    log_warn "No search queries defined. Add them to the SEARCH_QUERIES array in this script."
else
    for query in "${SEARCH_QUERIES[@]}"; do
        warm_url "${BASE_URL}/search?q=${query}" "Search: $query"
    done
fi

echo ""
log_info "Cache warming complete!"
log_info "Check Redis stats with: php artisan cache:product-counts stats"
log_info "Monitor DB load with: SHOW FULL PROCESSLIST;"
