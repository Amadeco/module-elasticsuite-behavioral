<?php
/**
 * Amadeco_ElasticSuiteBehavioral
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @author    Amadeco Core Team
 */

declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Service;

use Amadeco\ElasticSuiteBehavioral\Api\BehavioralServiceInterface;
use Amadeco\ElasticSuiteBehavioral\Api\Data\BehavioralMetricInterface;
use Amadeco\ElasticSuiteBehavioral\Model\Calculator\ScoreCalculator;
use Amadeco\ElasticSuiteBehavioral\Model\Config;
use Amadeco\ElasticSuiteBehavioral\Model\ResourceModel\BehavioralData;
use Magento\CatalogSearch\Model\Indexer\Fulltext as FulltextIndexer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the behavioral analysis pipeline.
 *
 * Logic:
 * 1. Iterates through all stores.
 * 2. Fetches Global Stats (Views/Clicks) ONCE per store to establish a stable baseline.
 * 3. Streams product data using Generators to maintain O(1) memory complexity.
 * 4. Batches updates to DB to optimize I/O operations.
 */
final class BehavioralService implements BehavioralServiceInterface
{
    /**
     * Number of records processed and saved in a single database transaction.
     */
    private const int BATCH_SIZE = 1000;

    /**
     * Default CTR fallback to prevent division by zero in zero-traffic stores.
     */
    private const float DEFAULT_FALLBACK_CTR = 0.02;

    /**
     * @param BehavioralData $resourceModel
     * @param ScoreCalculator $calculator
     * @param Config $config
     * @param IndexerInterfaceFactory $indexerFactory
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly BehavioralData $resourceModel,
        private readonly ScoreCalculator $calculator,
        private readonly Config $config,
        private readonly IndexerInterfaceFactory $indexerFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute the behavioral analysis process for all enabled stores.
     *
     * @throws LocalizedException
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $period = $this->config->getAnalysisPeriod();
        $this->logger->info('Amadeco Behavioral Analysis: Starting execution.', ['period_days' => $period]);

        $totalProcessed = 0;

        // Note: getStores() implicitly excludes the Admin store (ID 0)
        $stores = $this->storeManager->getStores();

        /** @var StoreInterface $store */
        foreach ($stores as $store) {
            $storeId = (int)$store->getId();

            // Skip disabled config
            if (!$this->config->isEnabled($storeId)) {
                continue;
            }

            $processedInStore = $this->processStore($storeId, $period);
            $totalProcessed += $processedInStore;

            $this->logger->info("Completed analysis for Store ID {$storeId}.", ['count' => $processedInStore]);
        }

        $this->logger->info('Amadeco Behavioral Analysis: Completed.', ['total_processed' => $totalProcessed]);

        $this->invalidateIndex();
    }

    /**
     * Process analysis for a single store scope.
     *
     * @param int $storeId
     * @param int $period
     * @return int Number of products successfully processed.
     */
    private function processStore(int $storeId, int $period): int
    {
        // 1. Fetch Global Baselines (Ceilings & Averages)
        // These are static for the duration of this store's processing to prevent "Drifting Average".
        $storeCeilings = $this->resourceModel->getStoreCeilings($storeId, $period);
        $trafficStats  = $this->resourceModel->getGlobalTrafficStats($storeId, $period);

        // Calculate a stable Global Average CTR for this store
        // Prevent division by zero if store has no traffic
        $globalAverageCtr = $trafficStats['global_views'] > 0
            ? $trafficStats['global_clicks'] / $trafficStats['global_views']
            : self::DEFAULT_FALLBACK_CTR;

        // Merge stats for the Calculator
        $stats = array_merge($storeCeilings, $trafficStats, ['global_average_ctr' => $globalAverageCtr]);

        $batch = [];
        $count = 0;

        // 2. Stream Data & Process Batches via Generator
        foreach ($this->resourceModel->collectAggregatedDataGenerator($storeId, $period) as $metric) {
            $batch[] = $metric;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->processMetricsBatch($batch, $stats);
                $count += count($batch);
                $batch = [];
            }
        }

        // Process any remaining records in the final incomplete batch
        if (!empty($batch)) {
            $this->processMetricsBatch($batch, $stats);
            $count += count($batch);
        }

        return $count;
    }

    /**
     * Calculate and save a batch of product scores.
     *
     * @param BehavioralMetricInterface[] $metrics Array of hydrated DTOs
     * @param array<string, float|int> $stats Contains max_sales, max_revenue, global_average_ctr
     * @return void
     */
    private function processMetricsBatch(array $metrics, array $stats): void
    {
        $rows = [];
        foreach ($metrics as $metric) {
            $rows[] = $this->calculator->calculateRow($metric, $stats);
        }

        $this->resourceModel->saveScoresBatch($rows);
    }

    /**
     * Invalidate the ElasticSuite Fulltext index so new scores are pushed to Elasticsearch.
     * * Handles exceptions gracefully to avoid failing the entire cron just because
     * indexer states are locked or misconfigured.
     *
     * @return void
     */
    private function invalidateIndex(): void
    {
        try {
            $indexer = $this->indexerFactory->create()->load(FulltextIndexer::INDEXER_ID);

            if (!$indexer->isScheduled()) {
                $indexer->invalidate();
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Amadeco Behavioral Analysis: Indexer invalidation failed.',
                ['error' => $e->getMessage()]
            );
        }
    }
}
