<?php

declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Model\Indexer\Fulltext\Datasource;

use Amadeco\ElasticSuiteBehavioral\Api\Data\BehavioralMetricInterface;
use Amadeco\ElasticSuiteBehavioral\Model\ResourceModel\BehavioralData;
use Amadeco\ElasticSuiteBehavioral\Setup\Patch\Data\CreateProductAttributes;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Smile\ElasticsuiteCore\Api\Index\DatasourceInterface;

/**
 * Datasource responsible for injecting behavioral scores into the ElasticSuite search index.
 *
 * This class enriches the product documents with behavioral metrics defined
 * in the CreateProductAttributes setup patch.
 */
class BehavioralDataSource implements DatasourceInterface
{
    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Add behavioral data to the index.
     *
     * @param int $storeId The store view ID being indexed.
     * @param array<int|string, array> $indexData The current data of the documents being indexed.
     * Keys are Entity IDs (Product IDs).
     * @return array<int|string, array> The enriched documents data.
     */
    public function addData($storeId, array $indexData): array
    {
        if (empty($indexData)) {
            return $indexData;
        }

        // Extract Product IDs from keys (ElasticSuite standard: keys are entity IDs)
        $productIds = array_map('intval', array_keys($indexData));

        // Fetch scores (Direct + Aggregated fallback)
        $scores = $this->getBehavioralScores((int)$storeId, $productIds);

        // Enrich the documents by reference to preserve existing data (name, sku, etc.)
        foreach ($indexData as $productId => &$documentData) {
            $scoreData = $scores[$productId] ?? null;

            // Map DB columns to Product Attributes defined in Setup Patch
            $documentData[CreateProductAttributes::PRODUCT_ATTRIBUTE_BEHAVIORAL_SCORE] =
                (int)($scoreData[BehavioralMetricInterface::GLOBAL_SCORE] ?? 0);

            $documentData[CreateProductAttributes::PRODUCT_ATTRIBUTE_IS_NEW_BOOST] =
                (int)($scoreData[BehavioralMetricInterface::IS_NEW_BOOST] ?? 0);
        }

        return $indexData;
    }

    /**
     * Orchestrates the retrieval of scores for a list of products.
     *
     * Strategies:
     * 1. Direct Fetch: Look for the product ID in the analysis table.
     * 2. Fallback: If not found (e.g., Configurable Parent), aggregate scores from children.
     *
     * @param int $storeId
     * @param int[] $productIds
     * @return array<int, array> Key is Product ID.
     */
    private function getBehavioralScores(int $storeId, array $productIds): array
    {
        // 1. Try to fetch scores directly
        $directScores = $this->fetchDirectScores($storeId, $productIds);

        // 2. Identify missing IDs (Parent products likely missing from direct analysis)
        $foundIds = array_keys($directScores);
        $missingIds = array_diff($productIds, $foundIds);

        if (empty($missingIds)) {
            return $directScores;
        }

        // 3. Fetch aggregated scores for parents
        $childScores = $this->fetchAggregatedChildScores($storeId, $missingIds);

        // 4. Merge results (Direct scores take precedence)
        return $directScores + $childScores;
    }

    /**
     * Fetches scores for products present in the analysis table.
     *
     * @param int $storeId
     * @param int[] $productIds
     * @return array<int, array>
     */
    private function fetchDirectScores(int $storeId, array $productIds): array
    {
        $connection = $this->getConnection();
        $tableName = $this->resourceConnection->getTableName(BehavioralData::TABLE_BEHAVIORAL_ANALYSIS);

        $select = $connection->select()
            ->from(
                $tableName,
                [
                    BehavioralMetricInterface::PRODUCT_ID,
                    BehavioralMetricInterface::GLOBAL_SCORE,
                    BehavioralMetricInterface::IS_NEW_BOOST
                ]
            )
            ->where(BehavioralMetricInterface::STORE_ID . ' = ?', $storeId)
            ->where(BehavioralMetricInterface::PRODUCT_ID . ' IN (?)', $productIds);

        return $connection->fetchAssoc($select);
    }

    /**
     * Aggregates scores from child products (variations) to the parent.
     * Uses MAX() to assign the score of the best-performing variation to the parent.
     *
     * @param int $storeId
     * @param int[] $parentIds
     * @return array<int, array>
     */
    private function fetchAggregatedChildScores(int $storeId, array $parentIds): array
    {
        $connection = $this->getConnection();
        $tableAnalysis = $this->resourceConnection->getTableName(BehavioralData::TABLE_BEHAVIORAL_ANALYSIS);
        $tableRelation = $this->resourceConnection->getTableName(BehavioralData::TABLE_CATALOG_PRODUCT_SUPER_LINK);

        $select = $connection->select()
            ->from(
                ['rel' => $tableRelation],
                [
                    // Alias as product_id so fetchAssoc indexes correctly by Parent ID
                    BehavioralMetricInterface::PRODUCT_ID => 'rel.parent_id',
                    BehavioralMetricInterface::GLOBAL_SCORE => new Expression('MAX(beh.' . BehavioralMetricInterface::GLOBAL_SCORE . ')'),
                    BehavioralMetricInterface::IS_NEW_BOOST => new Expression('MAX(beh.' . BehavioralMetricInterface::IS_NEW_BOOST . ')')
                ]
            )
            ->joinInner(
                ['beh' => $tableAnalysis],
                'rel.' . BehavioralMetricInterface::PRODUCT_ID . ' = beh.' . BehavioralMetricInterface::PRODUCT_ID,
                []
            )
            ->where('beh.' . BehavioralMetricInterface::STORE_ID . ' = ?', $storeId)
            ->where('rel.parent_id IN (?)', $parentIds)
            ->group('rel.parent_id');

        return $connection->fetchAssoc($select);
    }

    /**
     * Wrapper to get database adapter.
     *
     * @return AdapterInterface
     */
    private function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}