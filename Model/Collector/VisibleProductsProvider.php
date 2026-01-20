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

namespace Amadeco\ElasticSuiteBehavioral\Model\Collector;

/**
 * Class VisibleProductsProvider
 *
 * Request-scoped registry used to collect Product IDs displayed on the current page.
 *
 * This provider acts as a singleton (shared instance) during the request lifecycle.
 * It serves as a bridge between the Presentation Layer (Blocks displaying products)
 * and the Tracking Layer (ElasticSuite Tracker), adhering to the Inversion of Control principle.
 */
class VisibleProductsProvider
{
    /**
     * @var int[] List of unique product entity IDs visible on the current page.
     */
    private array $productIds = [];

    /**
     * Register a batch of visible product IDs.
     *
     * This method merges the provided IDs with the already collected IDs.
     * It ensures uniqueness to prevent double-counting impressions if a product
     * appears in multiple blocks (e.g., Category List + Related Products Widget).
     *
     * @param int[] $ids List of Product Entity IDs to register.
     * @return void
     */
    public function addProductIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        // Merge existing IDs with new IDs and remove duplicates.
        // SORT_NUMERIC ensures faster processing for integer IDs.
        $this->productIds = array_unique(
            array_merge($this->productIds, $ids),
            SORT_NUMERIC
        );
    }

    /**
     * Retrieve the list of all collected Product IDs.
     *
     * Returns a flat array of unique integers representing the products
     * rendered during this request cycle up to this point.
     *
     * @return int[]
     */
    public function getVisibleProductIds(): array
    {
        return $this->productIds;
    }
}