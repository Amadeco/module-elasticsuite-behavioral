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

namespace Amadeco\ElasticSuiteBehavioral\Model\ResourceModel\Data;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for resolving stock status tables (MSI vs Legacy).
 *
 * This class isolates the complexity of detecting Multi-Source Inventory (MSI)
 * configurations and determining the correct database join strategy for
 * retrieving the 'is_salable' status.
 */
final class StockStatusResolver
{
    private const string TABLE_STOCK_STATUS = 'cataloginventory_stock_status';
    private const string MODULE_INVENTORY_SALES_API = 'Magento_InventorySalesApi';
    private const string SALES_CHANNEL_TYPE_WEBSITE = 'website';
    private const string MSI_RESOLVER_INTERFACE = 'Magento\InventorySalesApi\Api\StockResolverInterface';

    /**
     * @var array<int, string|null> Local memoization cache to reduce overhead.
     */
    private array $resolvedTableCache = [];

    /**
     * @param ModuleManager $moduleManager
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Joins the appropriate stock status table to the select query.
     *
     * Detects if MSI is active and resolves the dynamic index table (e.g., inventory_stock_1).
     * Falls back to legacy cataloginventory_stock_status if MSI is disabled or unconfigured.
     *
     * @param Select $select The Magento DB Select object.
     * @param int $storeId The Store View ID.
     * @param string $productTableAlias Alias of the product table (default: 'cpe').
     * @param string $isSalableAlias Alias for the resulting column (default: 'is_salable').
     * @return void
     */
    public function joinStockStatus(
        Select $select,
        int $storeId,
        string $productTableAlias = 'cpe',
        string $isSalableAlias = 'is_salable'
    ): void {
        $connection = $select->getAdapter();

        // 1. Resolve Table (Cached)
        $msiTable = $this->resolveMsiTable($storeId);

        // 2. Security: Escape Alias to prevent SQL Injection
        $safeAlias = $connection->quoteIdentifier($productTableAlias);

        if ($msiTable) {
            // MSI Join Strategy (SKU based)
            // inventory_stock_X tables map 'sku' to 'is_salable' (1 or 0)
            $select->joinLeft(
                ['msi' => $msiTable],
                "msi.sku = {$safeAlias}.sku",
                [
                    $isSalableAlias => new Expression('COALESCE(msi.is_salable, 0)'),
                ]
            );
        } else {
            // Legacy Join Strategy (Entity ID + Website ID)
            $websiteId = $this->getWebsiteId($storeId);
            $legacyTable = $this->resource->getTableName(self::TABLE_STOCK_STATUS);

            $joinCondition = $connection->quoteInto(
                "css.product_id = {$safeAlias}.entity_id AND css.website_id = ? AND css.stock_id = 1",
                $websiteId
            );

            $select->joinLeft(
                ['css' => $legacyTable],
                $joinCondition,
                [
                    $isSalableAlias => new Expression('COALESCE(css.stock_status, 0)'),
                ]
            );
        }
    }

    /**
     * Attempt to find the MSI index table for the current store.
     *
     * Uses local caching to avoid repetitive ObjectManager calls during bulk processing.
     *
     * @param int $storeId
     * @return string|null Table name (e.g., 'inventory_stock_1') or null on failure/legacy.
     */
    private function resolveMsiTable(int $storeId): ?string
    {
        if (array_key_exists($storeId, $this->resolvedTableCache)) {
            return $this->resolvedTableCache[$storeId];
        }

        if (!$this->moduleManager->isEnabled(self::MODULE_INVENTORY_SALES_API)) {
            $this->resolvedTableCache[$storeId] = null;
            return null;
        }

        try {
            // Check interface existence to prevent hard dependency crashes
            if (!interface_exists(self::MSI_RESOLVER_INTERFACE)) {
                $this->resolvedTableCache[$storeId] = null;
                return null;
            }

            // Use ObjectManager to load MSI services dynamically
            $objectManager = ObjectManager::getInstance();
            $stockResolver = $objectManager->get(self::MSI_RESOLVER_INTERFACE);

            $website = $this->storeManager->getStore($storeId)->getWebsite();
            $stock = $stockResolver->execute(
                self::SALES_CHANNEL_TYPE_WEBSITE,
                $website->getCode()
            );

            // Construct table name: inventory_stock_{id}
            $tableName = $this->resource->getTableName('inventory_stock_' . $stock->getStockId());

            // Cache the result: Table exists ? Table Name : Null
            $this->resolvedTableCache[$storeId] = $this->resource->getConnection()->isTableExists($tableName)
                ? $tableName
                : null;
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    "Amadeco Behavioral: MSI Resolution failed for Store %d. "
                    . "Falling back to Legacy. Error: %s",
                    $storeId,
                    $e->getMessage()
                )
            );
            $this->resolvedTableCache[$storeId] = null;
        }

        return $this->resolvedTableCache[$storeId];
    }

    /**
     * Safe retrieval of Website ID from Store ID.
     *
     * @param int $storeId
     * @return int
     */
    private function getWebsiteId(int $storeId): int
    {
        try {
            return (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}