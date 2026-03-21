<?php
/**
 * Amadeco_ElasticSuiteBehavioral
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @author    Amadeco Core Team
 */

declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Model\ResourceModel;

use Amadeco\ElasticSuiteBehavioral\Api\Data\BehavioralMetricInterface;
use Amadeco\ElasticSuiteBehavioral\Api\Data\BehavioralMetricInterfaceFactory;
use Amadeco\ElasticSuiteBehavioral\Api\Data\TrackerDataBagInterface;
use Amadeco\ElasticSuiteBehavioral\Api\Data\TrackerDataBagInterfaceFactory;
use Amadeco\ElasticSuiteBehavioral\Model\Config;
use Amadeco\ElasticSuiteBehavioral\Model\ResourceModel\Data\StockStatusResolver;
use Amadeco\ElasticSuiteBehavioral\Model\ResourceModel\Data\TrackerDataCollector;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
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
 * Implements chunked Generators to ensure O(1) memory usage in both PHP and MySQL PDO buffers.
 */
class BehavioralData
{
    /**
     * Database Tables
     */
    public const string TABLE_BEHAVIORAL_ANALYSIS = 'behavioral_analysis';
    public const string TABLE_CATALOG_PRODUCT = 'catalog_product_entity';
    public const string TABLE_CATALOG_PRODUCT_SUPER_LINK = 'catalog_product_super_link';
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
    private const int DB_INSERT_BATCH_SIZE = 1000;
    private const int DB_SELECT_CHUNK_SIZE = 5000; // Prevents PDO buffer overflow
    private const string ATTR_DISCONTINUED = 'discontinued';

    /**
     * SQL Expression Fallbacks
     */
    private const string SQL_EXPR_TRUE = '1';
    private const string SQL_EXPR_FALSE = '0';
    private const string SQL_EXPR_NULL = 'NULL';

    /**
     * @param ResourceConnection $resource
     * @param TimezoneInterface $localeDate
     * @param Config $config
     * @param BehavioralMetricInterfaceFactory $metricFactory
     * @param EavConfig $eavConfig
     * @param LoggerInterface $logger
     * @param StockStatusResolver $stockResolver
     * @param TrackerDataCollector $trackerCollector
     * @param TrackerDataBagInterfaceFactory $trackerDataBagFactory
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TimezoneInterface $localeDate,
        private readonly Config $config,
        private readonly BehavioralMetricInterfaceFactory $metricFactory,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger,
        private readonly StockStatusResolver $stockResolver,
        private readonly TrackerDataCollector $trackerCollector,
        private readonly TrackerDataBagInterfaceFactory $trackerDataBagFactory
    ) {
    }

    /**
     * Retrieve global traffic statistics and raw metric maps from Elasticsearch in one pass.
     * Prevents H-03 Double Aggregation issue.
     *
     * @param int $storeId
     * @param int $periodDays
     * @return TrackerDataBagInterface
     */
    public function getTrackerDataBag(int $storeId, int $periodDays): TrackerDataBagInterface
    {
        $startDate = $this->getDateThreshold($periodDays);

        // 1. Fetch ElasticSearch Engagement Data maps ONCE
        $impressionData = $this->trackerCollector->collect($storeId, $startDate, self::ES_FIELD_IMPRESSION);
        $pdpViewData = $this->trackerCollector->collect($storeId, $startDate, self::ES_FIELD_PDP_VIEW, self::ES_EVENT_VIEW);
        $atcData = $this->trackerCollector->collect($storeId, $startDate, self::ES_FIELD_ADD_TO_CART, self::ES_EVENT_ATC);

        // 2. Hydrate DTO via Factory (Respecting Service Contracts)
        /** @var TrackerDataBagInterface $dataBag */
        $dataBag = $this->trackerDataBagFactory->create();

        $dataBag->setImpressions($impressionData)
                ->setViews($pdpViewData)
                ->setAtcs($atcData)
                ->setGlobalViews((int)array_sum($impressionData))
                ->setGlobalClicks((int)array_sum($pdpViewData))
                ->setMaxAtc(empty($atcData) ? Config::DEFAULT_MAX_ATC : (float)max($atcData));

        return $dataBag;
    }

    /**
     * Orchestrates fetching of all DB metrics and yields fully hydrated DTOs.
     *
     * @param int $storeId
     * @param int $periodDays
     * @param TrackerDataBagInterface $dataBag
     * @return \Generator<BehavioralMetricInterface>
     */
    public function collectAggregatedDataGenerator(
        int $storeId,
        int $periodDays,
        TrackerDataBagInterface $dataBag
    ): \Generator {
        $startDate = $this->getDateThreshold($periodDays);

        // Stream Database Data via Chunked Generator using the injected DTO maps
        yield from $this->getDataForStoreGenerator(
            $storeId,
            $startDate,
            $dataBag->getImpressions(),
            $dataBag->getViews(),
            $dataBag->getAtcs()
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

        foreach (array_chunk($rows, self::DB_INSERT_BATCH_SIZE) as $chunk) {
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
            ->where('store_id = ?', $storeId)
            // Ensure we only count valid finalized sales
            ->where('parent_item_id IS NULL');

        $result = $connection->fetchRow($select);

        return [
            'max_sales'   => (float)($result['max_sales'] ?? Config::DEFAULT_MAX_SALES),
            'max_revenue' => (float)($result['max_revenue'] ?? Config::DEFAULT_MAX_REVENUE),
        ];
    }

    /**
     * Retrieve global traffic statistics.
     * Replaces the hardcoded placeholder with real aggregation.
     *
     * @param int $storeId
     * @param int $periodDays
     * @return array{global_clicks: int, global_views: int, max_atc: float}
     */
    public function getGlobalTrafficStats(int $storeId, int $periodDays): array
    {
        $startDate = $this->getDateThreshold($periodDays);

        // Fetch the raw maps
        $impressionData = $this->trackerCollector->collect($storeId, $startDate, self::ES_FIELD_IMPRESSION);
        $pdpViewData = $this->trackerCollector->collect($storeId, $startDate, self::ES_FIELD_PDP_VIEW, self::ES_EVENT_VIEW);
        $atcData = $this->trackerCollector->collect($storeId, $startDate, self::ES_FIELD_ADD_TO_CART, self::ES_EVENT_ATC);

        return [
            'global_views'  => (int)array_sum($impressionData),
            'global_clicks' => (int)array_sum($pdpViewData),
            'max_atc'       => empty($atcData) ? Config::DEFAULT_MAX_ATC : (float)max($atcData),
        ];
    }

    /**
     * SQL Generator that merges SQL data with Elasticsearch maps.
     * Uses Cursor-Based Pagination (Keyset Pagination) to prevent PDO memory buffer overflow
     * and avoid the exponential performance degradation of LIMIT/OFFSET on massive catalogs.
     *
     * @param int $storeId
     * @param string $startDate
     * @param array<int, int> $impressionData
     * @param array<int, int> $pdpViewData
     * @param array<int, int> $atcData
     * @return \Generator<BehavioralMetricInterface>
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

        // Initialize the cursor
        $lastEntityId = 0;

        do {
            // Clone the base query and apply the Keyset Pagination
            $chunkSelect = clone $select;
            $chunkSelect->where('cpe.entity_id > ?', $lastEntityId);
            $chunkSelect->limit(self::DB_SELECT_CHUNK_SIZE);

            $stmt = $connection->query($chunkSelect);
            $rowCount = 0;

            while ($row = $stmt->fetch()) {
                $rowCount++;
                $pid = (int)$row[BehavioralMetricInterface::PRODUCT_ID];

                // Update the cursor to the current highest Entity ID in this chunk
                $lastEntityId = $pid;

                /** @var \Amadeco\ElasticSuiteBehavioral\Model\BehavioralMetric $metric */
                $metric = $this->metricFactory->create();
                $metric->setProductId($pid)
                    ->setStoreId($storeId)
                    ->setRawSales((float)($row[BehavioralMetricInterface::RAW_SALES] ?? 0.0))
                    ->setRawRevenue((float)($row[BehavioralMetricInterface::RAW_REVENUE] ?? 0.0))
                    ->setRawViews((int)($impressionData[$pid] ?? 0))
                    ->setRawClicks((int)($pdpViewData[$pid] ?? 0))
                    ->setRawAddToCarts((int)($atcData[$pid] ?? 0))
                    ->setRatingSummary((int)($row[BehavioralMetricInterface::RATING_SUMMARY] ?? 0))
                    ->setIsSalable((bool)($row[BehavioralMetricInterface::IS_SALABLE] ?? false))
                    ->setIsDiscontinued((bool)($row[BehavioralMetricInterface::IS_DISCONTINUED] ?? false))
                    ->setCreatedAt($row[BehavioralMetricInterface::CREATED_AT] ?? null)
                    ->setNewsFromDate($row[BehavioralMetricInterface::NEWS_FROM_DATE] ?? null);

                yield $metric;
            }

        // Continue looping as long as the chunk returned the maximum allowed rows
        } while ($rowCount === self::DB_SELECT_CHUNK_SIZE);
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

        if ($this->config->isStockCheckEnabled($storeId)) {
            $this->stockResolver->joinStockStatus($select, $storeId);
        } else {
            $select->columns([BehavioralMetricInterface::IS_SALABLE => new Expression(self::SQL_EXPR_TRUE)]);
        }

        if ($this->config->isDiscontinuedCheckEnabled($storeId)) {
            $this->joinAttribute($select, self::ATTR_DISCONTINUED, $storeId, BehavioralMetricInterface::IS_DISCONTINUED, self::SQL_EXPR_FALSE);
        } else {
            $select->columns([BehavioralMetricInterface::IS_DISCONTINUED => new Expression(self::SQL_EXPR_FALSE)]);
        }

        $this->joinAttribute($select, BehavioralMetricInterface::NEWS_FROM_DATE, $storeId, BehavioralMetricInterface::NEWS_FROM_DATE, self::SQL_EXPR_NULL);

        $salesJoinCond = implode(' AND ', [
            'soi.product_id = cpe.entity_id',
            'soi.parent_item_id IS NULL',
            $connection->quoteInto('soi.store_id = ?', $storeId),
            $connection->quoteInto('soi.created_at >= ?', $startDate)
        ]);

        $select->joinLeft(
            ['soi' => $this->resource->getTableName(self::TABLE_SALES_ORDER_ITEM)],
            $salesJoinCond,
            [
                BehavioralMetricInterface::RAW_SALES   => new Expression('SUM(COALESCE(soi.qty_ordered, 0))'),
                BehavioralMetricInterface::RAW_REVENUE => new Expression('SUM(COALESCE(soi.base_row_total, 0))'),
            ]
        );

        $select->joinLeft(
            ['res' => $this->resource->getTableName(self::TABLE_REVIEW_SUMMARY)],
            $connection->quoteInto('res.entity_pk_value = cpe.entity_id AND res.store_id = ?', $storeId),
            [
                BehavioralMetricInterface::RATING_SUMMARY => new Expression('COALESCE(MAX(res.rating_summary), 0)'),
            ]
        );

        $select->group('cpe.entity_id');
        // Critical for reliable Keyset pagination to ensure rows are ordered deterministically
        $select->order('cpe.entity_id ASC');

        return $select;
    }

    /**
     * DRY Helper to join EAV attribute values safely utilizing native Backend Tables.
     *
     * @param Select $select
     * @param string $code
     * @param int $storeId
     * @param string $alias
     * @param string $default
     * @return void
     */
    private function joinAttribute(Select $select, string $code, int $storeId, string $alias, string $default): void
    {
        $attribute = $this->getAttributeSafely($code);

        if (!$attribute || !$attribute->getId()) {
            $select->columns([$alias => new Expression($default)]);
            return;
        }

        $connection = $select->getAdapter();
        $table = $attribute->getBackendTable();
        $attrId = (int)$attribute->getId();

        $defAlias = $connection->quoteIdentifier("attr_{$alias}_def");
        $storeAlias = $connection->quoteIdentifier("attr_{$alias}_store");

        $defConditions = implode(' AND ', [
            "{$defAlias}.entity_id = cpe.entity_id",
            $connection->quoteInto("{$defAlias}.attribute_id = ?", $attrId),
            "{$defAlias}.store_id = 0",
        ]);

        $select->joinLeft(["attr_{$alias}_def" => $table], $defConditions, []);

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
     * Safely load the EAV attribute utilizing Magento's native EAV cache.
     *
     * @param string $code
     * @return AbstractAttribute|null
     */
    private function getAttributeSafely(string $code): ?AbstractAttribute
    {
        try {
            return $this->eavConfig->getAttribute(Product::ENTITY, $code);
        } catch (\Exception $e) {
            $this->logger->warning("Amadeco Behavioral: Missing attribute '$code'.");
            return null;
        }
    }

    /**
     * Helper to format database-compatible UTC date threshold.
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
