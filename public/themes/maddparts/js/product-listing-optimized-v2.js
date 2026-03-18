document.addEventListener('DOMContentLoaded', function () {
    const gridViewBtn = document.getElementById('gridViewBtn');
    const listViewBtn = document.getElementById('listViewBtn');
    const productsList = document.getElementById('productsList');
    const loadMoreBtn = document.getElementById('loadMoreBtn');

    let isLoading = false;
    let hasMorePages = true;

    const urlParams = new URLSearchParams(window.location.search);
    const currentPage = parseInt(urlParams.get('page')) || 1;

    const filterState = {
        brands: [],
        categories: [],
        minPrice: null,
        maxPrice: null,
        inStock: false,
        vehicleType: window.initialVehicleFilters?.vehicleType || null,
        vehicleMake: window.initialVehicleFilters?.vehicleMake || null,
        vehicleModel: window.initialVehicleFilters?.vehicleModel || null,
        vehicleYear: window.initialVehicleFilters?.vehicleYear || null,
        sort: urlParams.get('sort') || (window.location.pathname.includes('/kawasaki-products') ? 'newest' : 'name_az'),
        page: currentPage
    };

    // Store the latest filter data from server for accurate counts
    let latestFilterData = null;

    if (gridViewBtn && listViewBtn && productsList) {
        gridViewBtn.addEventListener('click', function () {
            setGridView();
        });

        listViewBtn.addEventListener('click', function () {
            setListView();
        });
    }

    function setGridView() {
        productsList.classList.remove('list-view');
        productsList.classList.add('grid-view');
        gridViewBtn.classList.add('active');
        listViewBtn.classList.remove('active');

        const productCards = productsList.querySelectorAll('.col-12.mb-3, .col-xl-4, .col-lg-6, .col-6');
        productCards.forEach(card => {
            card.className = 'col-xl-4 col-lg-6 col-md-6 col-6 mb-4';
        });
    }

    function setListView() {
        productsList.classList.remove('grid-view');
        productsList.classList.add('list-view');
        listViewBtn.classList.add('active');
        gridViewBtn.classList.remove('active');

        const productCards = productsList.querySelectorAll('.col-xl-4, .col-lg-6, .col-md-6, .col-6, .col-12');
        productCards.forEach(card => {
            card.className = 'col-12 mb-3';
        });
    }

    const currentRoute = window.location.pathname;
    const isSearchPage = currentRoute.includes('/search') && !currentRoute.includes('/search-by-vehicle');
    const isVehiclePage = currentRoute.includes('/search-by-vehicle');
    const isBrandPage = currentRoute.includes('/brands/');
    const isKawasakiPage = currentRoute.includes('/kawasaki-products');
    const isCategoryPage = !isSearchPage && !isVehiclePage && !isBrandPage && !isKawasakiPage;

    let updateTimeout = null;

    function getFilterUrl() {
        if (isSearchPage) return '/search/filter';
        if (isVehiclePage) return '/search-by-vehicle/filter';
        if (isKawasakiPage) return '/kawasaki-products/filter';
        if (isBrandPage) {
            const brandSlug = currentRoute.split('/brands/')[1].split('/')[0];
            return `/brands/${brandSlug}/filter`;
        }
        const slug = currentRoute.substring(1);
        return `/category/${slug}/filter`;
    }

    function showSkeletonLoading(append = false) {
        const skeleton = createSkeletonHTML();
        if (append) {
            const container = document.getElementById('productsList');
            container.insertAdjacentHTML('beforeend', skeleton);
        } else {
            const container = document.getElementById('productsList');
            container.innerHTML = skeleton;
        }
        disableFilters(true);
    }

    function createSkeletonHTML() {
        let html = '';
        for (let i = 0; i < 12; i++) {
            html += `
                <div class="col-xl-4 col-lg-6 col-md-6 col-6 mb-4 skeleton-card">
                    <div class="product-card h-100">
                        <div class="product-image-wrapper skeleton-shimmer">
                            <div class="skeleton-box" style="width:100%; height:200px;"></div>
                        </div>
                        <div class="product-info">
                            <div class="skeleton-box" style="width:80%; height:20px; margin-bottom:10px;"></div>
                            <div class="skeleton-box" style="width:40%; height:16px; margin-bottom:10px;"></div>
                            <div class="skeleton-box" style="width:50%; height:24px; margin-bottom:10px;"></div>
                            <div class="skeleton-box" style="width:100%; height:40px;"></div>
                        </div>
                    </div>
                </div>
            `;
        }
        return html;
    }

    function hideLoading() {
        const skeletons = document.querySelectorAll('.skeleton-card');
        skeletons.forEach(s => s.remove());
        disableFilters(false);
        isLoading = false;
    }

    function disableFilters(disabled) {
        const elements = [
            ...document.querySelectorAll('.brand-filter'),
            ...document.querySelectorAll('.category-filter'),
            document.getElementById('minPrice'),
            document.getElementById('maxPrice'),
            document.getElementById('priceRangeSlider'),
            document.getElementById('inStockOnly'),
            document.getElementById('sortBy'),
            document.getElementById('vehicleType'),
            document.getElementById('vehicleMake'),
            document.getElementById('vehicleModel'),
            document.getElementById('vehicleYear')
        ];

        elements.forEach(el => {
            if (el) {
                el.disabled = disabled;
                el.style.opacity = disabled ? '0.6' : '1';
            }
        });
    }

    function updateProducts(append = false) {
        if (isLoading) return;

        isLoading = true;
        clearTimeout(updateTimeout);

        updateTimeout = setTimeout(() => {
            showSkeletonLoading(append);

            const formData = new FormData();
            const urlParams = new URLSearchParams(window.location.search);
            const searchQuery = urlParams.get('q');

            if (searchQuery) formData.append('q', searchQuery);

            filterState.brands.forEach(brand => formData.append('brands[]', brand));
            filterState.categories.forEach(cat => formData.append('categories[]', cat));

            if (filterState.minPrice) formData.append('min_price', filterState.minPrice);
            if (filterState.maxPrice) formData.append('max_price', filterState.maxPrice);
            if (filterState.inStock) formData.append('in_stock', '1');
            if (filterState.sort) formData.append('sort', filterState.sort);
            if (filterState.page > 1) formData.append('page', filterState.page);

            if (filterState.vehicleType) formData.append('vehicle_type', filterState.vehicleType);
            if (filterState.vehicleMake) formData.append('vehicle_make', filterState.vehicleMake);
            if (filterState.vehicleModel) formData.append('vehicle_model', filterState.vehicleModel);
            if (filterState.vehicleYear) formData.append('vehicle_year', filterState.vehicleYear);

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            fetch(getFilterUrl(), {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(response => {
                    if (!response.ok) {
                        console.error('Filter request failed:', response.status, response.statusText);
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (data.message) {
                            updateProductsList(data.products, append, data.message);
                            if (isVehiclePage) {
                                hideOtherFilters();
                                updateVehicleHeader(null);
                            }
                            window.dispatchEvent(new CustomEvent('vehicleCleared'));
                        } else {
                            updateProductsList(data.products, append);
                            if (isVehiclePage) {
                                showOtherFilters();
                                if (data.vehicleInfo) {
                                    updateVehicleHeader(data.vehicleInfo);
                                }
                            }
                            window.dispatchEvent(new CustomEvent('productsUpdated', {
                                detail: { pagination: data.pagination }
                            }));
                        }
                        updateResultsCount(data.pagination, data.products.length);
                        hasMorePages = data.pagination.has_more_pages;

                        // Update pagination HTML
                        updatePagination(data.pagination);

                        // Load More button disabled - using infinite scroll instead
                        // Keep button hidden since infinite-scroll.js handles pagination
                        const loadMoreWrapper = document.getElementById('loadMoreWrapper');
                        if (loadMoreWrapper) {
                            loadMoreWrapper.style.display = 'none';
                        }

                        if (data.filterData) {
                            latestFilterData = data.filterData;
                            updateFilterOptions(data.filterData);
                        }

                        updateActiveFilters();
                        lazyLoadImages();
                    } else {
                        console.error('Filter response not successful:', data);
                    }
                })
                .catch(error => {
                    console.error('Filter error:', error);
                    alert('An error occurred while filtering products. Please check the console for details.');
                })
                .finally(() => hideLoading());
        }, 300);
    }

    function lazyLoadImages() {
        const images = document.querySelectorAll('img.lazy-image');
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    const src = img.getAttribute('data-src');
                    if (src) {
                        img.src = src;
                        img.classList.remove('lazy-image');
                        observer.unobserve(img);
                    }
                }
            });
        });

        images.forEach(img => imageObserver.observe(img));
    }

    function updateProductsList(products, append = false, message = null) {
        const container = document.getElementById('productsList');
        if (!container) return;

        if (products.length === 0 && !append) {
            const displayMessage = message || 'Try adjusting your filters or search terms.';
            const icon = message ? 'fa-car' : 'fa-search';
            const heading = message ? 'Select a Vehicle' : 'No Products Found';

            container.innerHTML = `
                <div class="col-12">
                    <div class="no-products-found text-center py-5">
                        <i class="fas ${icon} fa-4x text-muted mb-4"></i>
                        <h3 class="text-muted">${heading}</h3>
                        <p class="text-muted">${displayMessage}</p>
                    </div>
                </div>
            `;
            return;
        }

        const productsHTML = products.map(product => createProductCard(product)).join('');

        if (append) {
            container.insertAdjacentHTML('beforeend', productsHTML);
        } else {
            container.innerHTML = productsHTML;
        }
    }

    function createProductCard(product) {
        const stockBadge = product.stock_status === 'in_stock'
            ? '<span class="badge bg-success">In Stock</span>'
            : '<span class="badge bg-danger">Out of Stock</span>';

        const displayPrice = (product.price === null || product.price === undefined || product.price === '') ? 0 : parseFloat(product.price) || 0;

        const isConfigurable = product.sku && product.sku.includes('-PARENT');
        const priceText = isConfigurable ? `Starting from $${displayPrice.toFixed(2)}` : `$${displayPrice.toFixed(2)}`;

        const isListView = productsList.classList.contains('list-view');
        const columnClasses = isListView ? 'col-12 mb-3' : 'col-xl-4 col-lg-6 col-md-6 col-6 mb-4';

        return `
            <div class="${columnClasses}">
                <div class="product-card h-100">
                    <div class="product-image-wrapper position-relative">
                        <a href="/${product.url_key}" class="product-link">
                            <img data-src="${product.image_url}"
                                 alt="${product.name}"
                                 class="product-image lazy-image"
                                 loading="lazy"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 300 300'%3E%3Crect fill='%23f0f0f0' width='300' height='300'/%3E%3C/svg%3E">
                        </a>
                        <button type="button" class="wishlist-icon-btn" data-product-id="${product.id}" aria-label="Add to wishlist">
                            <i class="far fa-heart"></i>
                        </button>
                    </div>
                    <div class="product-info">
                        <h5 class="product-name">
                            <a href="/${product.url_key}" class="text-dark">
                                ${product.name.substring(0, 60)}${product.name.length > 60 ? '...' : ''}
                            </a>
                        </h5>
                        <p class="product-sku text-muted small mb-2">SKU: ${product.sku}</p>
                        <div class="product-price mb-3">
                            <span class="current-price text-dark font-weight-bold">${priceText}</span>
                        </div>
                        <a href="/${product.url_key}" class="btn btn-primary btn-block view-product-btn">
                            <i class="fa fa-eye mr-2"></i>
                            View Product
                        </a>
                    </div>
                </div>
            </div>
        `;
    }

    function updateResultsCount(pagination, actualProductCount = null) {
        const resultsCount = document.getElementById('resultsCount');
        if (!resultsCount) return;

        // If no products, show appropriate message
        if (pagination.total === 0) {
            resultsCount.innerHTML = 'No products found';
            return;
        }

        const start = (pagination.current_page - 1) * pagination.per_page + 1;
        // Use actual product count if provided, otherwise calculate based on per_page
        const itemsOnPage = actualProductCount !== null ? actualProductCount :
            Math.min(pagination.per_page, pagination.total - start + 1);
        const end = start + itemsOnPage - 1;

        resultsCount.innerHTML = `Showing <strong>${start}-${end}</strong> of <strong>${pagination.total}</strong> products`;
    }

    function updatePagination(pagination) {
        const paginationWrapper = document.getElementById('paginationWrapper');
        if (!paginationWrapper) return;

        // If only one page or no products, hide pagination
        if (pagination.last_page <= 1) {
            paginationWrapper.style.display = 'none';
            return;
        }

        // Show pagination wrapper
        paginationWrapper.style.display = 'block';

        const currentPage = pagination.current_page;
        const lastPage = pagination.last_page;

        // Build query parameters from current filter state
        const params = new URLSearchParams();
        filterState.brands.forEach(brand => params.append('brands[]', brand));
        filterState.categories.forEach(cat => params.append('categories[]', cat));
        if (filterState.minPrice) params.append('min_price', filterState.minPrice);
        if (filterState.maxPrice) params.append('max_price', filterState.maxPrice);
        if (filterState.inStock) params.append('in_stock', '1');
        if (filterState.sort) params.append('sort', filterState.sort);

        let paginationHTML = '<nav><ul class="pagination justify-content-center">';

        // Previous button
        if (currentPage > 1) {
            const prevParams = new URLSearchParams(params);
            prevParams.set('page', currentPage - 1);
            paginationHTML += `<li class="page-item"><a class="page-link" href="?${prevParams.toString()}" data-page="${currentPage - 1}">‹</a></li>`;
        } else {
            paginationHTML += '<li class="page-item disabled"><span class="page-link">‹</span></li>';
        }

        // Page numbers
        const maxVisible = 10;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(lastPage, startPage + maxVisible - 1);

        // Adjust start if we're near the end
        if (endPage - startPage < maxVisible - 1) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        // First page
        if (startPage > 1) {
            const firstParams = new URLSearchParams(params);
            firstParams.set('page', 1);
            paginationHTML += `<li class="page-item"><a class="page-link" href="?${firstParams.toString()}" data-page="1">1</a></li>`;
            if (startPage > 2) {
                paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }

        // Page numbers
        for (let i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                paginationHTML += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
            } else {
                const pageParams = new URLSearchParams(params);
                pageParams.set('page', i);
                paginationHTML += `<li class="page-item"><a class="page-link" href="?${pageParams.toString()}" data-page="${i}">${i}</a></li>`;
            }
        }

        // Last page
        if (endPage < lastPage) {
            if (endPage < lastPage - 1) {
                paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            const lastParams = new URLSearchParams(params);
            lastParams.set('page', lastPage);
            paginationHTML += `<li class="page-item"><a class="page-link" href="?${lastParams.toString()}" data-page="${lastPage}">${lastPage}</a></li>`;
        }

        // Next button
        if (currentPage < lastPage) {
            const nextParams = new URLSearchParams(params);
            nextParams.set('page', currentPage + 1);
            paginationHTML += `<li class="page-item"><a class="page-link" href="?${nextParams.toString()}" data-page="${currentPage + 1}">›</a></li>`;
        } else {
            paginationHTML += '<li class="page-item disabled"><span class="page-link">›</span></li>';
        }

        paginationHTML += '</ul></nav>';
        paginationWrapper.innerHTML = paginationHTML;

        // Add click handlers to pagination links
        paginationWrapper.querySelectorAll('a.page-link').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const page = parseInt(this.getAttribute('data-page'));
                if (page && page !== currentPage) {
                    filterState.page = page;
                    updateProducts();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        });
    }

    function updateFilterOptions(filterData) {
        if (!filterData) return;

        // Update brand counts
        if (filterData.brands && filterData.brands.length > 0) {
            // Create a map of brand names to counts
            const brandCounts = {};
            filterData.brands.forEach(brand => {
                brandCounts[brand.brand] = brand.product_count;
            });

            // Update existing brand filter checkboxes
            document.querySelectorAll('.brand-filter').forEach(checkbox => {
                const brandName = checkbox.value;
                const label = checkbox.closest('.form-check').querySelector('.form-check-label');
                const badge = label?.querySelector('.badge');

                if (brandCounts[brandName] !== undefined) {
                    // Brand has products - update count
                    if (badge) {
                        badge.textContent = brandCounts[brandName];
                    }
                    // Show the checkbox
                    checkbox.closest('.brand-item').style.display = 'block';
                } else {
                    // Brand has no products - hide it
                    checkbox.closest('.brand-item').style.display = 'none';
                }
            });
        }

        // Update category counts
        if (filterData.categories && filterData.categories.length > 0) {
            // Create a map of category IDs to counts
            const categoryCounts = {};
            filterData.categories.forEach(cat => {
                categoryCounts[cat.id] = cat.product_count;
            });

            // Update existing category filter checkboxes
            document.querySelectorAll('.category-filter').forEach(checkbox => {
                const categoryId = checkbox.value;
                const label = checkbox.closest('.form-check').querySelector('.form-check-label');
                const badge = label?.querySelector('.badge');

                if (categoryCounts[categoryId] !== undefined) {
                    // Category has products - update count
                    if (badge) {
                        badge.textContent = categoryCounts[categoryId];
                    }
                    // Show the checkbox
                    checkbox.closest('.category-item').style.display = 'block';
                } else {
                    // Category has no products - hide it
                    checkbox.closest('.category-item').style.display = 'none';
                }
            });
        }

        // Update price range if provided
        if (filterData.price_range) {
            const minSlider = document.getElementById('minPriceSlider');
            const maxSlider = document.getElementById('maxPriceSlider');
            const minDisplay = document.getElementById('minPriceDisplay');
            const maxDisplay = document.getElementById('maxPriceDisplay');

            if (minSlider && maxSlider && filterData.price_range.min_price !== undefined && filterData.price_range.max_price !== undefined) {
                const newMin = Math.round(parseFloat(filterData.price_range.min_price));
                const newMax = Math.round(parseFloat(filterData.price_range.max_price));

                // Only update if not currently filtering by price
                if (!filterState.minPrice && !filterState.maxPrice) {
                    minSlider.min = newMin;
                    minSlider.max = newMax;
                    maxSlider.min = newMin;
                    maxSlider.max = newMax;
                    minSlider.value = newMin;
                    maxSlider.value = newMax;

                    minSlider.setAttribute('data-original-min', newMin);
                    minSlider.setAttribute('data-original-max', newMax);
                    maxSlider.setAttribute('data-original-min', newMin);
                    maxSlider.setAttribute('data-original-max', newMax);

                    if (minDisplay) minDisplay.textContent = '$' + newMin.toLocaleString();
                    if (maxDisplay) maxDisplay.textContent = '$' + newMax.toLocaleString();

                    updateSliderTrack();
                }
            }
        }
    }

    function updateActiveFilters() {
        const activeFiltersContainer = document.getElementById('activeFilterTags');
        const activeFiltersSection = document.getElementById('activeFilters');
        const filterCount = document.getElementById('filterCount');
        const clearAllBtn = document.getElementById('clearAllFilters');

        if (!activeFiltersContainer) return;

        let filterTags = [];
        let count = 0;

        // Helper function to get category name from latest filter data
        function getCategoryName(catId) {
            if (latestFilterData && latestFilterData.categories) {
                const category = latestFilterData.categories.find(c => c.id == catId);
                if (category) return category.name;
            }
            // Fallback to DOM lookup, but extract only the text without the count
            const label = document.querySelector(`#cat_${catId}`);
            if (label && label.nextElementSibling) {
                const labelText = label.nextElementSibling.querySelector('.filter-label-text');
                if (labelText) return labelText.textContent.trim();
            }
            return catId;
        }

        // Helper function to get brand/category count from latest filter data
        function getBrandCount(brandName) {
            if (latestFilterData && latestFilterData.brands) {
                const brand = latestFilterData.brands.find(b => b.brand === brandName);
                return brand ? brand.product_count : 0;
            }
            return null;
        }

        function getCategoryCount(catId) {
            if (latestFilterData && latestFilterData.categories) {
                const category = latestFilterData.categories.find(c => c.id == catId);
                return category ? category.product_count : 0;
            }
            return null;
        }

        filterState.brands.forEach(brand => {
            count++;
            filterTags.push(`<span class="filter-tag">Brand: ${brand} <i class="fa fa-times remove-tag" data-filter="brand" data-value="${brand}"></i></span>`);
        });

        filterState.categories.forEach(catId => {
            const catName = getCategoryName(catId);
            count++;
            filterTags.push(`<span class="filter-tag">Category: ${catName} <i class="fa fa-times remove-tag" data-filter="category" data-value="${catId}"></i></span>`);
        });

        if (filterState.minPrice || filterState.maxPrice) {
            count++;
            filterTags.push(`<span class="filter-tag">Price: $${filterState.minPrice || 0} - $${filterState.maxPrice || '∞'} <i class="fa fa-times remove-tag" data-filter="price"></i></span>`);
        }

        if (filterState.inStock) {
            count++;
            filterTags.push(`<span class="filter-tag">In Stock Only <i class="fa fa-times remove-tag" data-filter="stock"></i></span>`);
        }

        if (filterState.vehicleType && filterState.vehicleMake && filterState.vehicleModel && filterState.vehicleYear) {
            count++;
            filterTags.push(`<span class="filter-tag">Vehicle Filter Active <i class="fa fa-times remove-tag" data-filter="vehicle"></i></span>`);
        }

        if (count > 0) {
            activeFiltersSection.style.display = 'block';
            clearAllBtn.style.display = 'inline-block';
            filterCount.textContent = count;
            activeFiltersContainer.innerHTML = filterTags.join('');

            document.querySelectorAll('.remove-tag').forEach(tag => {
                tag.addEventListener('click', function () {
                    const filterType = this.getAttribute('data-filter');
                    const value = this.getAttribute('data-value');
                    removeFilter(filterType, value);
                });
            });
        } else {
            activeFiltersSection.style.display = 'none';
            clearAllBtn.style.display = 'none';
        }
    }

    function removeFilter(filterType, value) {
        switch (filterType) {
            case 'brand':
                filterState.brands = filterState.brands.filter(b => b !== value);
                document.querySelectorAll('.brand-filter').forEach(cb => {
                    if (cb.value === value) cb.checked = false;
                });
                break;
            case 'category':
                filterState.categories = filterState.categories.filter(c => c !== value);
                document.querySelectorAll('.category-filter').forEach(cb => {
                    if (cb.value === value) cb.checked = false;
                });
                break;
            case 'price':
                filterState.minPrice = null;
                filterState.maxPrice = null;
                // Reset sliders to original values
                const minSlider = document.getElementById('minPriceSlider');
                const maxSlider = document.getElementById('maxPriceSlider');
                const minDisplay = document.getElementById('minPriceDisplay');
                const maxDisplay = document.getElementById('maxPriceDisplay');

                if (minSlider && maxSlider) {
                    const originalMin = minSlider.getAttribute('data-original-min');
                    const originalMax = maxSlider.getAttribute('data-original-max');

                    minSlider.value = originalMin;
                    maxSlider.value = originalMax;

                    if (minDisplay) minDisplay.textContent = '$' + parseInt(originalMin).toLocaleString();
                    if (maxDisplay) maxDisplay.textContent = '$' + parseInt(originalMax).toLocaleString();

                    updateSliderTrack();
                }
                break;
            case 'stock':
                filterState.inStock = false;
                const stockCheckbox = document.getElementById('inStockOnly');
                if (stockCheckbox) stockCheckbox.checked = false;
                break;
            case 'vehicle':
                filterState.vehicleType = null;
                filterState.vehicleMake = null;
                filterState.vehicleModel = null;
                filterState.vehicleYear = null;
                break;
        }
        filterState.page = 1;
        updateProducts();
    }

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function () {
            if (!isLoading && hasMorePages) {
                filterState.page++;
                updateProducts(true);
            }
        });
    }

    const sortSelect = document.getElementById('sortBy');
    if (sortSelect) {
        // Set dropdown value on page load to match URL parameter
        if (filterState.sort) {
            sortSelect.value = filterState.sort;
        }

        sortSelect.addEventListener('change', function () {
            filterState.sort = this.value;
            filterState.page = 1;

            // Update URL with sort parameter
            const url = new URL(window.location);
            url.searchParams.set('sort', this.value);
            url.searchParams.delete('page');
            window.history.pushState({}, '', url);

            updateProducts();
        });
    }

    document.querySelectorAll('.brand-filter').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            if (this.checked) {
                filterState.brands.push(this.value);
            } else {
                filterState.brands = filterState.brands.filter(b => b !== this.value);
            }
            filterState.page = 1;
            updateProducts();
        });
    });

    document.querySelectorAll('.category-filter').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            if (this.checked) {
                filterState.categories.push(this.value);
            } else {
                filterState.categories = filterState.categories.filter(c => c !== this.value);
            }
            filterState.page = 1;
            updateProducts();
        });
    });

    const inStockCheckbox = document.getElementById('inStockOnly');
    if (inStockCheckbox) {
        inStockCheckbox.addEventListener('change', function () {
            filterState.inStock = this.checked;
            filterState.page = 1;
            updateProducts();
        });
    }

    const applyPriceBtn = document.getElementById('applyPriceFilter');
    if (applyPriceBtn) {
        applyPriceBtn.addEventListener('click', function () {
            const minSlider = document.getElementById('minPriceSlider');
            const maxSlider = document.getElementById('maxPriceSlider');

            if (minSlider && maxSlider) {
                filterState.minPrice = minSlider.value;
                filterState.maxPrice = maxSlider.value;
                filterState.page = 1;
                updateProducts();
            }
        });
    }

    const clearAllBtn = document.getElementById('clearAllFilters');
    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', function () {
            filterState.brands = [];
            filterState.categories = [];
            filterState.minPrice = null;
            filterState.maxPrice = null;
            filterState.inStock = false;
            filterState.vehicleType = null;
            filterState.vehicleMake = null;
            filterState.vehicleModel = null;
            filterState.vehicleYear = null;
            filterState.page = 1;

            document.querySelectorAll('.brand-filter, .category-filter').forEach(cb => cb.checked = false);
            const stockCheckbox = document.getElementById('inStockOnly');
            if (stockCheckbox) stockCheckbox.checked = false;

            // Reset price sliders to original values
            const minSlider = document.getElementById('minPriceSlider');
            const maxSlider = document.getElementById('maxPriceSlider');
            const minDisplay = document.getElementById('minPriceDisplay');
            const maxDisplay = document.getElementById('maxPriceDisplay');

            if (minSlider && maxSlider) {
                const originalMin = minSlider.getAttribute('data-original-min');
                const originalMax = maxSlider.getAttribute('data-original-max');

                minSlider.value = originalMin;
                maxSlider.value = originalMax;

                if (minDisplay) minDisplay.textContent = '$' + parseInt(originalMin).toLocaleString();
                if (maxDisplay) maxDisplay.textContent = '$' + parseInt(originalMax).toLocaleString();

                updateSliderTrack();
            }

            // Reset vehicle dropdowns
            const vehicleType = document.getElementById('vehicleType');
            const vehicleMake = document.getElementById('vehicleMake');
            const vehicleModel = document.getElementById('vehicleModel');
            const vehicleYear = document.getElementById('vehicleYear');
            const applyVehicleBtn = document.getElementById('applyVehicleFilter');
            const clearVehicleBtn = document.getElementById('clearVehicleFilter');

            if (vehicleType) vehicleType.value = '';
            if (vehicleMake) {
                vehicleMake.value = '';
                vehicleMake.disabled = true;
            }
            if (vehicleModel) {
                vehicleModel.value = '';
                vehicleModel.disabled = true;
            }
            if (vehicleYear) {
                vehicleYear.value = '';
                vehicleYear.disabled = true;
            }
            if (applyVehicleBtn) applyVehicleBtn.disabled = true;
            if (clearVehicleBtn) clearVehicleBtn.style.display = 'none';

            updateProducts();
        });
    }

    lazyLoadImages();

    const minPriceSlider = document.getElementById('minPriceSlider');
    const maxPriceSlider = document.getElementById('maxPriceSlider');
    const minPriceDisplay = document.getElementById('minPriceDisplay');
    const maxPriceDisplay = document.getElementById('maxPriceDisplay');
    const sliderTrackFill = document.getElementById('sliderTrackFill');

    function updateSliderTrack() {
        if (!minPriceSlider || !maxPriceSlider || !sliderTrackFill) return;

        const min = parseInt(minPriceSlider.min);
        const max = parseInt(minPriceSlider.max);
        const minVal = parseInt(minPriceSlider.value);
        const maxVal = parseInt(maxPriceSlider.value);

        const percentMin = ((minVal - min) / (max - min)) * 100;
        const percentMax = ((maxVal - min) / (max - min)) * 100;

        sliderTrackFill.style.left = percentMin + '%';
        sliderTrackFill.style.width = (percentMax - percentMin) + '%';
    }

    if (minPriceSlider && maxPriceSlider) {
        minPriceSlider.addEventListener('input', function () {
            const minVal = parseInt(this.value);
            const maxVal = parseInt(maxPriceSlider.value);

            // Prevent overlap - maintain at least $1 gap
            if (minVal >= maxVal) {
                this.value = maxVal - 1;
            }

            // Bring active slider to front
            this.style.zIndex = '5';
            maxPriceSlider.style.zIndex = '4';

            if (minPriceDisplay) {
                minPriceDisplay.textContent = '$' + parseInt(this.value).toLocaleString();
            }
            updateSliderTrack();
        });

        maxPriceSlider.addEventListener('input', function () {
            const minVal = parseInt(minPriceSlider.value);
            const maxVal = parseInt(this.value);

            // Prevent overlap - maintain at least $1 gap
            if (maxVal <= minVal) {
                this.value = minVal + 1;
            }

            // Bring active slider to front
            this.style.zIndex = '5';
            minPriceSlider.style.zIndex = '4';

            if (maxPriceDisplay) {
                maxPriceDisplay.textContent = '$' + parseInt(this.value).toLocaleString();
            }
            updateSliderTrack();
        });

        updateSliderTrack();
    }

    const brandSearch = document.getElementById('brandSearch');
    if (brandSearch) {
        brandSearch.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('.brand-item').forEach(item => {
                const label = item.querySelector('.filter-label-text').textContent.toLowerCase();
                item.style.display = label.includes(searchTerm) ? 'block' : 'none';
            });
        });
    }

    const categorySearch = document.getElementById('categorySearch');
    if (categorySearch) {
        categorySearch.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('.category-item').forEach(item => {
                const label = item.querySelector('.filter-label-text').textContent.toLowerCase();
                item.style.display = label.includes(searchTerm) ? 'block' : 'none';
            });
        });
    }

    const vehicleType = document.getElementById('vehicleType');
    const vehicleMake = document.getElementById('vehicleMake');
    const vehicleModel = document.getElementById('vehicleModel');
    const vehicleYear = document.getElementById('vehicleYear');
    const applyVehicleBtn = document.getElementById('applyVehicleFilter');
    const clearVehicleBtn = document.getElementById('clearVehicleFilter');

    if (vehicleType) {

        fetch('/api/vehicle-types')
            .then(res => res.json())
            .then(data => {

                vehicleType.innerHTML = '<option value="">Select Type</option>';
                data.forEach(type => {
                    const selected = filterState.vehicleType && filterState.vehicleType == type.id ? 'selected' : '';
                    vehicleType.innerHTML += `<option value="${type.id}" ${selected}>${type.description}</option>`;
                });

                if (filterState.vehicleType) {

                    loadInitialMakes();
                } else {
                    console.log('No initial vehicle type to load');
                }
            })
            .catch(err => console.error('Error loading vehicle types:', err));

        vehicleType.addEventListener('change', function () {
            const typeId = this.value;
            vehicleMake.disabled = !typeId;
            vehicleModel.disabled = true;
            vehicleYear.disabled = true;
            applyVehicleBtn.disabled = true;

            if (typeId) {
                fetch(`/api/makes?type_id=${typeId}`)
                    .then(res => res.json())
                    .then(data => {
                        vehicleMake.innerHTML = '<option value="">Select Make</option>';
                        data.forEach(make => {
                            vehicleMake.innerHTML += `<option value="${make.id}">${make.description}</option>`;
                        });
                    });
            }
        });
    }

    function loadInitialMakes() {

        if (!filterState.vehicleType || !vehicleMake) {

            return;
        }

        fetch(`/api/makes?type_id=${filterState.vehicleType}`)
            .then(res => res.json())
            .then(data => {

                vehicleMake.innerHTML = '<option value="">Select Make</option>';
                data.forEach(make => {
                    const selected = filterState.vehicleMake && filterState.vehicleMake == make.id ? 'selected' : '';
                    vehicleMake.innerHTML += `<option value="${make.id}" ${selected}>${make.description}</option>`;
                });
                vehicleMake.disabled = false;

                if (filterState.vehicleMake) {

                    loadInitialModels();
                }
            })
            .catch(err => console.error('Error loading makes:', err));
    }

    function loadInitialModels() {

        if (!filterState.vehicleType || !filterState.vehicleMake || !vehicleModel) {

            return;
        }

        fetch(`/api/models?type_id=${filterState.vehicleType}&make_id=${filterState.vehicleMake}`)
            .then(res => res.json())
            .then(data => {

                vehicleModel.innerHTML = '<option value="">Select Model</option>';
                data.forEach(model => {
                    const selected = filterState.vehicleModel && filterState.vehicleModel == model.id ? 'selected' : '';
                    vehicleModel.innerHTML += `<option value="${model.id}" ${selected}>${model.description}</option>`;
                });
                vehicleModel.disabled = false;

                if (filterState.vehicleModel) {

                    loadInitialYears();
                }
            })
            .catch(err => console.error('Error loading models:', err));
    }

    function loadInitialYears() {

        if (!filterState.vehicleType || !filterState.vehicleMake || !filterState.vehicleModel || !vehicleYear) {

            return;
        }

        fetch(`/api/years?type_id=${filterState.vehicleType}&make_id=${filterState.vehicleMake}&model_id=${filterState.vehicleModel}`)
            .then(res => res.json())
            .then(data => {

                vehicleYear.innerHTML = '<option value="">Select Year</option>';
                data.forEach(year => {
                    const selected = filterState.vehicleYear && filterState.vehicleYear == year.id ? 'selected' : '';
                    vehicleYear.innerHTML += `<option value="${year.id}" ${selected}>${year.description}</option>`;
                });
                vehicleYear.disabled = false;

                if (filterState.vehicleYear && clearVehicleBtn) {

                    clearVehicleBtn.style.display = 'block';
                    applyVehicleBtn.disabled = false;
                }
            })
            .catch(err => console.error('Error loading years:', err));
    }

    if (vehicleMake) {
        vehicleMake.addEventListener('change', function () {
            const makeId = this.value;
            const typeId = vehicleType.value;
            vehicleModel.disabled = !makeId;
            vehicleYear.disabled = true;
            applyVehicleBtn.disabled = true;

            if (makeId && typeId) {
                fetch(`/api/models?type_id=${typeId}&make_id=${makeId}`)
                    .then(res => res.json())
                    .then(data => {
                        vehicleModel.innerHTML = '<option value="">Select Model</option>';
                        data.forEach(model => {
                            vehicleModel.innerHTML += `<option value="${model.id}">${model.description}</option>`;
                        });
                    });
            }
        });
    }

    if (vehicleModel) {
        vehicleModel.addEventListener('change', function () {
            const modelId = this.value;
            const makeId = vehicleMake.value;
            const typeId = vehicleType.value;
            vehicleYear.disabled = !modelId;
            applyVehicleBtn.disabled = true;

            if (modelId && makeId && typeId) {
                fetch(`/api/years?type_id=${typeId}&make_id=${makeId}&model_id=${modelId}`)
                    .then(res => res.json())
                    .then(data => {
                        vehicleYear.innerHTML = '<option value="">Select Year</option>';
                        data.forEach(year => {
                            vehicleYear.innerHTML += `<option value="${year.id}">${year.description}</option>`;
                        });
                        vehicleYear.disabled = false;
                    });
            }
        });
    }

    if (vehicleYear) {
        vehicleYear.addEventListener('change', function () {
            applyVehicleBtn.disabled = !this.value;
        });
    }

    if (applyVehicleBtn) {
        applyVehicleBtn.addEventListener('click', function () {
            filterState.vehicleType = vehicleType.value;
            filterState.vehicleMake = vehicleMake.value;
            filterState.vehicleModel = vehicleModel.value;
            filterState.vehicleYear = vehicleYear.value;
            filterState.page = 1;

            if (clearVehicleBtn) {
                clearVehicleBtn.style.display = 'block';
            }

            updateProducts();
        });
    }

    if (clearVehicleBtn) {
        clearVehicleBtn.addEventListener('click', function () {
            vehicleType.value = '';
            vehicleMake.value = '';
            vehicleModel.value = '';
            vehicleYear.value = '';
            vehicleMake.disabled = true;
            vehicleModel.disabled = true;
            vehicleYear.disabled = true;
            applyVehicleBtn.disabled = true;
            this.style.display = 'none';

            filterState.vehicleType = null;
            filterState.vehicleMake = null;
            filterState.vehicleModel = null;
            filterState.vehicleYear = null;
            filterState.page = 1;

            updateProducts();
        });
    }

    function hideOtherFilters() {
        const filterIds = ['categoryFilter', 'brandFilter', 'priceFilter', 'stockFilter'];

        filterIds.forEach(id => {
            const filterDiv = document.getElementById(id);
            if (filterDiv) {
                const filterSection = filterDiv.closest('.filter-section');
                if (filterSection) {
                    filterSection.style.display = 'none';
                }
            }
        });
    }

    function showOtherFilters() {
        const filterIds = ['categoryFilter', 'brandFilter', 'priceFilter', 'stockFilter'];

        filterIds.forEach(id => {
            const filterDiv = document.getElementById(id);
            if (filterDiv) {
                const filterSection = filterDiv.closest('.filter-section');
                if (filterSection) {
                    filterSection.style.display = 'block';
                }
            }
        });
    }

    function updateVehicleHeader(vehicleInfo) {
        const vehicleTitle = document.getElementById('vehicleHeaderTitle');
        const vehicleType = document.getElementById('vehicleHeaderType');
        const breadcrumbVehicle = document.getElementById('breadcrumbVehicle');

        if (!vehicleTitle || !vehicleType || !breadcrumbVehicle) return;

        if (vehicleInfo) {
            const fullTitle = `${vehicleInfo.year} ${vehicleInfo.make} ${vehicleInfo.model}`;
            vehicleTitle.textContent = fullTitle;
            vehicleType.innerHTML = `<i class="fa fa-tag me-1"></i>${vehicleInfo.type}`;
            breadcrumbVehicle.textContent = fullTitle;
            document.title = `Products for ${fullTitle}`;
        } else {
            vehicleTitle.textContent = 'Select a Vehicle';
            vehicleType.innerHTML = '<i class="fa fa-tag me-1"></i>No vehicle selected';
            breadcrumbVehicle.textContent = 'No vehicle selected';
            document.title = 'Search by Vehicle';
        }
    }
});
