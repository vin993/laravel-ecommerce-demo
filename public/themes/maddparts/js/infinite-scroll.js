// Infinite Scroll - Handles JSON response from AJAX with filter support
(function() {
    let loading = false;
    let currentPage = parseInt(new URLSearchParams(window.location.search).get('page')) || 1;
    let hasMorePages = true;

    // Collect active filter values
    function getActiveFilters() {
        const filters = {
            brands: [],
            categories: [],
            minPrice: null,
            maxPrice: null,
            inStock: false,
            vehicleType: null,
            vehicleMake: null,
            vehicleModel: null,
            vehicleYear: null,
            sort: null
        };

        // Get checked brand filters
        document.querySelectorAll('.brand-filter:checked').forEach(cb => {
            filters.brands.push(cb.value);
        });

        // Get checked category filters
        document.querySelectorAll('.category-filter:checked').forEach(cb => {
            filters.categories.push(cb.value);
        });

        // Get price range
        const minSlider = document.getElementById('minPriceSlider');
        const maxSlider = document.getElementById('maxPriceSlider');
        if (minSlider && maxSlider) {
            const originalMin = minSlider.getAttribute('data-original-min');
            const originalMax = maxSlider.getAttribute('data-original-max');

            // Only include if changed from original
            if (minSlider.value != originalMin) {
                filters.minPrice = minSlider.value;
            }
            if (maxSlider.value != originalMax) {
                filters.maxPrice = maxSlider.value;
            }
        }

        // Get stock filter
        const stockCheckbox = document.getElementById('inStockOnly');
        if (stockCheckbox?.checked) {
            filters.inStock = true;
        }

        // Get vehicle filters
        const vehicleType = document.getElementById('vehicleType');
        const vehicleMake = document.getElementById('vehicleMake');
        const vehicleModel = document.getElementById('vehicleModel');
        const vehicleYear = document.getElementById('vehicleYear');

        if (vehicleType?.value) filters.vehicleType = vehicleType.value;
        if (vehicleMake?.value) filters.vehicleMake = vehicleMake.value;
        if (vehicleModel?.value) filters.vehicleModel = vehicleModel.value;
        if (vehicleYear?.value) filters.vehicleYear = vehicleYear.value;

        // Get sort option
        const sortSelect = document.getElementById('sortBy');
        if (sortSelect?.value) {
            filters.sort = sortSelect.value;
        }

        return filters;
    }

    // Get the filter URL endpoint
    function getFilterUrl() {
        const currentRoute = window.location.pathname;
        const isSearchPage = currentRoute.includes('/search') && !currentRoute.includes('/search-by-vehicle');
        const isVehiclePage = currentRoute.includes('/search-by-vehicle');
        const isBrandPage = currentRoute.includes('/brands/');

        if (isSearchPage) return '/search/filter';
        if (isVehiclePage) return '/search-by-vehicle/filter';
        if (isBrandPage) {
            const brandSlug = currentRoute.split('/brands/')[1].split('/')[0];
            return `/brands/${brandSlug}/filter`;
        }

        const slug = currentRoute.substring(1);
        return `/category/${slug}/filter`;
    }

    function loadMoreProducts() {
        if (loading || !hasMorePages) {
            return;
        }

        loading = true;
        currentPage++;
        showLoadingIndicator();

        const filters = getActiveFilters();
        const formData = new FormData();

        // Add search query and category if present
        const urlParams = new URLSearchParams(window.location.search);
        const searchQuery = urlParams.get('q');
        const categoryParam = urlParams.get('category');
        if (searchQuery) formData.append('q', searchQuery);
        if (categoryParam) formData.append('category', categoryParam);

        // Add all filter parameters
        filters.brands.forEach(brand => formData.append('brands[]', brand));
        filters.categories.forEach(cat => formData.append('categories[]', cat));

        if (filters.minPrice) formData.append('min_price', filters.minPrice);
        if (filters.maxPrice) formData.append('max_price', filters.maxPrice);
        if (filters.inStock) formData.append('in_stock', '1');
        if (filters.sort) formData.append('sort', filters.sort);

        if (filters.vehicleType) formData.append('vehicle_type', filters.vehicleType);
        if (filters.vehicleMake) formData.append('vehicle_make', filters.vehicleMake);
        if (filters.vehicleModel) formData.append('vehicle_model', filters.vehicleModel);
        if (filters.vehicleYear) formData.append('vehicle_year', filters.vehicleYear);

        // Add page number
        formData.append('page', currentPage);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const filterUrl = getFilterUrl();

        fetch(filterUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.products && data.products.length > 0) {
                appendProducts(data.products);
                hasMorePages = data.pagination.has_more_pages;

                if (!hasMorePages) {
                    showEndMessage();
                }
            } else {
                hasMorePages = false;
                showEndMessage();
            }
        })
        .catch(error => {
            console.error('Error loading products:', error);
            hasMorePages = false;
            currentPage--;
        })
        .finally(() => {
            hideLoadingIndicator();
            loading = false;
        });
    }
    
    function appendProducts(products) {
        const productsList = document.getElementById('productsList');
        if (!productsList) return;

        const isListView = productsList.classList.contains('list-view');
        const columnClasses = isListView ? 'col-12 mb-3' : 'col-xl-4 col-lg-6 col-md-6 col-6 mb-4';

        products.forEach(product => {
            const col = document.createElement('div');
            col.className = columnClasses;
            col.innerHTML = createProductCard(product);
            productsList.appendChild(col);
        });

        initLazyLoading();
    }
    
    function createProductCard(product) {
        const hasRealImage = product.image_url && !product.image_url.includes('logo.png');
        const imgUrl = hasRealImage ? product.image_url : '/themes/maddparts/images/logo.png';
        const imgClass = hasRealImage ? 'product-image lazy-image' : 'product-image product-logo';
        const displayPrice = (product.price === null || product.price === undefined || product.price === '') ? 0 : parseFloat(product.price) || 0;
        const specialPrice = (product.special_price === null || product.special_price === undefined || product.special_price === '') ? 0 : parseFloat(product.special_price) || 0;
        const isConfigurable = product.sku && product.sku.indexOf('-PARENT') !== -1;
        const shortName = product.name.length > 60 ? product.name.substring(0, 60) + '...' : product.name;

        const imageHtml = hasRealImage ?
            '<img data-src="' + imgUrl + '" alt="' + product.name + '" class="' + imgClass + '" loading="lazy" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 300 300\'%3E%3Crect fill=\'%23f0f0f0\' width=\'300\' height=\'300\'/%3E%3C/svg%3E" onerror="this.src=\'/themes/maddparts/images/logo.png\'; this.className=\'product-image product-logo\';">' :
            '<img src="' + imgUrl + '" alt="' + product.name + '" class="' + imgClass + '" loading="lazy">';

        let priceHtml = '';
        if (displayPrice <= 0) {
            priceHtml = '<span class="current-price text-primary font-weight-bold">Call for Price</span>';
        } else if (specialPrice > 0 && specialPrice < displayPrice) {
            priceHtml = '<span class="current-price text-danger font-weight-bold">$' + specialPrice.toFixed(2) + '</span> <span class="original-price text-muted"><del>$' + displayPrice.toFixed(2) + '</del></span>';
        } else {
            if (isConfigurable) {
                priceHtml = '<span class="current-price text-dark font-weight-bold">Starting from $' + displayPrice.toFixed(2) + '</span>';
            } else {
                priceHtml = '<span class="current-price text-dark font-weight-bold">$' + displayPrice.toFixed(2) + '</span>';
            }
        }

        return '<div class="product-card h-100">' +
            '<div class="product-image-wrapper position-relative">' +
            '<a href="/' + product.url_key + '" class="product-link">' + imageHtml + '</a>' +
            '</div>' +
            '<div class="product-info">' +
            '<h5 class="product-name"><a href="/' + product.url_key + '" class="text-dark">' + shortName + '</a></h5>' +
            '<p class="product-sku text-muted small mb-2">SKU: ' + product.sku + '</p>' +
            '<div class="product-price mb-3">' + priceHtml + '</div>' +
            '<a href="/' + product.url_key + '" class="btn btn-primary btn-block view-product-btn"><i class="fa fa-eye mr-2"></i>View Product</a>' +
            '</div></div>';
    }
    
    function initLazyLoading() {
        document.querySelectorAll('.lazy-image[data-src]').forEach(function(img) {
            img.src = img.dataset.src;
            img.classList.remove('lazy-image');
        });
    }
    
    function showLoadingIndicator() {
        if (document.getElementById('infinite-scroll-loader')) return;
        var loader = document.createElement('div');
        loader.id = 'infinite-scroll-loader';
        loader.className = 'text-center py-4';
        loader.innerHTML = '<div class="spinner-border text-primary"><span class="sr-only">Loading...</span></div><p class="mt-2 text-muted">Loading more products...</p>';
        document.getElementById('productsList').parentElement.appendChild(loader);
    }
    
    function hideLoadingIndicator() {
        var loader = document.getElementById('infinite-scroll-loader');
        if (loader) loader.remove();
    }
    
    function showEndMessage() {
        if (document.getElementById('infinite-scroll-end')) return;

        const productsList = document.getElementById('productsList');
        if (!productsList) return;

        const noProductsMessage = productsList.querySelector('.no-products-found');
        if (noProductsMessage) return;

        var msg = document.createElement('div');
        msg.id = 'infinite-scroll-end';
        msg.className = 'text-center py-4 text-muted';
        msg.innerHTML = '<div class="mb-2"><i class="fa fa-check-circle fa-2x text-success"></i></div><p class="mb-0">You have reached the end</p>';
        productsList.parentElement.appendChild(msg);
    }
    
    function checkScroll() {
        if (loading || !hasMorePages) return;

        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 800) {
            loadMoreProducts();
        }
    }
    
    // Reset page counter when filters change
    function resetPagination() {
        currentPage = 1;
        hasMorePages = false;

        // Remove end message if exists
        const endMsg = document.getElementById('infinite-scroll-end');
        if (endMsg) endMsg.remove();
        
        // Disable infinite scroll temporarily to let product-listing-enhanced handle it
        setTimeout(() => {
            hasMorePages = true;
        }, 1000);
    }

    // Listen for when product-listing-enhanced.js updates products
    window.addEventListener('productsUpdated', function(e) {
        if (e.detail && e.detail.pagination) {
            currentPage = e.detail.pagination.current_page;
            hasMorePages = e.detail.pagination.has_more_pages;

            // Remove end message when new filtered results loaded
            const endMsg = document.getElementById('infinite-scroll-end');
            if (endMsg) endMsg.remove();
        }
    });

    // Listen for when vehicle is cleared
    window.addEventListener('vehicleCleared', function() {
        hasMorePages = false;

        // Remove end message
        const endMsg = document.getElementById('infinite-scroll-end');
        if (endMsg) endMsg.remove();

        // Remove loading indicator
        hideLoadingIndicator();
    });

    document.addEventListener('DOMContentLoaded', function() {
        var pagination = document.querySelector('.pagination-wrapper');
        if (pagination) pagination.style.display = 'none';

        // Hide the Load More button wrapper since we're using infinite scroll
        const loadMoreWrapper = document.getElementById('loadMoreWrapper');
        if (loadMoreWrapper) {
            loadMoreWrapper.style.display = 'none';
        }

        initLazyLoading();

        var scrollTimeout;
        window.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(checkScroll, 100);
        });

        // Listen for filter changes to reset pagination
        document.querySelectorAll('.brand-filter, .category-filter').forEach(filter => {
            filter.addEventListener('change', resetPagination);
        });

        const stockCheckbox = document.getElementById('inStockOnly');
        if (stockCheckbox) {
            stockCheckbox.addEventListener('change', resetPagination);
        }

        const applyPriceBtn = document.getElementById('applyPriceFilter');
        if (applyPriceBtn) {
            applyPriceBtn.addEventListener('click', resetPagination);
        }

        const sortSelect = document.getElementById('sortBy');
        if (sortSelect) {
            sortSelect.addEventListener('change', resetPagination);
        }

        const applyVehicleBtn = document.getElementById('applyVehicleFilter');
        if (applyVehicleBtn) {
            applyVehicleBtn.addEventListener('click', resetPagination);
        }

        const clearAllBtn = document.getElementById('clearAllFilters');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', resetPagination);
        }

        setTimeout(checkScroll, 1000);
    });
})();
