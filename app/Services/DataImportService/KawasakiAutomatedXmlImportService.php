<?php

namespace App\Services\DataImportService;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleXMLElement;

class KawasakiAutomatedXmlImportService extends AutomatedXmlImportService
{
    /**
     * Overridden to handle attributes in the Kawasaki XML structure.
     */
    protected function getSkuFromNode(SimpleXMLElement $node): string
    {
        return (string) $node['ItemNumber'];
    }

    /**
     * Overridden to handle attributes in the Kawasaki XML structure.
     */
    protected function processItemNode(SimpleXMLElement $node, bool $syncImages, &$stats, bool $dryRun, bool $onlySyncImages = false): void
    {
        parent::processItemNode($node, $syncImages, $stats, $dryRun, $onlySyncImages);
    }

    /**
     * Overridden to map Kawasaki-specific attributes to product fields.
     */
    protected function mapAttributes(SimpleXMLElement $node): array
    {
        $description = (string) ($node->ExtendedDescription ?? $node['ItemDescription']);
        $name = (string) $node['ItemDescription'];
        $sku = (string) $node['ItemNumber'];
        $sizeAndStyle = (string) $node['SizeAndStyle'];

        return [
            'name' => $name,
            'price' => (float) $node['MsrpPriceAmt'],
            'weight' => $this->parseWeight((string) $node['ItemWeight']),
            'length' => $this->parseDimension((string) $node['ItemLength']),
            'width' => $this->parseDimension((string) $node['ItemWidth']),
            'height' => $this->parseDimension((string) $node['ItemHeight']),
            'description' => $description,
            'short_description' => Str::limit($description, 150),
            'url_key' => Str::slug($name . '-' . $sku),
            'brand' => 'Kawasaki',
            'status' => 1,
            'visible_individually' => 1,
            'size_and_style' => $sizeAndStyle,
        ];
    }

    protected function updateProductAttributeValues(int $productId, array $attributes): void
    {
        $sizeAndStyleAttrId = DB::table('attributes')
            ->where('code', 'size_and_style')
            ->value('id');

        parent::updateProductAttributeValues($productId, $attributes);

        if ($sizeAndStyleAttrId && isset($attributes['size_and_style']) && !empty($attributes['size_and_style'])) {
            DB::table('product_attribute_values')->updateOrInsert(
                [
                    'product_id' => $productId,
                    'attribute_id' => $sizeAndStyleAttrId,
                    'channel' => 'maddparts',
                    'locale' => 'en',
                ],
                [
                    'product_id' => $productId,
                    'attribute_id' => $sizeAndStyleAttrId,
                    'channel' => 'maddparts',
                    'locale' => 'en',
                    'text_value' => $attributes['size_and_style'],
                ]
            );
        }
    }
}
