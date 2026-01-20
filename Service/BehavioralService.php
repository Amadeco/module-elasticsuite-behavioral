<?php
declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Service;

use Amadeco\ElasticSuiteBehavioral\Api\BehavioralServiceInterface;
use Amadeco\ElasticSuiteBehavioral\Model\Calculator\ScoreCalculator;
use Amadeco\ElasticSuiteBehavioral\Model\Config;
use Amadeco\ElasticSuiteBehavioral\Model\ResourceModel\BehavioralData;
use Magento\CatalogSearch\Model\Indexer\Fulltext as FulltextIndexer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates behavioral analysis pipeline.
 *
 * Logic:
 * 1. Iterates through all stores.
 * 2. Fetches Global Stats (Views/Clicks) ONCE per store to establish a stable baseline.
 * 3. Streams product data using Generators.
 * 4. Batches updates to DB.
 */
final class BehavioralService implements BehavioralServiceInterface
{
    private const int BATCH_SIZE = 1000;

    public function __construct(
        private readonly BehavioralData $resourceModel,
        private readonly ScoreCalculator $calculator,
        private readonly Config $config,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $period = $this->config->getAnalysisPeriod();
        $this->logger->info('Amadeco Behavioral Analysis: Starting execution.', ['period_days' => $period]);

        $totalProcessed = 0;
        $stores = $this->storeManager->getStores();

        foreach ($stores as $store) {
            $storeId = (int)$store->getId();

            // Skip admin store or disabled config
            if ($storeId === 0 || !$this->config->isEnabled($storeId)) {
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
     * @return int Number of products processed.
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
            : 0.02; // Default fallback to 2%


        // Merge stats for the Calculator
        $stats = $storeCeilings + $trafficStats + ['global_average_ctr' => $globalAverageCtr];

        $batch = [];
        $count = 0;

        // 2. Stream Data & Process Batches
        foreach ($this->resourceModel->collectAggregatedDataGenerator($storeId, $period) as $metric) {
            $batch[] = $metric;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->processMetricsBatch($batch, $stats);
                $count += count($batch);
                $batch = [];
            }
        }

        // Process remaining
        if (!empty($batch)) {
            $this->processMetricsBatch($batch, $stats);
            $count += count($batch);
        }

        return $count;
    }

    /**
     * Calculate and Save a batch of scores.
     *
     * @param array $metrics
     * @param array $stats Contains max_sales, max_revenue, global_average_ctr
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
     * @return void
     */
    private function invalidateIndex(): void
    {
        try {
            $indexer = $this->indexerRegistry->get(FulltextIndexer::INDEXER_ID);
            if (!$indexer->isScheduled()) {
                $indexer->invalidate();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Indexer invalidation failed.', ['error' => $e->getMessage()]);
        }
    }
}