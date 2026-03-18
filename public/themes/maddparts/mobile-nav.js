document.addEventListener('DOMContentLoaded', function() {
	const mobileHamburger = document.getElementById('mobile-hamburger');
	const mobileDrawer = document.getElementById('mobile-drawer');
	const mobileDrawerOverlay = document.getElementById('mobile-drawer-overlay');
	const closeDrawer = document.getElementById('close-drawer');
	const mobileSearchTrigger = document.getElementById('mobile-search-trigger');
	const mobileSearchModal = document.getElementById('mobile-search-modal');
	const closeSearchModal = document.getElementById('close-search-modal');
	const mobileCategoriesList = document.getElementById('mobile-categories-list');
	const mobileMenuItems = document.getElementById('mobile-menu-items');
	const siteHeader = document.getElementById('site-header');

	let lastScrollTop = 0;
	let isCategoriesLoaded = false;
	let isMenuItemsLoaded = false;

	if (mobileHamburger) {
		mobileHamburger.addEventListener('click', openDrawer);
	}

	if (closeDrawer) {
		closeDrawer.addEventListener('click', closeDrawerFunc);
	}

	if (mobileDrawerOverlay) {
		mobileDrawerOverlay.addEventListener('click', closeDrawerFunc);
	}

	if (mobileSearchTrigger) {
		mobileSearchTrigger.addEventListener('click', openSearchModal);
	}

	if (closeSearchModal) {
		closeSearchModal.addEventListener('click', closeSearchModalFunc);
	}

	function openDrawer() {
		mobileDrawer.classList.add('active');
		mobileDrawerOverlay.classList.add('active');
		document.body.classList.add('drawer-open');

		if (!isCategoriesLoaded) {
			loadMobileCategories();
		}

		if (!isMenuItemsLoaded) {
			loadMenuItems();
		}
	}

	function closeDrawerFunc() {
		mobileDrawer.classList.remove('active');
		mobileDrawerOverlay.classList.remove('active');
		document.body.classList.remove('drawer-open');
	}

	function openSearchModal() {
		mobileSearchModal.classList.add('active');
		document.body.classList.add('search-modal-open');

		const mobileSearchInput = document.getElementById('mobile-search-input');
		const mobileSearchResults = document.getElementById('mobile-search-results');

		if (mobileSearchInput) {
			if (mobileSearchInput.value.trim().length === 0) {
				mobileSearchResults.innerHTML = '';
				mobileSearchResults.style.display = 'none';
			}

			setTimeout(() => {
				mobileSearchInput.focus();
			}, 300);
		}
	}

	function closeSearchModalFunc() {
		mobileSearchModal.classList.remove('active');
		document.body.classList.remove('search-modal-open');
	}

	function loadMobileCategories() {
		if (!mobileCategoriesList) {
			isCategoriesLoaded = true;
			return;
		}

		fetch('/api/mega-menu-categories')
			.then(res => res.json())
			.then(data => {
				if (data.success && Array.isArray(data.categories)) {
					mobileCategoriesList.innerHTML = '';

					data.categories.forEach(cat => {
						const categoryItem = document.createElement('div');
						categoryItem.className = 'mobile-category-item';

						const hasSubcategories = cat.subcategories && cat.subcategories.length > 0;

						let subcategoriesHtml = '';
						if (hasSubcategories) {
							subcategoriesHtml = '<div class="mobile-subcategories">';
							cat.subcategories.forEach(sub => {
								subcategoriesHtml += `<a href="${sub.url}" class="mobile-subcategory-link">${sub.name}</a>`;
							});
							subcategoriesHtml += '</div>';
						}

						categoryItem.innerHTML = `
							<div class="mobile-category-header">
								<a href="${cat.url}" class="mobile-category-name">${cat.name}</a>
								${hasSubcategories ? '<button class="mobile-category-toggle" aria-label="Toggle subcategories"><i class="fa-solid fa-chevron-down"></i></button>' : ''}
							</div>
							${subcategoriesHtml}
						`;

						mobileCategoriesList.appendChild(categoryItem);

						if (hasSubcategories) {
							const toggleBtn = categoryItem.querySelector('.mobile-category-toggle');
							const subcategoriesDiv = categoryItem.querySelector('.mobile-subcategories');

							toggleBtn.addEventListener('click', function(e) {
								e.stopPropagation();
								const isActive = subcategoriesDiv.classList.contains('active');

								document.querySelectorAll('.mobile-subcategories.active').forEach(sub => {
									sub.classList.remove('active');
								});
								document.querySelectorAll('.mobile-category-toggle.active').forEach(btn => {
									btn.classList.remove('active');
								});

								if (!isActive) {
									subcategoriesDiv.classList.add('active');
									toggleBtn.classList.add('active');
								}
							});
						}
					});

					isCategoriesLoaded = true;
				} else if (mobileCategoriesList) {
					mobileCategoriesList.innerHTML = '<p class="text-center text-muted p-3">No categories available</p>';
				}
			})
			.catch(err => {
				console.error('Mobile categories load error:', err);
				if (mobileCategoriesList) {
					mobileCategoriesList.innerHTML = '<p class="text-center text-danger p-3">Error loading categories</p>';
				}
			});
	}

	function loadMenuItems() {
		if (!mobileMenuItems || window.mobileMenuLoadedByInline || mobileMenuItems.getAttribute('data-loaded') === 'true') {
			isMenuItemsLoaded = true;
			return;
		}

		fetch('/api/header-categories')
			.then(res => res.json())
			.then(data => {
				if (data.success && Array.isArray(data.categories)) {
					mobileMenuItems.innerHTML = '';

					data.categories.forEach(cat => {
						const hasChildren = cat.children && cat.children.length > 0;

						if (hasChildren) {
							// Create menu item with dropdown for categories with children
							const menuItem = document.createElement('div');
							menuItem.className = 'mobile-menu-item-dropdown';

							let childrenHtml = '<div class="mobile-menu-submenu">';
							cat.children.forEach(child => {
								childrenHtml += `<a href="${child.url}" class="mobile-menu-submenu-link">${child.name}</a>`;
							});
							childrenHtml += '</div>';

							menuItem.innerHTML = `
								<div class="mobile-menu-item-header">
									<a href="${cat.url}" class="mobile-menu-link">${cat.name}</a>
									<button class="mobile-menu-toggle" aria-label="Toggle submenu"><i class="fa-solid fa-chevron-down"></i></button>
								</div>
								${childrenHtml}
							`;

							mobileMenuItems.appendChild(menuItem);

							// Add toggle functionality
							const toggleBtn = menuItem.querySelector('.mobile-menu-toggle');
							const submenuDiv = menuItem.querySelector('.mobile-menu-submenu');

							toggleBtn.addEventListener('click', function(e) {
								e.stopPropagation();
								const isActive = submenuDiv.classList.contains('active');

								// Close other open submenus
								document.querySelectorAll('.mobile-menu-submenu.active').forEach(sub => {
									sub.classList.remove('active');
								});
								document.querySelectorAll('.mobile-menu-toggle.active').forEach(btn => {
									btn.classList.remove('active');
								});

								// Toggle current submenu
								if (!isActive) {
									submenuDiv.classList.add('active');
									toggleBtn.classList.add('active');
								}
							});
						} else {
							// Create simple menu link for categories without children
							const menuLink = document.createElement('a');
							menuLink.className = 'mobile-menu-link';
							menuLink.href = cat.url;
							menuLink.textContent = cat.name;
							mobileMenuItems.appendChild(menuLink);
						}
					});

					isMenuItemsLoaded = true;
				}
			})
			.catch(err => {
				console.error('Mobile menu items load error:', err);
			});
	}


	let mobileSearchTimeout;

	function attachMobileSearchListener() {
		const mobileSearchInput = document.getElementById('mobile-search-input');



		if (mobileSearchInput) {
			mobileSearchInput.addEventListener('input', function() {

				clearTimeout(mobileSearchTimeout);

				const query = this.value.trim();

				if (query.length < 2) {
					const resultsDiv = document.getElementById('mobile-search-results');
					resultsDiv.innerHTML = '';
					resultsDiv.style.display = 'none';
					return;
				}

				mobileSearchTimeout = setTimeout(() => {

					fetchMobileSuggestions();
				}, 300);
			});


		} else {
			console.error('Mobile search input element not found!');
		}
	}

	attachMobileSearchListener();

	function fetchMobileSuggestions() {
		const mobileSearchInput = document.getElementById('mobile-search-input');
		const mobileSearchResults = document.getElementById('mobile-search-results');
		const mobileSearchCategory = document.getElementById('mobile-search-category');



		if (!mobileSearchInput || !mobileSearchResults) {
			console.error('Required elements not found!');
			return;
		}

		const query = mobileSearchInput.value.trim();
		const category = mobileSearchCategory ? mobileSearchCategory.value : '';



		if (query.length < 2) {
			mobileSearchResults.innerHTML = '';
			mobileSearchResults.style.display = 'none';
			return;
		}

		mobileSearchResults.innerHTML = '<div class="text-center py-3 text-muted"><i class="fa fa-spinner fa-spin me-2"></i>Searching...</div>';
		mobileSearchResults.style.display = 'block';

		let url = window.mobileSearchAutocompleteUrl || '/shop/search/autocomplete';
		url += `?q=${encodeURIComponent(query)}`;



		fetch(url)
			.then(response => {

				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			})
			.then(data => {


				if (data.suggestions && data.suggestions.length > 0) {


					const resultsHtml = data.suggestions.map(item => `
						<a href="${item.url}" class="mobile-suggestion-item">
							<img src="${item.image}" alt="${item.name}" onerror="this.src='/themes/maddparts/images/placeholder.jpg'">
							<div class="mobile-suggestion-details">
								<div class="mobile-suggestion-name">${item.name}</div>
								<div class="mobile-suggestion-meta">
									<span class="mobile-suggestion-sku">SKU: ${item.sku}</span>
									<span class="mobile-suggestion-price">${item.price ? '$' + parseFloat(item.price).toFixed(2) : 'See options'}</span>
								</div>
							</div>
						</a>
					`).join('');



					mobileSearchResults.innerHTML = resultsHtml;
					mobileSearchResults.style.display = 'block';
					mobileSearchResults.classList.add('active');


				} else {

					mobileSearchResults.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-search fa-2x mb-2 opacity-50"></i><p class="mb-0">No products found</p></div>';
					mobileSearchResults.style.display = 'block';
				}
			})
			.catch(error => {
				console.error('Mobile search error:', error);
				mobileSearchResults.innerHTML = '<div class="text-center text-danger py-3"><i class="fa-solid fa-exclamation-triangle me-2"></i>Error loading results</div>';
				mobileSearchResults.style.display = 'block';
			});
	}

	window.validateMobileSearch = function() {
		const searchInput = document.getElementById('mobile-search-input');
		const categoryInput = document.getElementById('mobile-search-category');
		const searchValue = searchInput.value.trim();
		const categoryValue = categoryInput ? categoryInput.value : '';

		if (!searchValue && !categoryValue) {
			alert('Please enter a search keyword or select a category.');
			searchInput.focus();
			return false;
		}

		return true;
	};

	let scrollTimeout;
	const scrollThreshold = 150;

	window.addEventListener('scroll', function() {
		if (window.innerWidth >= 992) {
			return;
		}

		clearTimeout(scrollTimeout);

		scrollTimeout = setTimeout(function() {
			const scrollTop = window.pageYOffset || document.documentElement.scrollTop;

			if (scrollTop > scrollThreshold) {
				if (scrollTop > lastScrollTop) {
					siteHeader.classList.add('header-minimal');
				} else {
					siteHeader.classList.remove('header-minimal');
				}
			} else {
				siteHeader.classList.remove('header-minimal');
			}

			lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;
		}, 100);
	});

	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			if (mobileDrawer.classList.contains('active')) {
				closeDrawerFunc();
			}
			if (mobileSearchModal.classList.contains('active')) {
				closeSearchModalFunc();
			}
		}
	});
});
