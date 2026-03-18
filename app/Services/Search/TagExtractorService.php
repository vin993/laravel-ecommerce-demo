<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TagExtractorService
{
    protected $vehicleMapper;
    protected $extractedTags = [];

    public function __construct(VehicleTypeMapper $vehicleMapper)
    {
        $this->vehicleMapper = $vehicleMapper;
    }

    public function extractTagsForProduct($product, $dryRun = false)
    {
        $this->extractedTags = [];

        $productId = is_object($product) ? $product->id : $product['id'];
        $productName = is_object($product) ? $product->name : $product['name'];
        $productSku = is_object($product) ? $product->sku : $product['sku'];

        $description = $this->getProductDescription($productId);
        $categoryNames = $this->getCategoryNames($productId);
        $brandName = $this->getBrandName($productId);

        $combinedText = implode(' ', array_filter([
            $productName,
            $description,
            implode(' ', $categoryNames),
            $brandName
        ]));

        $this->extractVehicleTypes($combinedText, $productId);
        $this->extractVehicleBrands($combinedText, $productId);
        $this->extractPartBrands($combinedText);
        $this->extractVehicleModels($combinedText);
        $this->extractPartCategories($combinedText, $categoryNames);
        $this->extractFeatures($combinedText);
        $this->extractApplications($combinedText);

        if ($brandName) {
            $partBrands = array_keys(KeywordDictionary::getPartBrandPatterns());
            $brandLower = strtolower($brandName);

            if (in_array($brandLower, $partBrands)) {
                $this->addTag('part_brand', $brandLower, 95, 'brand_attribute');
            } else {
                $this->addTag('vehicle_brand', $brandLower, 90, 'brand_attribute');
            }
        }

        if ($dryRun) {
            return [
                'product_id' => $productId,
                'sku' => $productSku,
                'name' => $productName,
                'tags' => $this->extractedTags,
                'tag_count' => count($this->extractedTags)
            ];
        }

        return $this->extractedTags;
    }

    protected function getProductDescription($productId)
    {
        $description = DB::table('product_flat')
            ->where('product_id', $productId)
            ->where('locale', 'en')
            ->value('description');

        if (empty($description)) {
            $description = DB::table('product_flat')
                ->where('product_id', $productId)
                ->where('locale', 'en')
                ->value('short_description');
        }

        return $description ?? '';
    }

    protected function getCategoryNames($productId)
    {
        return DB::table('product_categories as pc')
            ->join('category_translations as ct', 'pc.category_id', '=', 'ct.category_id')
            ->where('pc.product_id', $productId)
            ->where('ct.locale', 'en')
            ->pluck('ct.name')
            ->toArray();
    }

    protected function getBrandName($productId)
    {
        $brandOptionId = DB::table('product_attribute_values')
            ->where('product_id', $productId)
            ->where('attribute_id', 25)
            ->value('text_value');

        if ($brandOptionId) {
            $brandName = DB::table('attribute_options')
                ->where('id', $brandOptionId)
                ->value('admin_name');

            return $brandName;
        }

        return null;
    }

    protected function extractVehicleTypes($text, $productId)
    {
        $lowerText = strtolower($text);

        $patterns = KeywordDictionary::getVehicleTypePatterns();

        foreach ($patterns as $vehicleType => $config) {
            $keywords = $config['keywords'];
            $weight = $config['weight'];
            $negatives = $config['negative'] ?? [];

            if (KeywordDictionary::isNegativeMatch($text, $negatives)) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if (strpos($lowerText, strtolower($keyword)) !== false) {
                    $this->addTag('vehicle_type', $vehicleType, $weight, 'description');
                    break;
                }
            }
        }

        $fitmentTypes = $this->vehicleMapper->getVehicleTypesForProduct($productId);
        foreach ($fitmentTypes as $vehicleType) {
            $this->addTag('vehicle_type', $vehicleType, 95, 'fitment');
        }
    }

    protected function extractVehicleBrands($text, $productId)
    {
        $lowerText = strtolower($text);

        $patterns = KeywordDictionary::getVehicleBrandPatterns();

        foreach ($patterns as $brand => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $text)) {
                    $this->addTag('vehicle_brand', $brand, 85, 'description');
                    break;
                }
            }
        }

        $fitmentBrands = $this->vehicleMapper->getVehicleBrandsForProduct($productId);
        foreach ($fitmentBrands as $brand) {
            $this->addTag('vehicle_brand', $brand, 90, 'fitment');
        }
    }

    protected function extractPartBrands($text)
    {
        $patterns = KeywordDictionary::getPartBrandPatterns();

        foreach ($patterns as $brand => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $text)) {
                    $this->addTag('part_brand', $brand, 100, 'description');
                    break;
                }
            }
        }
    }

    protected function extractVehicleModels($text)
    {
        $patterns = KeywordDictionary::getVehicleModelPatterns();

        foreach ($patterns as $modelType => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $modelName = trim($matches[0]);
                $this->addTag('vehicle_model', strtolower($modelName), 80, 'description');
            }
        }
    }

    protected function extractPartCategories($text, $categoryNames)
    {
        $lowerText = strtolower($text);

        $patterns = KeywordDictionary::getPartCategoryPatterns();

        foreach ($patterns as $category => $config) {
            $keywords = $config['keywords'];
            $weight = $config['weight'];
            $negatives = $config['negative'] ?? [];

            if (KeywordDictionary::isNegativeMatch($text, $negatives)) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if (strpos($lowerText, strtolower($keyword)) !== false) {
                    $this->addTag('part_category', $category, $weight, 'description');
                    break;
                }
            }
        }

        foreach ($categoryNames as $catName) {
            $normalized = KeywordDictionary::normalizeKeyword($catName);
            if ($normalized) {
                $configKey = $normalized;
                if (!isset($patterns[$configKey])) {
                    foreach ($patterns as $key => $cfg) {
                        if (in_array($normalized, $cfg['keywords'])) {
                            $configKey = $key;
                            break;
                        }
                    }
                }

                $config = $patterns[$configKey] ?? null;
                if ($config) {
                    $negatives = $config['negative'] ?? [];
                    if (KeywordDictionary::isNegativeMatch($text, $negatives)) {
                        continue;
                    }
                }
                $this->addTag('part_category', $normalized, 70, 'category');
            }
        }
    }

    protected function extractFeatures($text)
    {
        $lowerText = strtolower($text);

        $patterns = KeywordDictionary::getFeaturePatterns();

        foreach ($patterns as $feature => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowerText, strtolower($keyword)) !== false) {
                    $this->addTag('feature', $feature, 60, 'description');
                    break;
                }
            }
        }
    }

    protected function extractApplications($text)
    {
        $patterns = KeywordDictionary::getApplicationPatterns();

        foreach ($patterns as $applicationType => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                if (isset($matches[1])) {
                    $application = trim($matches[1]);
                    if (strlen($application) > 3 && strlen($application) < 100) {
                        $normalized = KeywordDictionary::normalizeKeyword($application);
                        $this->addTag('application', $normalized, 70, 'description');
                    }
                }
            }
        }
    }

    protected function addTag($type, $value, $weight, $source)
    {
        $value = trim($value);

        if (empty($value)) {
            return;
        }

        $key = $type . ':' . $value;

        if (!isset($this->extractedTags[$key])) {
            $this->extractedTags[$key] = [
                'tag_type' => $type,
                'tag_value' => $value,
                'weight' => $weight,
                'source' => $source
            ];
        } else {
            if ($weight > $this->extractedTags[$key]['weight']) {
                $this->extractedTags[$key]['weight'] = $weight;
                $this->extractedTags[$key]['source'] = $source;
            }
        }
    }

    public function saveTags($productId, $tags)
    {
        if (empty($tags)) {
            return 0;
        }

        $insertData = [];
        $timestamp = now();

        foreach ($tags as $tag) {
            $insertData[] = [
                'product_id' => $productId,
                'tag_type' => $tag['tag_type'],
                'tag_value' => $tag['tag_value'],
                'weight' => $tag['weight'],
                'source' => $tag['source'],
                'created_at' => $timestamp
            ];
        }

        if (!empty($insertData)) {
            DB::table('product_search_tags')->insert($insertData);
            return count($insertData);
        }

        return 0;
    }

    public function clearTagsForProduct($productId)
    {
        DB::table('product_search_tags')
            ->where('product_id', $productId)
            ->delete();
    }

    public function getExtractedTags()
    {
        return array_values($this->extractedTags);
    }
}
