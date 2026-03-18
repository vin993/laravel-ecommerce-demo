<?php

return [
	/* Shop Theme Configuration */
	'shop-default' => 'maddparts',
	'shop' => [
		// 'default' => [
		// 	'name'        => 'Default',
		// 	'assets_path' => 'public/themes/shop/default',
		// 	'views_path'  => 'resources/themes/default/views',
		// 	'vite'        => [
		// 		'hot_file'                 => 'shop-default-vite.hot',
		// 		'build_directory'          => 'themes/shop/default/build',
		// 		'package_assets_directory' => 'src/Resources/assets',
		// 	],
		// ],
		'maddparts' => [
			'name' => 'Madd Parts',
			'assets_path' => 'public/themes/maddparts',
			'views_path' => 'resources/themes/maddparts/views',
			'vite' => [
				'hot_file' => 'maddparts-vite.hot',
				'build_directory' => 'themes/maddparts',
				'package_assets_directory' => 'resources/themes/maddparts/assets',
			],
		],
	],

	/* Admin Theme Configuration */
	'admin-default' => 'default',
	'admin' => [
		'default' => [
			'name' => 'Default',
			'assets_path' => 'public/themes/admin/default',
			'views_path' => 'resources/admin-themes/default/views',
			'vite' => [
				'hot_file' => 'admin-default-vite.hot',
				'build_directory' => 'themes/admin/default/build',
				'package_assets_directory' => 'src/Resources/assets',
			],
		],
	],
];
