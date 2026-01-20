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

use Amadeco\ElasticSuiteBehavioral\Model\Config;
use Psr\Log\LoggerInterface;
use Smile\ElasticsuiteCore\Client\Client;
use Smile\ElasticsuiteCore\Index\IndexSettings;

/**
 * Service to aggregate raw tracking data from Elasticsearch.
 *
 * This collector uses Composite Aggregations to efficiently page through large
 * datasets of tracking events (impressions, views, add-to-carts) without
 * memory overflow, returning a map of Product IDs to Event Counts.
 */
readonly class TrackerDataCollector
{
    /**
     * ElasticSuite Tracking Index Identifier.
     */
    private const string TRACKING_INDEX_IDENTIFIER = 'tracking_log_event';

    /**
     * Page size for Elasticsearch Composite Aggregation.
     */
    private const int ES_AGG_PAGE_SIZE = 5000;

    /**
     * @param Client $client
     * @param IndexSettings $indexSettings
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private Client $client,
        private IndexSettings $indexSettings,
        private Config $config,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Fetch aggregated event counts for a specific event type/field.
     *
     * @param int $storeId The Store View ID to query.
     * @param string $startDate MySQL formatted datetime (Y-m-d H:i:s).
     * @param string $aggField Field to aggregate on (e.g., 'page.product.id').
     * @param string|null $eventType Optional event type filter (e.g., 'catalog_product_view').
     * @return array<int, int> Map of ProductID => Count.
     */
    public function collect(
        int $storeId,
        string $startDate,
        string $aggField,
        ?string $eventType = null
    ): array {
        if (!$this->config->isTrackerEnabled($storeId)) {
            return [];
        }

        $map = [];
        $afterKey = null;

        try {
            $indexAlias = $this->indexSettings->getIndexAliasFromIdentifier(
                self::TRACKING_INDEX_IDENTIFIER,
                $storeId
            );

            do {
                $query = $this->buildCompositeQuery(
                    $indexAlias,
                    $startDate,
                    $aggField,
                    $eventType,
                    $afterKey
                );

                $response = $this->client->search($query);
                $buckets = $response['aggregations']['product_buckets']['buckets'] ?? [];

                foreach ($buckets as $bucket) {
                    // Extract Product ID from the composite key
                    $productId = (int)($bucket['key'][$aggField] ?? 0);
                    if ($productId > 0) {
                        $map[$productId] = (int)$bucket['doc_count'];
                    }
                }

                // Prepare the key for the next page of results
                $afterKey = $response['aggregations']['product_buckets']['after_key'] ?? null;
            } while ($afterKey !== null);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    "Amadeco Behavioral ES Error [Store: %d]: %s",
                    $storeId,
                    $e->getMessage()
                )
            );
            return [];
        }

        return $map;
    }

    /**
     * Constructs the Elasticsearch query body.
     *
     * @param string $index
     * @param string $startDate
     * @param string $aggField
     * @param string|null $eventType
     * @param array|null $afterKey
     * @return array
     */
    private function buildCompositeQuery(
        string $index,
        string $startDate,
        string $aggField,
        ?string $eventType,
        ?array $afterKey
    ): array {
        $query = [
            'index' => $index,
            'body' => [
                'size' => 0, // We only need aggregation data, not documents
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['range' => ['date' => ['gte' => $startDate]]],
                            ['exists' => ['field' => $aggField]],
                        ],
                    ],
                ],
                'aggs' => [
                    'product_buckets' => [
                        'composite' => [
                            'size' => self::ES_AGG_PAGE_SIZE,
                            'sources' => [
                                [$aggField => ['terms' => ['field' => $aggField]]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Apply Event Type Filter if provided (e.g., separating Views from Clicks)
        if ($eventType) {
            $query['body']['query']['bool']['filter'][] = [
                'term' => ['page.type.identifier' => $eventType],
            ];
        }

        // Apply Pagination Cursor
        if ($afterKey) {
            $query['body']['aggs']['product_buckets']['composite']['after'] = $afterKey;
        }

        return $query;
    }
}