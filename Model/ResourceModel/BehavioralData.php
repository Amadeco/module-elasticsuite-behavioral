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

namespace Amadeco\ElasticSuiteBehavioral\Model\ResourceModel;

use Amadeco\ElasticSuiteBehavioral\Api\Data\BehavioralMetricInterface;
use Amadeco\ElasticSuiteBehavioral\Api\Data\BehavioralMetricInterfaceFactory;
use Amadeco\ElasticSuiteBehavioral\Model\Config;
use Amadeco\ElasticSuiteBehavioral\Model\ResourceModel\Data\StockStatusResolver;
use Amadeco\ElasticSuiteBehavioral\Model\ResourceModel\Data\TrackerDataCollector;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrator class for Behavioral Data Aggregation.
 *
 * This class coordinates the retrieval of data from heterogeneous sources:
 * 1. Engagement metrics from Elasticsearch (via TrackerDataCollector).
 * 2. Stock status from MSI/Legacy tables (via StockStatusResolver).
 * 3. Transactional data (Sales, Reviews) from MySQL.
 *
 * It yields a Generator of hydrated DTOs to ensure O(1) memory usage during processing.
 */
class BehavioralData
{
    /**
     * Database Tables
     */
    public const string TABLE_BEHAVIORAL_ANALYSIS = 'behavioral_analysis';
    public const string TABLE_CATALOG_PRODUCT = 'catalog_product_entity';
    public const string TABLE_SALES_ORDER_ITEM = 'sales_order_item';
    public const string TABLE_REVIEW_SUMMARY = 'review_entity_summary';

    /**
     * Elasticsearch Field Mapping
     */
    public const string ES_FIELD_IMPRESSION = 'page.product_list.visible_ids';
    public const string ES_FIELD_PDP_VIEW = 'page.product.id';
    public const string ES_FIELD_ADD_TO_CART = 'page.cart.product_id';
    public const string ES_EVENT_VIEW = 'catalog_product_view';
    public const string ES_EVENT_ATC = 'checkout_cart_add';

    /**
     * Processing Constants
     */
    private const int DB_BATCH_SIZE = 1000;
    private const string ATTR_DISCONTINUED = 'discontinued';

    /**
     * @var array<string, int|null> Local cache to prevent repetitive EAV attribute lookups.
     */
    private array $attributeIdCache = [];

    /**
     * @param ResourceConnection $resource
     * @param TimezoneInterface $localeDate
     * @param Config $config
     * @param BehavioralMetricInterfaceFactory $metricFactory
     * @param EavConfig $eavConfig
     * @param LoggerInterface $logger
     * @param StockStatusResolver $stockResolver Service for resolving MSI/Legacy stock logic.
     * @param TrackerDataCollector $trackerCollector Service for fetching ES engagement data.
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TimezoneInterface $localeDate,
        private readonly Config $config,
        private readonly BehavioralMetricInterfaceFactory $metricFactory,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger,
        private readonly StockStatusResolver $stockResolver,
        private readonly TrackerDataCollector $trackerCollector
    ) {
    }

    /**
     * Orchestrates fetching of all metrics and yields fully hydrated DTOs.
     *
     * @param int $storeId
     * @param int $periodDays
     * @return \Generator<BehavioralMetricInterface>
     */
    public function collectAggregatedDataGenerator(int $storeId, int $periodDays): \Generator
    {
        $startDate = $this->getDateThreshold($periodDays);

        // 1. Fetch ElasticSearch Engagement Data (Views, Clicks, ATC)
        $impressionData = $this->trackerCollector->collect($storeId, $startDate, self::ES_FIELD_IMPRESSION);
        $pdpViewData = $this->trackerCollector->collect(
            $storeId,
            $startDate,
            self::ES_FIELD_PDP_VIEW,
            self::ES_EVENT_VIEW
        );
        $atcData = $this->trackerCollector->collect(
            $storeId,
            $startDate,
            self::ES_FIELD_ADD_TO_CART,
            self::ES_EVENT_ATC
        );

        // 2. Stream Database Data via Generator and Merge with ES Data
        yield from $this->getDataForStoreGenerator(
            $storeId,
            $startDate,
            $impressionData,
            $pdpViewData,
            $atcData
        );
    }

    /**
     * Batch save scores to database using Insert On Duplicate.
     *
     * @param array $rows
     * @return void
     */
    public function saveScoresBatch(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName(self::TABLE_BEHAVIORAL_ANALYSIS);

        $updateFields = [
            BehavioralMetricInterface::RAW_VIEWS,
            BehavioralMetricInterface::RAW_CLICKS,
            BehavioralMetricInterface::RAW_ADD_TO_CARTS,
            BehavioralMetricInterface::RAW_SALES,
            BehavioralMetricInterface::RAW_REVENUE,
            BehavioralMetricInterface::RATING_SCORE,
            BehavioralMetricInterface::GLOBAL_SCORE,
            BehavioralMetricInterface::IS_NEW_BOOST,
            BehavioralMetricInterface::UPDATED_AT,
        ];

        foreach (array_chunk($rows, self::DB_BATCH_SIZE) as $chunk) {
            $connection->insertOnDuplicate($tableName, $chunk, $updateFields);
        }
    }

    /**
     * Fetch max sales and revenue ceilings for normalization logic.
     *
     * @param int $storeId
     * @param int $periodDays
     * @return array{max_sales: float, max_revenue: float}
     */
    public function getStoreCeilings(int $storeId, int $periodDays): array
    {
        $connection = $this->resource->getConnection();
        $dateThreshold = $this->getDateThreshold($periodDays);

        $select = $connection->select()
            ->from(
                ['soi' => $this->resource->getTableName(self::TABLE_SALES_ORDER_ITEM)],
                [
                    'max_sales'   => new Expression('MAX(qty_ordered)'),
                    'max_revenue' => new Expression('MAX(base_row_total)'),
                ]
            )
            ->where('created_at >= ?', $dateThreshold)
            ->where('store_id = ?', $storeId);

        $result = $connection->fetchRow($select);

        return [
            'max_sales'   => (float)($result['max_sales'] ?? 1.0),
            'max_revenue' => (float)($result['max_revenue'] ?? 1.0),
        ];
    }

    /**
     * Retrieve global traffic statistics facade.
     *
     * @param int $storeId
     * @param int $periodDays
     * @return array{global_clicks: int, global_views: int, max_atc: float}
     */
    public function getGlobalTrafficStats(int $storeId, int $periodDays): array
    {
        return ['global_clicks' => 0, 'global_views' => 0, 'max_atc' => 50.0];
    }

    /**
     * Internal: SQL Generator that merges SQL data with Elasticsearch maps.
     *
     * @param int $storeId
     * @param string $startDate
     * @param array $impressionData
     * @param array $pdpViewData
     * @param array $atcData
     * @return \Generator
     */
    private function getDataForStoreGenerator(
        int $storeId,
        string $startDate,
        array $impressionData,
        array $pdpViewData,
        array $atcData
    ): \Generator {
        $connection = $this->resource->getConnection();
        $select = $this->buildMainQuery($connection, $storeId, $startDate);

        $stmt = $connection->query($select);

        while ($row = $stmt->fetch()) {
            $pid = (int)$row[BehavioralMetricInterface::PRODUCT_ID];

            yield $this->metricFactory->create([
                'data' => [
                    BehavioralMetricInterface::PRODUCT_ID       => $pid,
                    BehavioralMetricInterface::STORE_ID         => $storeId,
                    BehavioralMetricInterface::RAW_SALES        => (float)($row[BehavioralMetricInterface::RAW_SALES] ?? 0.0),
                    BehavioralMetricInterface::RAW_REVENUE      => (float)($row[BehavioralMetricInterface::RAW_REVENUE] ?? 0.0),
                    BehavioralMetricInterface::RAW_VIEWS        => (int)($impressionData[$pid] ?? 0),
                    BehavioralMetricInterface::RAW_CLICKS       => (int)($pdpViewData[$pid] ?? 0),
                    BehavioralMetricInterface::RAW_ADD_TO_CARTS => (int)($atcData[$pid] ?? 0),
                    BehavioralMetricInterface::RATING_SUMMARY   => (int)($row[BehavioralMetricInterface::RATING_SUMMARY] ?? 0),
                    BehavioralMetricInterface::IS_SALABLE       => (bool)($row[BehavioralMetricInterface::IS_SALABLE] ?? false),
                    BehavioralMetricInterface::IS_DISCONTINUED  => (bool)($row[BehavioralMetricInterface::IS_DISCONTINUED] ?? false),
                    BehavioralMetricInterface::CREATED_AT       => $row[BehavioralMetricInterface::CREATED_AT],
                    BehavioralMetricInterface::NEWS_FROM_DATE   => $row[BehavioralMetricInterface::NEWS_FROM_DATE],
                ],
            ]);
        }
    }

    /**
     * Builds the main SQL query for product attributes and transactional stats.
     *
     * @param AdapterInterface $connection
     * @param int $storeId
     * @param string $startDate
     * @return Select
     */
    private function buildMainQuery(AdapterInterface $connection, int $storeId, string $startDate): Select
    {
        $select = $connection->select()
            ->from(
                ['cpe' => $this->resource->getTableName(self::TABLE_CATALOG_PRODUCT)],
                [
                    BehavioralMetricInterface::PRODUCT_ID => 'entity_id',
                    BehavioralMetricInterface::CREATED_AT => 'created_at',
                ]
            );

        // 1. Stock Status
        if ($this->config->isStockCheckEnabled($storeId)) {
            $this->stockResolver->joinStockStatus($select, $storeId);
        } else {
            $select->columns([BehavioralMetricInterface::IS_SALABLE => new Expression('1')]);
        }

        // 2. Discontinued Status
        if ($this->config->isDiscontinuedCheckEnabled($storeId)) {
            $this->joinAttribute(
                $select,
                self::ATTR_DISCONTINUED,
                $storeId,
                BehavioralMetricInterface::IS_DISCONTINUED,
                'int',
                '0'
            );
        } else {
            $select->columns([BehavioralMetricInterface::IS_DISCONTINUED => new Expression('0')]);
        }

        // 3. News From Date
        $this->joinAttribute(
            $select,
            BehavioralMetricInterface::NEWS_FROM_DATE,
            $storeId,
            BehavioralMetricInterface::NEWS_FROM_DATE,
            'datetime',
            'NULL'
        );

        // 4. Sales Data (Secure chaining of quoteInto)
        $salesJoinCond = $connection->quoteInto(
            'soi.product_id = cpe.entity_id AND soi.parent_item_id IS NULL AND soi.store_id = ?',
            $storeId
        ) . $connection->quoteInto(' AND soi.created_at >= ?', $startDate);

        $select->joinLeft(
            ['soi' => $this->resource->getTableName(self::TABLE_SALES_ORDER_ITEM)],
            $salesJoinCond,
            [
                BehavioralMetricInterface::RAW_SALES   => new Expression('SUM(COALESCE(soi.qty_ordered, 0))'),
                BehavioralMetricInterface::RAW_REVENUE => new Expression('SUM(COALESCE(soi.base_row_total, 0))'),
            ]
        );

        // 5. Review Data
        $select->joinLeft(
            ['res' => $this->resource->getTableName(self::TABLE_REVIEW_SUMMARY)],
            $connection->quoteInto('res.entity_pk_value = cpe.entity_id AND res.store_id = ?', $storeId),
            [
                BehavioralMetricInterface::RATING_SUMMARY => new Expression('COALESCE(MAX(res.rating_summary), 0)'),
            ]
        );

        $select->group('cpe.entity_id');

        return $select;
    }

    /**
     * DRY Helper to join EAV attribute values safely.
     *
     * Uses strict quoteIdentifier for aliases and separated quoteInto calls
     * to prevent SQL Injection and binding errors.
     *
     * @param Select $select
     * @param string $code
     * @param int $storeId
     * @param string $alias
     * @param string $suffix
     * @param string $default
     * @return void
     */
    private function joinAttribute(
        Select $select,
        string $code,
        int $storeId,
        string $alias,
        string $suffix,
        string $default
    ): void {
        $attrId = $this->getAttributeId($code);
        if (!$attrId) {
            $select->columns([$alias => new Expression($default)]);
            return;
        }

        $connection = $select->getAdapter();
        $table = $this->resource->getTableName("catalog_product_entity_$suffix");

        // Secure Identifiers for Aliases
        $defAlias = $connection->quoteIdentifier("attr_{$alias}_def");
        $storeAlias = $connection->quoteIdentifier("attr_{$alias}_store");

        // Join Global (Store 0)
        $defConditions = implode(' AND ', [
            "{$defAlias}.entity_id = cpe.entity_id",
            $connection->quoteInto("{$defAlias}.attribute_id = ?", $attrId),
            "{$defAlias}.store_id = 0",
        ]);

        $select->joinLeft(["attr_{$alias}_def" => $table], $defConditions, []);

        // Join Store View (Store ID)
        $storeConditions = implode(' AND ', [
            "{$storeAlias}.entity_id = cpe.entity_id",
            $connection->quoteInto("{$storeAlias}.attribute_id = ?", $attrId),
            $connection->quoteInto("{$storeAlias}.store_id = ?", $storeId),
        ]);

        $select->joinLeft(
            ["attr_{$alias}_store" => $table],
            $storeConditions,
            [
                $alias => new Expression("COALESCE({$storeAlias}.value, {$defAlias}.value, {$default})"),
            ]
        );
    }

    /**
     * Efficiently resolve Attribute ID by Code using local cache.
     *
     * @param string $code
     * @return int|null
     */
    private function getAttributeId(string $code): ?int
    {
        if (!array_key_exists($code, $this->attributeIdCache)) {
            try {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
                $this->attributeIdCache[$code] = $attribute->getId() ? (int)$attribute->getId() : null;
            } catch (\Exception $e) {
                $this->logger->warning("Amadeco Behavioral: Missing attribute '$code'.");
                $this->attributeIdCache[$code] = null;
            }
        }
        return $this->attributeIdCache[$code];
    }

    /**
     * Helper to format date threshold.
     *
     * @param int $days
     * @return string
     */
    private function getDateThreshold(int $days): string
    {
        return $this->localeDate->date(strtotime("-{$days} days"), null, false)
            ->format(\Magento\Framework\DB\Adapter\Pdo\Mysql::DATETIME_FORMAT);
    }
}