<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CmsHomeContentSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();

        $contents = [
            [
                'section_key' => 'hero_section',
                'title' => 'Get <span> Madd</span>',
                'subtitle' => 'Search by Year, Make and Model',
                'content' => 'Ride <span> Hard</span>',
                'image_path' => null,
                'link_url' => null,
                'link_text' => null,
                'extra_data' => null,
                'status' => 1,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section_key' => 'about_section',
                'title' => 'ABOUT <span> MADD </span><span> PARTS </span>',
                'subtitle' => null,
                'content' => '<p>As a family-owned and operated dealership, MADD Parts specializes in offering the finest powersports products to enhance your outdoor lifestyle. Our extensive range includes top-of-the-line motorcycle parts, accessories, and apparel, ensuring a more enjoyable experience. From cutting-edge ATV technology to the latest UTV models, we have everything you need to repair and upgrade your machine.</p><p>Our team of friendly and well-informed experts is dedicated to assisting you in finding the perfect recreational ATV parts, side x side parts, or scooter parts tailored to your requirements. With our comprehensive selection and exceptional customer service, MADD Parts is poised to be your ultimate destination for all your powersports needs.</p>',
                'image_path' => null,
                'link_url' => '/contact',
                'link_text' => 'Contact Maddparts',
                'extra_data' => null,
                'status' => 1,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section_key' => 'shop_now_1',
                'title' => 'New Kawasaki Motocross Parts',
                'subtitle' => 'Introducing',
                'content' => 'Introducing the latest Kawasaki motocross bike parts, designed for peak performance and durability.',
                'image_path' => null,
                'link_url' => '/search?q=kawasaki',
                'link_text' => 'Shop now',
                'extra_data' => null,
                'status' => 1,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section_key' => 'shop_now_2',
                'title' => 'Racing Absolute V2 Leather Suit',
                'subtitle' => 'Introducing New',
                'content' => 'Introducing the Racing Absolute V2 Leather Suit by Alpinestars, designed for the ultimate.',
                'image_path' => null,
                'link_url' => '/search?q=alpinestars',
                'link_text' => 'Shop now',
                'extra_data' => null,
                'status' => 1,
                'sort_order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section_key' => 'support_section',
                'title' => 'Support Madd Parts Kawasaki!',
                'subtitle' => 'SAVE UP TO $200.00',
                'content' => 'Check out Support Madd Parts Kawasaki! They offer top-notch performance',
                'image_path' => null,
                'link_url' => '/search?q=kawasaki',
                'link_text' => 'VIEW MADD PARTS ITEMS',
                'extra_data' => null,
                'status' => 1,
                'sort_order' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section_key' => 'ultimate_sale',
                'title' => 'ULTIMATE',
                'subtitle' => 'SALE',
                'content' => 'NEW COLLECTION',
                'image_path' => null,
                'link_url' => '/products',
                'link_text' => 'SHOP NOW',
                'extra_data' => null,
                'status' => 1,
                'sort_order' => 6,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section_key' => 'newsletter_section',
                'title' => 'Subscribe to our newsletter',
                'subtitle' => null,
                'content' => null,
                'image_path' => null,
                'link_url' => null,
                'link_text' => null,
                'extra_data' => null,
                'status' => 1,
                'sort_order' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($contents as $content) {
            DB::table('cms_home_content')->updateOrInsert(
                ['section_key' => $content['section_key']],
                $content
            );
        }

        $this->command->info('CMS Home Content seeded successfully!');
    }
}
