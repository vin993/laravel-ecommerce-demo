<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\Dropship\Turn14DropshipService;

class SyncTurn14Catalog extends Command
{
    protected $signature = 'turn14:sync-catalog {--brand-id=} {--all}';
    protected $description = 'Sync Turn14 catalog to local database';

    public function handle()
    {
        $brandId = $this->option('brand-id');
        $all = $this->option('all');

        $service = new Turn14DropshipService();
        $service->ensureMappingTableExists();
        $this->createCatalogTable();

        $token = $service->getAccessToken();
        $apiUrl = config('turn14.api_url');
        $environment = config('turn14.environment', 'testing');

        if ($environment === 'testing' && !str_contains($apiUrl, 'apitest')) {
            $apiUrl = str_replace('api.turn14.com', 'apitest.turn14.com', $apiUrl);
        }

        if ($brandId) {
            $this->syncBrand($brandId, $token, $apiUrl);
        } elseif ($all) {
            $brands = $this->getBrands($token, $apiUrl);
            foreach ($brands as $brand) {
                $this->syncBrand($brand['id'], $token, $apiUrl);
            }
        } else {
            $this->error('Use --brand-id=ID or --all');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getBrands($token, $apiUrl)
    {
        $response = Http::timeout(30)->withToken($token)->get("{$apiUrl}/v1/brands");
        return $response->successful() ? $response->json()['data'] : [];
    }

    private function syncBrand($brandId, $token, $apiUrl)
    {
        $page = 1;
        $totalPages = 1;

        $this->info("Syncing Brand ID: {$brandId}");

        while ($page <= $totalPages) {
            $response = Http::timeout(30)
                ->withToken($token)
                ->get("{$apiUrl}/v1/items/brand/{$brandId}?page={$page}");

            if (!$response->successful()) {
                $this->error("Failed page {$page}");
                break;
            }

            $data = $response->json();
            $totalPages = $data['meta']['total_pages'] ?? 1;

            foreach ($data['data'] as $item) {
                DB::table('turn14_catalog')->updateOrInsert(
                    ['item_id' => $item['id']],
                    [
                        'part_number' => $item['attributes']['part_number'] ?? null,
                        'mfr_part_number' => $item['attributes']['mfr_part_number'] ?? null,
                        'product_name' => $item['attributes']['product_name'] ?? null,
                        'brand_id' => $brandId,
                        'brand' => $item['attributes']['brand'] ?? null,
                        'updated_at' => now()
                    ]
                );
            }

            $this->line("Page {$page}/{$totalPages}");
            $page++;
        }
    }

    private function createCatalogTable()
    {
        if (!DB::getSchemaBuilder()->hasTable('turn14_catalog')) {
            DB::statement("
                CREATE TABLE turn14_catalog (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    item_id VARCHAR(50) NOT NULL UNIQUE,
                    part_number VARCHAR(100),
                    mfr_part_number VARCHAR(100),
                    product_name VARCHAR(255),
                    brand_id INT,
                    brand VARCHAR(100),
                    updated_at TIMESTAMP NULL,
                    INDEX idx_mfr_part (mfr_part_number),
                    INDEX idx_brand (brand)
                )
            ");
        }
    }
}
