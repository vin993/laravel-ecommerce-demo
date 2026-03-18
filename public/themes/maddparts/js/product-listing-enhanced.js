document.addEventListener('DOMContentLoaded', function() {
    const gridViewBtn = document.getElementById('gridViewBtn');
    const listViewBtn = document.getElementById('listViewBtn');
    const productsList = document.getElementById('productsList');

    // Get current page from URL if exists
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
        sort: urlParams.get('sort') || 'name_az', // Read from URL or default to Name A-Z
        page: currentPage
    };

    if (gridViewBtn && listViewBtn && productsList) {
        const isMobile = window.innerWidth < 992;

        if (isMobile) {
            setListView();
        }

        gridViewBtn.addEventListener('click', function() {
            setGridView();
        });

        listViewBtn.addEventListener('click', function() {
            setListView();
        });
    }

    function setGridView() {
        productsList.classList.remove('list-view');
        productsList.classList.add('grid-view');
        gridViewBtn.classList.add('active');
        listViewBtn.classList.remove('active');

        const productCards = productsList.querySelectorAll('.col-12.mb-3, .col-xl-4, .col-lg-6');
        productCards.forEach(card => {
            card.className = 'col-xl-4 col-lg-6 col-md-6 col-12 mb-4';
        });
    }

    function setListView() {
        productsList.classList.remove('grid-view');
        productsList.classList.add('list-view');
        listViewBtn.classList.add('active');
        gridViewBtn.classList.remove('active');

        const productCards = productsList.querySelectorAll('.col-xl-4, .col-lg-6, .col-md-6, .col-12');
        productCards.forEach(card => {
            card.className = 'col-12 mb-3';
        });
    }

    const currentRoute = window.location.pathname;
    const isSearchPage = currentRoute.includes('/search');
    const isCategoryPage = !isSearchPage && !currentRoute.includes('/search-by-vehicle');
    const isVehiclePage = currentRoute.includes('/search-by-vehicle');

    let updateTimeout = null;

    function getFilterUrl() {
        if (isSearchPage) return '/search/filter';
        if (isVehiclePage) return '/search-by-vehicle/filter';
        const slug = currentRoute.substring(1);
        return `/category/${slug}/filter`;
    }

    function showLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.classList.add('show');
        }
        disableFilters(true);
    }

    function hideLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.classList.remove('show');
        }
        disableFilters(false);
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

    function updateProducts() {
        clearTimeout(updateTimeout);
        updateTimeout = setTimeout(() => {
            showLoading();

            const formData = new FormData();
            const urlParams = new URLSearchParams(window.location.search);
            const searchQuery = urlParams.get('q');
            const categoryParam = urlParams.get('category');

            if (searchQuery) formData.append('q', searchQuery);
            if (categoryParam) formData.append('category', categoryParam);

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
                    updateProductsList(data.products);
                    updateResultsCount(data.pagination);
                    updatePagination(data.pagination);

                    if (data.filterData) {
                        updateFilterOptions(data.filterData);
                    }

                    updateActiveFilters();

                    // Notify infinite scroll that products have been updated
                    window.dispatchEvent(new CustomEvent('productsUpdated', {
                        detail: { pagination: data.pagination }
                    }));
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

    function updateProductsList(products) {
        const container = document.getElementById('productsList');
        if (!container) return;

        if (products.length === 0) {
            container.innerHTML = `
                <div class="col-12">
                    <div class="no-products-found text-center py-5">
                        <i class="fas fa-search fa-4x text-muted mb-4"></i>
                        <h3 class="text-muted">No Products Found</h3>
                        <p class="text-muted">Try adjusting your filters or search terms.</p>
                    </div>
                </div>
            `;
            return;
        }

        container.innerHTML = products.map(product => createProductCard(product)).join('');
    }

    function createProductCard(product) {
        const imageUrl = product.product?.images?.[0]?.path
            ? `https://maddparts-images.s3.amazonaws.com/${product.product.images[0].path}`
            : '/themes/maddparts/images/logo.png';

        const logoClass = imageUrl.includes('logo.png') ? 'product-logo' : '';

        const price = parseFloat(product.price) || 0;
        const specialPrice = parseFloat(product.special_price) || 0;
        const productType = product.type || 'simple';

        let priceHtml = '';

        if (price <= 0) {
            priceHtml = '<span class="current-price text-primary fw-bold">Call for Price</span>';
        } else if (specialPrice > 0 && specialPrice < price) {
            priceHtml = `<span class="current-price text-danger fw-bold">$${specialPrice.toFixed(2)}</span>
               <span class="original-price text-muted"><del>$${price.toFixed(2)}</del></span>`;
        } else {
            if (productType === 'configurable') {
                priceHtml = `<span class="current-price text-dark fw-bold">Starting at $${price.toFixed(2)}</span>`;
            } else {
                priceHtml = `<span class="current-price text-dark fw-bold">$${price.toFixed(2)}</span>`;
            }
        }

        const reviews = product.product?.reviews || [];
        const avgRating = reviews.length > 0
            ? reviews.reduce((sum, r) => sum + parseFloat(r.rating), 0) / reviews.length
            : 0;
        const reviewCount = reviews.length;

        let ratingHtml = '';
        if (reviewCount > 0) {
            let starsHtml = '';
            for (let i = 1; i <= 5; i++) {
                if (i <= Math.floor(avgRating)) {
                    starsHtml += '<i class="fa fa-star text-warning"></i>';
                } else if (i - 0.5 <= avgRating) {
                    starsHtml += '<i class="fa fa-star-half-alt text-warning"></i>';
                } else {
                    starsHtml += '<i class="far fa-star text-warning"></i>';
                }
            }
            ratingHtml = `
                <div class="product-rating mb-2">
                    <div class="stars">
                        ${starsHtml}
                        <span class="rating-text text-muted small ms-1">(${reviewCount})</span>
                    </div>
                </div>
            `;
        }

        return `
            <div class="col-xl-4 col-lg-6 col-md-6 col-12 mb-4">
                <div class="product-card h-100">
                    <div class="product-image-wrapper position-relative">
                        <a href="/${product.url_key}" class="product-link">
                            <img src="${imageUrl}" alt="${product.name}"
                                 class="product-image ${logoClass}"
                                 onerror="this.src='/themes/maddparts/images/logo.png'; this.className='product-image product-logo';">
                        </a>
                    </div>
                    <div class="product-info p-3">
                        <h5 class="product-name">
                            <a href="/${product.url_key}" class="text-dark">
                                ${product.name.length > 60 ? product.name.substring(0, 60) + '...' : product.name}
                            </a>
                        </h5>
                        <p class="product-sku text-muted small mb-2">SKU: ${product.sku}</p>
                        ${ratingHtml}
                        <div class="product-price mb-3">${priceHtml}</div>
                        <a href="/${product.url_key}" class="btn btn-primary btn-block view-product-btn w-100">
                            <i class="fa fa-eye me-2"></i>
                            View Product
                        </a>
                    </div>
                </div>
            </div>
        `;
    }

    function updateResultsCount(pagination) {
        const resultsCount = document.getElementById('resultsCount');
        if (!resultsCount) return;

        const start = (pagination.current_page - 1) * pagination.per_page + 1;
        const end = Math.min(start + pagination.per_page - 1, pagination.total);

        resultsCount.innerHTML = `Showing <strong>${start}-${end}</strong> of <strong>${pagination.total}</strong> products`;
    }

    function updatePagination(pagination) {
        const wrapper = document.getElementById('paginationWrapper');
        if (!wrapper) return;

        if (pagination.last_page <= 1) {
            wrapper.style.display = 'none';
            return;
        }

        wrapper.style.display = 'block';

        let html = '<nav><ul class="pagination justify-content-center">';

        if (pagination.current_page > 1) {
            html += `<li class="page-item">
                <a class="page-link" href="#" data-page="${pagination.current_page - 1}">&laquo;</a>
            </li>`;
        }

        const maxVisible = 5;
        let startPage = Math.max(1, pagination.current_page - Math.floor(maxVisible / 2));
        let endPage = Math.min(pagination.last_page, startPage + maxVisible - 1);

        if (endPage - startPage < maxVisible - 1) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        if (startPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
            if (startPage > 2) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }

        for (let i = startPage; i <= endPage; i++) {
            html += `<li class="page-item ${i === pagination.current_page ? 'active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a>
            </li>`;
        }

        if (endPage < pagination.last_page) {
            if (endPage < pagination.last_page - 1) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${pagination.last_page}">${pagination.last_page}</a></li>`;
        }

        if (pagination.current_page < pagination.last_page) {
            html += `<li class="page-item">
                <a class="page-link" href="#" data-page="${pagination.current_page + 1}">&raquo;</a>
            </li>`;
        }

        html += '</ul></nav>';
        wrapper.innerHTML = html;

        wrapper.querySelectorAll('a.page-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = parseInt(this.getAttribute('data-page'));
                if (page) {
                    filterState.page = page;
                    updateProducts();
                    document.querySelector('.products-section').scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    }

    function updateFilterOptions(filterData) {
        if (filterData.brands !== undefined) updateBrandOptions(filterData.brands || []);
        if (filterData.categories !== undefined) updateCategoryOptions(filterData.categories || []);
        if (filterData.price_range) updatePriceRange(filterData.price_range);
    }

    function updateBrandOptions(brands) {
        const container = document.getElementById('brandOptions');
        if (!container) return;

        const existingBrands = new Set(brands.map(b => b.brand));

        filterState.brands.forEach(selectedBrand => {
            if (!existingBrands.has(selectedBrand)) {
                brands.push({ brand: selectedBrand, product_count: 0 });
            }
        });

        let html = '';
        brands.forEach((brand, index) => {
            const checked = filterState.brands.includes(brand.brand) ? 'checked' : '';
            html += `
                <div class="form-check brand-item filter-checkbox-item">
                    <input class="form-check-input brand-filter" type="checkbox" ${checked}
                           value="${brand.brand}" id="brand_${index}">
                    <label class="form-check-label" for="brand_${index}">
                        <span class="filter-label-text">${brand.brand}</span>
                        <span class="badge bg-light text-dark">${brand.product_count}</span>
                    </label>
                </div>
            `;
        });

        container.innerHTML = html;
        attachBrandListeners();
    }

    function updateCategoryOptions(categories) {
        const container = document.getElementById('categoryOptions');
        if (!container) return;

        const existingCategories = new Set(categories.map(c => c.id.toString()));

        filterState.categories.forEach(selectedCatId => {
            if (!existingCategories.has(selectedCatId)) {
                const existingLabel = document.querySelector(`label[for="cat_${selectedCatId}"] .filter-label-text`);
                const catName = existingLabel ? existingLabel.textContent : `Category ${selectedCatId}`;
                categories.push({ id: parseInt(selectedCatId), name: catName, product_count: 0 });
            }
        });

        let html = '';
        categories.forEach(category => {
            const checked = filterState.categories.includes(category.id.toString()) ? 'checked' : '';
            html += `
                <div class="form-check category-item filter-checkbox-item">
                    <input class="form-check-input category-filter" type="checkbox" ${checked}
                           value="${category.id}" id="cat_${category.id}">
                    <label class="form-check-label" for="cat_${category.id}">
                        <span class="filter-label-text">${category.name}</span>
                        ${category.product_count ? `<span class="badge bg-light text-dark">${category.product_count}</span>` : ''}
                    </label>
                </div>
            `;
        });

        container.innerHTML = html;
        attachCategoryListeners();
    }

    function updatePriceRange(priceRange) {
        const minSlider = document.getElementById('minPriceSlider');
        const maxSlider = document.getElementById('maxPriceSlider');
        const minDisplay = document.getElementById('minPriceDisplay');
        const maxDisplay = document.getElementById('maxPriceDisplay');

        if (!minSlider || !maxSlider) return;

        const min = Math.round(priceRange.min_price);
        const max = Math.round(priceRange.max_price);

        // Update slider attributes
        minSlider.setAttribute('min', min);
        minSlider.setAttribute('max', max);
        maxSlider.setAttribute('min', min);
        maxSlider.setAttribute('max', max);

        // Preserve user's selected values if they exist
        if (filterState.minPrice !== null) {
            minSlider.value = filterState.minPrice;
            if (minDisplay) minDisplay.textContent = '$' + formatNumber(filterState.minPrice);
        } else {
            minSlider.value = min;
            if (minDisplay) minDisplay.textContent = '$' + formatNumber(min);
        }

        if (filterState.maxPrice !== null) {
            maxSlider.value = filterState.maxPrice;
            if (maxDisplay) maxDisplay.textContent = '$' + formatNumber(filterState.maxPrice);
        } else {
            maxSlider.value = max;
            if (maxDisplay) maxDisplay.textContent = '$' + formatNumber(max);
        }

        // Update track fill
        updateSliderTrack();
    }

    function updateActiveFilters() {
        const container = document.getElementById('activeFilterTags');
        const wrapper = document.getElementById('activeFilters');
        const clearAllBtn = document.getElementById('clearAllFilters');
        const filterCountBadge = document.getElementById('filterCount');
        const brandCountBadge = document.querySelector('.brand-count');
        const categoryCountBadge = document.querySelector('.category-count');

        if (!container || !wrapper) return;

        const tags = [];
        let totalFilters = 0;

        filterState.brands.forEach(brand => {
            tags.push(`<span class="filter-tag">${brand} <i class="fa fa-times remove-tag" data-type="brand" data-value="${brand}"></i></span>`);
            totalFilters++;
        });

        filterState.categories.forEach(catId => {
            const label = document.querySelector(`label[for="cat_${catId}"]`);
            if (label) {
                const catName = label.querySelector('.filter-label-text')?.textContent || label.textContent.trim().split('(')[0].trim();
                tags.push(`<span class="filter-tag">${catName} <i class="fa fa-times remove-tag" data-type="category" data-value="${catId}"></i></span>`);
                totalFilters++;
            }
        });

        if (filterState.minPrice || filterState.maxPrice) {
            const priceText = filterState.minPrice && filterState.maxPrice
                ? `$${filterState.minPrice} - $${filterState.maxPrice}`
                : filterState.minPrice
                    ? `From $${filterState.minPrice}`
                    : `Up to $${filterState.maxPrice}`;
            tags.push(`<span class="filter-tag">${priceText} <i class="fa fa-times remove-tag" data-type="price"></i></span>`);
            totalFilters++;
        }

        if (filterState.inStock) {
            tags.push(`<span class="filter-tag">In Stock <i class="fa fa-times remove-tag" data-type="stock"></i></span>`);
            totalFilters++;
        }

        if (filterState.vehicleYear) {
            tags.push(`<span class="filter-tag">Vehicle Selected <i class="fa fa-times remove-tag" data-type="vehicle"></i></span>`);
            totalFilters++;
        }

        if (tags.length > 0) {
            wrapper.style.display = 'block';
            container.innerHTML = tags.join('');
            if (clearAllBtn) clearAllBtn.style.display = 'block';
            if (filterCountBadge) filterCountBadge.textContent = totalFilters;

            container.querySelectorAll('.remove-tag').forEach(tag => {
                tag.addEventListener('click', function() {
                    const type = this.getAttribute('data-type');
                    const value = this.getAttribute('data-value');
                    removeFilter(type, value);
                });
            });
        } else {
            wrapper.style.display = 'none';
            if (clearAllBtn) clearAllBtn.style.display = 'none';
        }

        if (brandCountBadge) {
            if (filterState.brands.length > 0) {
                brandCountBadge.textContent = filterState.brands.length;
                brandCountBadge.style.display = 'block';
            } else {
                brandCountBadge.style.display = 'none';
            }
        }

        if (categoryCountBadge) {
            if (filterState.categories.length > 0) {
                categoryCountBadge.textContent = filterState.categories.length;
                categoryCountBadge.style.display = 'block';
            } else {
                categoryCountBadge.style.display = 'none';
            }
        }
    }

    function removeFilter(type, value) {
        switch(type) {
            case 'brand':
                filterState.brands = filterState.brands.filter(b => b !== value);
                document.querySelectorAll('.brand-filter').forEach(cb => {
                    if (cb.value === value) cb.checked = false;
                });
                break;
            case 'category':
                filterState.categories = filterState.categories.filter(c => c !== value);
                const catCheckbox = document.getElementById(`cat_${value}`);
                if (catCheckbox) catCheckbox.checked = false;
                break;
            case 'price':
                filterState.minPrice = null;
                filterState.maxPrice = null;

                // Reset dual range sliders to original values
                const minPriceSlider = document.getElementById('minPriceSlider');
                const maxPriceSlider = document.getElementById('maxPriceSlider');
                const minPriceDisplay = document.getElementById('minPriceDisplay');
                const maxPriceDisplay = document.getElementById('maxPriceDisplay');

                if (minPriceSlider) {
                    const originalMin = minPriceSlider.getAttribute('data-original-min');
                    minPriceSlider.value = originalMin;
                    if (minPriceDisplay) minPriceDisplay.textContent = '$' + formatNumber(originalMin);
                }
                if (maxPriceSlider) {
                    const originalMax = maxPriceSlider.getAttribute('data-original-max');
                    maxPriceSlider.value = originalMax;
                    if (maxPriceDisplay) maxPriceDisplay.textContent = '$' + formatNumber(originalMax);
                }
                updateSliderTrack();
                break;
            case 'stock':
                filterState.inStock = false;
                const stockCheckbox = document.getElementById('inStockOnly');
                if (stockCheckbox) stockCheckbox.checked = false;
                break;
            case 'vehicle':
                const clearVehicleBtn = document.getElementById('clearVehicleFilter');
                if (clearVehicleBtn) clearVehicleBtn.click();
                return;
        }
        filterState.page = 1;
        updateProducts();
    }

    function attachBrandListeners() {
        document.querySelectorAll('.brand-filter').forEach(checkbox => {
            checkbox.addEventListener('change', function() {

                if (this.checked) {
                    filterState.brands.push(this.value);
                } else {
                    filterState.brands = filterState.brands.filter(b => b !== this.value);
                }

                filterState.page = 1;
                updateProducts();
            });
        });

    }

    function attachCategoryListeners() {
        document.querySelectorAll('.category-filter').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    filterState.categories.push(this.value);
                } else {
                    filterState.categories = filterState.categories.filter(c => c !== this.value);
                }
                filterState.page = 1;
                updateProducts();
            });
        });
    }

    attachBrandListeners();
    attachCategoryListeners();
    updateActiveFilters();

    // Dual Range Slider Implementation
    const minPriceSlider = document.getElementById('minPriceSlider');
    const maxPriceSlider = document.getElementById('maxPriceSlider');
    const minPriceDisplay = document.getElementById('minPriceDisplay');
    const maxPriceDisplay = document.getElementById('maxPriceDisplay');
    const sliderTrackFill = document.getElementById('sliderTrackFill');
    const applyPriceBtn = document.getElementById('applyPriceFilter');

    function updateSliderTrack() {
        if (!minPriceSlider || !maxPriceSlider || !sliderTrackFill) return;

        const min = parseFloat(minPriceSlider.min);
        const max = parseFloat(minPriceSlider.max);
        const minVal = parseFloat(minPriceSlider.value);
        const maxVal = parseFloat(maxPriceSlider.value);

        const percentMin = ((minVal - min) / (max - min)) * 100;
        const percentMax = ((maxVal - min) / (max - min)) * 100;

        sliderTrackFill.style.left = percentMin + '%';
        sliderTrackFill.style.width = (percentMax - percentMin) + '%';
    }

    function formatNumber(num) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    if (minPriceSlider) {
        minPriceSlider.addEventListener('input', function() {
            let minVal = parseFloat(this.value);
            let maxVal = parseFloat(maxPriceSlider.value);

            // Ensure min doesn't exceed max
            if (minVal > maxVal - 1) {
                minVal = maxVal - 1;
                this.value = minVal;
            }

            if (minPriceDisplay) {
                minPriceDisplay.textContent = '$' + formatNumber(minVal);
            }

            updateSliderTrack();
        });
    }

    if (maxPriceSlider) {
        maxPriceSlider.addEventListener('input', function() {
            let maxVal = parseFloat(this.value);
            let minVal = parseFloat(minPriceSlider.value);

            // Ensure max doesn't go below min
            if (maxVal < minVal + 1) {
                maxVal = minVal + 1;
                this.value = maxVal;
            }

            if (maxPriceDisplay) {
                maxPriceDisplay.textContent = '$' + formatNumber(maxVal);
            }

            updateSliderTrack();
        });
    }

    // Apply Price Filter Button
    if (applyPriceBtn) {
        applyPriceBtn.addEventListener('click', function() {
            const originalMin = parseFloat(minPriceSlider.getAttribute('data-original-min'));
            const originalMax = parseFloat(maxPriceSlider.getAttribute('data-original-max'));

            const currentMin = parseFloat(minPriceSlider.value);
            const currentMax = parseFloat(maxPriceSlider.value);

            // Only apply if values have changed from original
            if (currentMin !== originalMin || currentMax !== originalMax) {
                filterState.minPrice = currentMin;
                filterState.maxPrice = currentMax;
            } else {
                // Reset to null if back to original range
                filterState.minPrice = null;
                filterState.maxPrice = null;
            }

            filterState.page = 1;
            updateProducts();
        });
    }

    // Initialize slider track on load
    updateSliderTrack();

    const inStockEl = document.getElementById('inStockOnly');
    if (inStockEl) {
        inStockEl.addEventListener('change', function() {
            filterState.inStock = this.checked;
            filterState.page = 1;
            updateProducts();
        });
    }

    const sortByEl = document.getElementById('sortBy');
    if (sortByEl) {
        // Set dropdown to match URL parameter on load
        if (filterState.sort) {
            sortByEl.value = filterState.sort;
        }

        sortByEl.addEventListener('change', function() {
            filterState.sort = this.value;
            filterState.page = 1;

            // Update URL so browser back button works
            const url = new URL(window.location);
            url.searchParams.set('sort', this.value);
            url.searchParams.delete('page'); // Reset to page 1 on sort change
            window.history.pushState({}, '', url);

            updateProducts();
        });
    }

    const clearFiltersEl = document.getElementById('clearAllFilters');
    if (clearFiltersEl) {
        clearFiltersEl.addEventListener('click', function() {
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

            document.querySelectorAll('.brand-filter').forEach(cb => cb.checked = false);
            document.querySelectorAll('.category-filter').forEach(cb => cb.checked = false);

            // Reset dual range sliders
            const minPriceSlider = document.getElementById('minPriceSlider');
            const maxPriceSlider = document.getElementById('maxPriceSlider');
            const minPriceDisplay = document.getElementById('minPriceDisplay');
            const maxPriceDisplay = document.getElementById('maxPriceDisplay');

            if (minPriceSlider) {
                const originalMin = minPriceSlider.getAttribute('data-original-min');
                minPriceSlider.value = originalMin;
                if (minPriceDisplay) minPriceDisplay.textContent = '$' + formatNumber(originalMin);
            }
            if (maxPriceSlider) {
                const originalMax = maxPriceSlider.getAttribute('data-original-max');
                maxPriceSlider.value = originalMax;
                if (maxPriceDisplay) maxPriceDisplay.textContent = '$' + formatNumber(originalMax);
            }
            updateSliderTrack();

            const inStock = document.getElementById('inStockOnly');
            if (inStock) inStock.checked = false;

            const vehicleType = document.getElementById('vehicleType');
            const vehicleMake = document.getElementById('vehicleMake');
            const vehicleModel = document.getElementById('vehicleModel');
            const vehicleYear = document.getElementById('vehicleYear');
            const applyVehicleBtn = document.getElementById('applyVehicleFilter');
            const clearVehicleBtn = document.getElementById('clearVehicleFilter');

            if (vehicleType) vehicleType.value = '';
            if (vehicleMake) {
                vehicleMake.innerHTML = '<option value="">Select Make</option>';
                vehicleMake.disabled = true;
            }
            if (vehicleModel) {
                vehicleModel.innerHTML = '<option value="">Select Model</option>';
                vehicleModel.disabled = true;
            }
            if (vehicleYear) {
                vehicleYear.innerHTML = '<option value="">Select Year</option>';
                vehicleYear.disabled = true;
            }
            if (applyVehicleBtn) applyVehicleBtn.disabled = true;
            if (clearVehicleBtn) clearVehicleBtn.style.display = 'none';

            updateProducts();
        });
    }

    const brandSearchEl = document.getElementById('brandSearch');
    if (brandSearchEl) {
        brandSearchEl.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('#brandOptions .form-check').forEach(item => {
                const label = item.querySelector('label').textContent.toLowerCase();
                item.style.display = label.includes(searchTerm) ? 'block' : 'none';
            });
        });
    }

    const categorySearchEl = document.getElementById('categorySearch');
    if (categorySearchEl) {
        categorySearchEl.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('#categoryOptions .form-check').forEach(item => {
                const label = item.querySelector('label').textContent.toLowerCase();
                item.style.display = label.includes(searchTerm) ? 'block' : 'none';
            });
        });
    }

    loadVehicleTypes();

    function loadVehicleTypes() {
        const vehicleType = document.getElementById('vehicleType');
        if (!vehicleType) {

            return;
        }



        fetch('/api/vehicle-types')
            .then(response => response.json())
            .then(data => {

                const validTypes = data.filter(type => type.description && type.description.trim() !== '');


                validTypes.forEach(type => {
                    const option = document.createElement('option');
                    option.value = type.id;
                    option.textContent = type.description;
                    vehicleType.appendChild(option);
                });

                if (window.initialVehicleFilters && window.initialVehicleFilters.vehicleType) {

                    setTimeout(() => preSelectVehicle(), 200);
                }
            })
            .catch(error => {
                console.error('Error loading vehicle types:', error);
            });
    }

    function preSelectVehicle() {
        if (!window.initialVehicleFilters) {

            return;
        }



        const vehicleTypeEl = document.getElementById('vehicleType');
        const vehicleMakeEl = document.getElementById('vehicleMake');
        const vehicleModelEl = document.getElementById('vehicleModel');
        const vehicleYearEl = document.getElementById('vehicleYear');
        const applyBtn = document.getElementById('applyVehicleFilter');
        const clearBtn = document.getElementById('clearVehicleFilter');

        if (!vehicleTypeEl) {

            return;
        }

        const typeId = window.initialVehicleFilters.vehicleType;
        if (typeId && typeId !== '') {

            vehicleTypeEl.value = typeId;
            filterState.vehicleType = typeId;

        }

        if (typeId && typeId !== '') {

            fetch(`/api/makes?type_id=${typeId}`)
                .then(response => response.json())
                .then(data => {

                    vehicleMakeEl.innerHTML = '<option value="">Select Make</option>';
                    data.filter(make => make.description && make.description.trim() !== '').forEach(make => {
                        const option = document.createElement('option');
                        option.value = make.id;
                        option.textContent = make.description;
                        vehicleMakeEl.appendChild(option);
                    });
                    vehicleMakeEl.disabled = false;

                    const makeId = window.initialVehicleFilters.vehicleMake;
                    if (makeId && makeId !== '') {

                        vehicleMakeEl.value = makeId;
                        filterState.vehicleMake = makeId;

                    }

                    if (makeId && makeId !== '') {

                        fetch(`/api/models?type_id=${typeId}&make_id=${makeId}`)
                            .then(response => response.json())
                            .then(data => {

                                vehicleModelEl.innerHTML = '<option value="">Select Model</option>';
                                data.filter(model => model.description && model.description.trim() !== '').forEach(model => {
                                    const option = document.createElement('option');
                                    option.value = model.id;
                                    option.textContent = model.description;
                                    vehicleModelEl.appendChild(option);
                                });
                                vehicleModelEl.disabled = false;

                                const modelId = window.initialVehicleFilters.vehicleModel;
                                if (modelId && modelId !== '') {

                                    vehicleModelEl.value = modelId;
                                    filterState.vehicleModel = modelId;

                                }

                                if (modelId && modelId !== '') {

                                    fetch(`/api/years?type_id=${typeId}&make_id=${makeId}&model_id=${modelId}`)
                                        .then(response => response.json())
                                        .then(data => {

                                            vehicleYearEl.innerHTML = '<option value="">Select Year</option>';
                                            data.filter(year => year.description && year.description.trim() !== '').forEach(year => {
                                                const option = document.createElement('option');
                                                option.value = year.id;
                                                option.textContent = year.description;
                                                vehicleYearEl.appendChild(option);
                                            });
                                            vehicleYearEl.disabled = false;

                                            const yearId = window.initialVehicleFilters.vehicleYear;
                                            if (yearId && yearId !== '') {

                                                vehicleYearEl.value = yearId;
                                                filterState.vehicleYear = yearId;

                                            }

                                            if (yearId && yearId !== '' && applyBtn && clearBtn) {
                                                applyBtn.disabled = false;
                                                clearBtn.style.display = 'block';

                                                if (typeof updateActiveFilters === 'function') {
                                                    updateActiveFilters();
                                                }
                                            }
                                        });
                                }
                            });
                    }
                });
        }
    }

    const vehicleTypeEl = document.getElementById('vehicleType');
    if (vehicleTypeEl) {
        vehicleTypeEl.addEventListener('change', function() {
            const typeId = this.value;
            filterState.vehicleType = typeId;
            const makeEl = document.getElementById('vehicleMake');
            const modelEl = document.getElementById('vehicleModel');
            const yearEl = document.getElementById('vehicleYear');
            const applyBtn = document.getElementById('applyVehicleFilter');

            makeEl.innerHTML = '<option value="">Make</option>';
            modelEl.innerHTML = '<option value="">Model</option>';
            yearEl.innerHTML = '<option value="">Year</option>';
            makeEl.disabled = true;
            modelEl.disabled = true;
            yearEl.disabled = true;
            applyBtn.disabled = true;

            if (typeId) {
                fetch(`/api/makes?type_id=${typeId}`)
                    .then(response => response.json())
                    .then(data => {
                        data.filter(make => make.description && make.description.trim() !== '').forEach(make => {
                            const option = document.createElement('option');
                            option.value = make.id;
                            option.textContent = make.description;
                            makeEl.appendChild(option);
                        });
                        makeEl.disabled = false;
                    });
            }
        });
    }

    const vehicleMakeEl = document.getElementById('vehicleMake');
    if (vehicleMakeEl) {
        vehicleMakeEl.addEventListener('change', function() {
            const typeId = document.getElementById('vehicleType').value;
            const makeId = this.value;
            filterState.vehicleMake = makeId;
            const modelEl = document.getElementById('vehicleModel');
            const yearEl = document.getElementById('vehicleYear');
            const applyBtn = document.getElementById('applyVehicleFilter');

            modelEl.innerHTML = '<option value="">Model</option>';
            yearEl.innerHTML = '<option value="">Year</option>';
            modelEl.disabled = true;
            yearEl.disabled = true;
            applyBtn.disabled = true;

            if (makeId) {
                fetch(`/api/models?type_id=${typeId}&make_id=${makeId}`)
                    .then(response => response.json())
                    .then(data => {
                        data.filter(model => model.description && model.description.trim() !== '').forEach(model => {
                            const option = document.createElement('option');
                            option.value = model.id;
                            option.textContent = model.description;
                            modelEl.appendChild(option);
                        });
                        modelEl.disabled = false;
                    });
            }
        });
    }

    const vehicleModelEl = document.getElementById('vehicleModel');
    if (vehicleModelEl) {
        vehicleModelEl.addEventListener('change', function() {
            const typeId = document.getElementById('vehicleType').value;
            const makeId = document.getElementById('vehicleMake').value;
            const modelId = this.value;
            filterState.vehicleModel = modelId;
            const yearEl = document.getElementById('vehicleYear');
            const applyBtn = document.getElementById('applyVehicleFilter');

            yearEl.innerHTML = '<option value="">Year</option>';
            yearEl.disabled = true;
            applyBtn.disabled = true;

            if (modelId) {
                fetch(`/api/years?type_id=${typeId}&make_id=${makeId}&model_id=${modelId}`)
                    .then(response => response.json())
                    .then(data => {
                        data.filter(year => year.description && year.description.trim() !== '').forEach(year => {
                            const option = document.createElement('option');
                            option.value = year.id;
                            option.textContent = year.description;
                            yearEl.appendChild(option);
                        });
                        yearEl.disabled = false;
                    });
            }
        });
    }

    const vehicleYearEl = document.getElementById('vehicleYear');
    if (vehicleYearEl) {
        vehicleYearEl.addEventListener('change', function() {
            filterState.vehicleYear = this.value;
            const applyBtn = document.getElementById('applyVehicleFilter');
            applyBtn.disabled = !this.value;
        });
    }

    const applyVehicleBtn = document.getElementById('applyVehicleFilter');
    if (applyVehicleBtn) {
        applyVehicleBtn.addEventListener('click', function() {
            filterState.page = 1;
            updateProducts();
            document.getElementById('clearVehicleFilter').style.display = 'block';
        });
    }

    const clearVehicleBtn = document.getElementById('clearVehicleFilter');
    if (clearVehicleBtn) {
        clearVehicleBtn.addEventListener('click', function() {
            filterState.vehicleType = null;
            filterState.vehicleMake = null;
            filterState.vehicleModel = null;
            filterState.vehicleYear = null;
            filterState.page = 1;

            document.getElementById('vehicleType').value = '';
            document.getElementById('vehicleMake').innerHTML = '<option value="">Make</option>';
            document.getElementById('vehicleModel').innerHTML = '<option value="">Model</option>';
            document.getElementById('vehicleYear').innerHTML = '<option value="">Year</option>';
            document.getElementById('vehicleMake').disabled = true;
            document.getElementById('vehicleModel').disabled = true;
            document.getElementById('vehicleYear').disabled = true;
            document.getElementById('applyVehicleFilter').disabled = true;
            this.style.display = 'none';

            updateProducts();
        });
    }

    // Handle initial server-side pagination clicks
    function attachPaginationListeners() {
        const paginationWrapper = document.getElementById('paginationWrapper');
        if (!paginationWrapper) return;

        paginationWrapper.querySelectorAll('a.page-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                // Extract page number from href or data attribute
                const href = this.getAttribute('href');
                let page = null;

                if (href && href.includes('page=')) {
                    const urlParams = new URLSearchParams(href.split('?')[1]);
                    page = parseInt(urlParams.get('page'));
                } else if (this.textContent.trim() === '«') {
                    page = filterState.page - 1;
                } else if (this.textContent.trim() === '»') {
                    page = filterState.page + 1;
                } else {
                    page = parseInt(this.textContent.trim());
                }

                if (page && page > 0) {
                    filterState.page = page;
                    updateProducts();
                    document.querySelector('.products-section').scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    }

    // Attach listeners on initial page load
    attachPaginationListeners();
});
