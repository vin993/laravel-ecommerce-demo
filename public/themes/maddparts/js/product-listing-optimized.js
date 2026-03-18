document.addEventListener('DOMContentLoaded', function() {
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
        sort: 'name_az',
        page: currentPage
    };


    if (gridViewBtn && listViewBtn && productsList) {
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
                    updateProductsList(data.products, append);
                    updateResultsCount(data.pagination);
                    hasMorePages = data.pagination.has_more_pages;

                    if (loadMoreBtn) {
                        loadMoreBtn.style.display = hasMorePages ? 'block' : 'none';
                    }

                    if (data.filterData) {
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

    function updateProductsList(products, append = false) {
        const container = document.getElementById('productsList');
        if (!container) return;

        if (products.length === 0 && !append) {
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

        return `
            <div class="col-xl-4 col-lg-6 col-md-6 col-6 mb-4">
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
                            <span class="current-price text-dark font-weight-bold">$${parseFloat(product.price).toFixed(2)}</span>
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

    function updateResultsCount(pagination) {
        const resultsCount = document.getElementById('resultsCount');
        if (!resultsCount) return;

        const start = (pagination.current_page - 1) * pagination.per_page + 1;
        const end = Math.min(start + pagination.per_page - 1, pagination.total);

        resultsCount.innerHTML = `Showing <strong>${start}-${end}</strong> of <strong>${pagination.total}</strong> products`;
    }

    function updateFilterOptions(filterData) {
    }

    function updateActiveFilters() {
        const activeFiltersContainer = document.getElementById('activeFilterTags');
        const activeFiltersSection = document.getElementById('activeFilters');
        const filterCount = document.getElementById('filterCount');
        const clearAllBtn = document.getElementById('clearAllFilters');

        if (!activeFiltersContainer) return;

        let filterTags = [];
        let count = 0;

        filterState.brands.forEach(brand => {
            count++;
            filterTags.push(`<span class="filter-tag">Brand: ${brand} <i class="fa fa-times remove-tag" data-filter="brand" data-value="${brand}"></i></span>`);
        });

        filterState.categories.forEach(catId => {
            count++;
            const catName = document.querySelector(`#cat_${catId}`)?.nextElementSibling?.textContent || catId;
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
                tag.addEventListener('click', function() {
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
        switch(filterType) {
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
        loadMoreBtn.addEventListener('click', function() {
            if (!isLoading && hasMorePages) {
                filterState.page++;
                updateProducts(true);
            }
        });
    }

    const sortSelect = document.getElementById('sortBy');
    if (sortSelect) {
        sortSelect.addEventListener('change', function() {
            filterState.sort = this.value;
            filterState.page = 1;
            updateProducts();
        });
    }

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

    const inStockCheckbox = document.getElementById('inStockOnly');
    if (inStockCheckbox) {
        inStockCheckbox.addEventListener('change', function() {
            filterState.inStock = this.checked;
            filterState.page = 1;
            updateProducts();
        });
    }

    const applyPriceBtn = document.getElementById('applyPriceFilter');
    if (applyPriceBtn) {
        applyPriceBtn.addEventListener('click', function() {
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
        clearAllBtn.addEventListener('click', function() {
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
        minPriceSlider.addEventListener('input', function() {
            const minVal = parseInt(this.value);
            const maxVal = parseInt(maxPriceSlider.value);

            if (minVal >= maxVal) {
                this.value = maxVal - 1;
            }

            this.style.zIndex = '5';
            maxPriceSlider.style.zIndex = '4';

            if (minPriceDisplay) {
                minPriceDisplay.textContent = '$' + parseInt(this.value).toLocaleString();
            }
            updateSliderTrack();
        });

        maxPriceSlider.addEventListener('input', function() {
            const minVal = parseInt(minPriceSlider.value);
            const maxVal = parseInt(this.value);

            if (maxVal <= minVal) {
                this.value = minVal + 1;
            }

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
        brandSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('.brand-item').forEach(item => {
                const label = item.querySelector('.filter-label-text').textContent.toLowerCase();
                item.style.display = label.includes(searchTerm) ? 'block' : 'none';
            });
        });
    }

    const categorySearch = document.getElementById('categorySearch');
    if (categorySearch) {
        categorySearch.addEventListener('input', function() {
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

        vehicleType.addEventListener('change', function() {
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
        vehicleMake.addEventListener('change', function() {
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
        vehicleModel.addEventListener('change', function() {
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
        vehicleYear.addEventListener('change', function() {
            applyVehicleBtn.disabled = !this.value;
        });
    }

    if (applyVehicleBtn) {
        applyVehicleBtn.addEventListener('click', function() {
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
        clearVehicleBtn.addEventListener('click', function() {
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
});
