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

namespace Amadeco\ElasticSuiteBehavioral\Api\Data;

/**
 * Interface TrackerDataBagInterface
 *
 * Service Contract for the Data Transfer Object (DTO) containing
 * aggregated ElasticSuite tracking metrics. Ensures Elasticsearch
 * is only queried once per execution cycle.
 */
interface TrackerDataBagInterface
{
    public const IMPRESSIONS = 'impressions';
    public const VIEWS = 'views';
    public const ATCS = 'atcs';
    public const GLOBAL_VIEWS = 'global_views';
    public const GLOBAL_CLICKS = 'global_clicks';
    public const MAX_ATC = 'max_atc';

    /**
     * Get Impressions Map (ProductID => Count)
     *
     * @return array<int, int>
     */
    public function getImpressions(): array;

    /**
     * Set Impressions Map
     *
     * @param array<int, int> $impressions
     * @return $this
     */
    public function setImpressions(array $impressions): self;

    /**
     * Get Views Map (ProductID => Count)
     *
     * @return array<int, int>
     */
    public function getViews(): array;

    /**
     * Set Views Map
     *
     * @param array<int, int> $views
     * @return $this
     */
    public function setViews(array $views): self;

    /**
     * Get Add-to-Carts Map (ProductID => Count)
     *
     * @return array<int, int>
     */
    public function getAtcs(): array;

    /**
     * Set Add-to-Carts Map
     *
     * @param array<int, int> $atcs
     * @return $this
     */
    public function setAtcs(array $atcs): self;

    /**
     * Get Total Global Views
     *
     * @return int
     */
    public function getGlobalViews(): int;

    /**
     * Set Total Global Views
     *
     * @param int $globalViews
     * @return $this
     */
    public function setGlobalViews(int $globalViews): self;

    /**
     * Get Total Global Clicks
     *
     * @return int
     */
    public function getGlobalClicks(): int;

    /**
     * Set Total Global Clicks
     *
     * @param int $globalClicks
     * @return $this
     */
    public function setGlobalClicks(int $globalClicks): self;

    /**
     * Get Maximum Add-to-Cart Count
     *
     * @return float
     */
    public function getMaxAtc(): float;

    /**
     * Set Maximum Add-to-Cart Count
     *
     * @param float $maxAtc
     * @return $this
     */
    public function setMaxAtc(float $maxAtc): self;
}
