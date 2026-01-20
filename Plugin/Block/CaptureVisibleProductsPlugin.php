<?php
/**
 * Amadeco_ElasticSuiteBehavioral
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @author    Amadeco Core Team
 * @copyright Copyright (c) 2026 Amadeco
 * @license   Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Plugin\Block;

use Amadeco\ElasticSuiteBehavioral\Model\Collector\VisibleProductsProvider;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Class CaptureVisibleProductsPlugin
 *
 * Intercepts any block extending Magento\Catalog\Block\Product\ListProduct
 * to capture the IDs of products being rendered and push them to the Provider.
 */
class CaptureVisibleProductsPlugin
{
    /**
     * @param VisibleProductsProvider $provider Registry to store visible IDs.
     */
    public function __construct(
        private readonly VisibleProductsProvider $provider
    ) {}

    /**
     * After the collection is loaded by the List Block, capture the IDs.
     *
     * This method is triggered whenever a product list (Category, Search Result,
     * or Compatible Widget) retrieves its product collection.
     *
     * @param ListProduct $subject The block instance.
     * @param Collection  $result  The loaded product collection.
     * @return Collection
     */
    public function afterGetLoadedProductCollection(ListProduct $subject, Collection $result): Collection
    {
        // Optimally extract IDs from the loaded collection.
        // We avoid loading the full model if possible, relying on the collection's loaded items.
        $ids = [];

        foreach ($result as $product) {
            $ids[] = (int)$product->getId();
        }

        if (!empty($ids)) {
            $this->provider->addProductIds($ids);
        }

        return $result;
    }
}