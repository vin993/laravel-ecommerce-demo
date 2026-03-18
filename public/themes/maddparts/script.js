jQuery('.confirm_leave').confirm({
	theme: 'supervan',
	icon: 'fa fa-spinner fa-spin',
	columnClass: 'col-md-6 col-md-offset-3',
	autoClose: 'Cancel|8000',
	title: 'Are you sure?',
	content: 'You are now leaving Website',
	buttons: {
		Continue: function () {
			window.open(this.$target.attr('href'));
		},
		Cancel: function () {
			$.alert('Thank you for staying back');
		}
	}
});



// $(document).bind("contextmenu",function(e) {
// 	e.preventDefault();
// });
// $(document).keydown(function(e){
// 	if(e.which === 123){
// 		return false;
// 	}
// 	if (e.ctrlKey && e.shiftKey && (e.keyCode == 'I'.charCodeAt(0) || e.keyCode == 'i'.charCodeAt(0))) {
// 		return false;
// 	}
// });
$(document).ready(function () {
	$.getJSON("https://api.ipify.org/?format=json", function (e) {
		$('input[name="ip_address"]').val(e.ip);
	});
	const recaptchaSiteKey = document.querySelector('script[src*="recaptcha"]')?.src.match(/render=([^&]+)/)?.[1];
	$('form').not('#add-to-cart-form, #product-form, #payment-form, form[role="search"], .search-form, .mobile-search-form, #op_cap_form').on('submit', function (e) {
		let form = this;
		e.preventDefault();
		if (recaptchaSiteKey && typeof grecaptcha !== 'undefined') {
			grecaptcha.ready(function () {
				grecaptcha.execute(recaptchaSiteKey, {action: 'submit'}).then(function (token) {
					let input = form.querySelector('input[name="g-recaptcha-response"]');
					if (!input) {
						input = document.createElement('input');
						input.type = 'hidden';
						input.name = 'g-recaptcha-response';
						form.appendChild(input);
					}
					input.value = token;
					form.submit();
				});
			});
		} else {
			form.submit();
		}
	});

	let pathname = window.location.pathname.replace(/^\/|\/$/g, '');
	$('body').addClass(pathname === '' ? 'page-home' : 'page-' + pathname.replace(/\/+/g, '-').replace(/[^a-zA-Z0-9\-]/g, '').toLowerCase());
	$('body').on('click', '.scrolltop', function() {
		$("html, body").animate({ scrollTop: $("body").offset().top }, 1000);
		return false;
	});
	$('.form-control').unmask();
	$('.quick_links ul li a').each(function () {
		if ($(this).attr('href') == location.href) {
			$(this).addClass('active');
		}
	});

	$('.category_slider_wrap').slick({
		infinite: true,
		autoplay: true,
		autoplaySpeed: 3000,
		speed: 500,
		dots: false,
		arrows: false,
		rows: 0,
		slidesToShow: 6,
		slidesToScroll: 1,
		responsive: [
			{breakpoint: 1400, settings: {slidesToShow: 4}},
			{breakpoint: 1200, settings: {slidesToShow: 3}},
			{breakpoint: 992, settings: {slidesToShow: 2}},
			{breakpoint: 768, settings: {slidesToShow: 1}}
		]
	});
	$('#cat_sld_prev').click(function () {
		$('.category_slider_wrap').slick('slickPrev');
	});
	$('#cat_sld_next').click(function () {
		$('.category_slider_wrap').slick('slickNext');
	});
	$('.brand_slider_wrap').slick({
		infinite: true,
		autoplay: true,
		autoplaySpeed: 3000,
		speed: 500,
		dots: false,
		arrows: true,
		rows: 0,
		slidesToShow: 4,
		slidesToScroll: 1,
		responsive: [
			{breakpoint: 1200, settings: {slidesToShow: 3}},
			{breakpoint: 992, settings: {slidesToShow: 2}},
			{breakpoint: 768, settings: {slidesToShow: 1}}
		]
	});
});

$(window).on('load', function() {
	AOS.init();
	setTimeout(function () {
		$("#preloader").fadeOut();
	}, 5000);
});

$(window).on('resize', function() {
	AOS.refresh();
});

$(window).on("scroll", function() {
	$(window).trigger('resize');
	AOS.refresh();
	var navbar = $("header#site-header");
	if ($(window).scrollTop() > 50) {
		navbar.addClass("scrolled");
	} else {
		navbar.removeClass("scrolled");
	}
	if ($(this).scrollTop() > 50) {
		$('.scrolltop:hidden').stop(true, true).fadeIn();
	} else {
		$('.scrolltop').stop(true, true).fadeOut();
	}
});

function setActiveNav() {
    var currentUrl = window.location.href;

    $('.nav-item a').each(function() {
        if (this.href === currentUrl) {
            $(this).parent('li').addClass('active');
        }
        else if (currentUrl.indexOf($(this).attr('href')) !== -1 && $(this).attr('href') !== '') {
            $(this).parent('li').addClass('active');
        }
    });
}

$(window).on('load', function() {
    setTimeout(setActiveNav, 300);
});





