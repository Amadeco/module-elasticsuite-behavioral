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
 * Interface BehavioralMetricInterface
 *
 * Contract for the Behavioral Metric Data Transfer Object.
 * Represents the aggregated scoring data for a single product.
 */
interface BehavioralMetricInterface
{
    /**
     * Data array keys.
     */
    public const PRODUCT_ID = 'product_id';
    public const STORE_ID = 'store_id';
    public const RAW_VIEWS = 'raw_views';
    public const RAW_CLICKS = 'raw_clicks';
    public const RAW_ADD_TO_CARTS = 'raw_add_to_carts';
    public const RAW_SALES = 'raw_sales';
    public const RAW_REVENUE = 'raw_revenue';
    public const RATING_SCORE = 'rating_score';
    public const GLOBAL_SCORE = 'global_score';
    public const IS_NEW_BOOST = 'is_new_boost';
    public const IS_SALABLE = 'is_salable';
    public const IS_DISCONTINUED = 'is_discontinued';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * @var string Field for standard Magento reviews.
     */
    public const RATING_SUMMARY = 'rating_summary';

    /**
     * @var string Field for standard Magento "Set Product as New From" date.
     */
    public const NEWS_FROM_DATE = 'news_from_date';

    /**
     * Get Product ID
     *
     * @return int
     */
    public function getProductId(): int;

    /**
     * Set Product ID
     *
     * @param int $productId
     * @return $this
     */
    public function setProductId(int $productId): self;

    /**
     * Get Store ID
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Set Store ID
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * Get Raw Views (Tracker)
     *
     * @return int
     */
    public function getRawViews(): int;

    /**
     * Set Raw Views
     *
     * @param int $rawViews
     * @return $this
     */
    public function setRawViews(int $rawViews): self;

    /**
     * Get Raw Clicks (Tracker)
     *
     * @return int
     */
    public function getRawClicks(): int;

    /**
     * Set Raw Clicks
     *
     * @param int $rawClicks
     * @return $this
     */
    public function setRawClicks(int $rawClicks): self;

    /**
     * Get Raw Add to Cart events
     * @return int
     */
    public function getRawAddToCarts(): int;

    /**
     * Set Raw Add to Cart events
     * @param int $qty
     * @return $this
     */
    public function setRawAddToCarts(int $qty): self;

    /**
     * Get Raw Sales Quantity
     *
     * @return float
     */
    public function getRawSales(): float;

    /**
     * Set Raw Sales Quantity
     *
     * @param float $rawSales
     * @return $this
     */
    public function setRawSales(float $rawSales): self;

    /**
     * Get Raw Revenue
     *
     * @return float
     */
    public function getRawRevenue(): float;

    /**
     * Set Raw Revenue
     *
     * @param float $rawRevenue
     * @return $this
     */
    public function setRawRevenue(float $rawRevenue): self;

    /**
     * Get Rating Summary (0-100)
     *
     * @return int
     */
    public function getRatingSummary(): int;

    /**
     * Set Rating Summary
     *
     * @param int $ratingSummary
     * @return $this
     */
    public function setRatingSummary(int $ratingSummary): self;

    /**
     * Get Rating Score (Weighted calculation)
     *
     * @return int
     */
    public function getRatingScore(): int;

    /**
     * Set Rating Score
     *
     * @param int $ratingScore
     * @return $this
     */
    public function setRatingScore(int $ratingScore): self;

    /**
     * Get Global Behavioral Score
     *
     * @return int
     */
    public function getGlobalScore(): int;

    /**
     * Set Global Behavioral Score
     *
     * @param int $globalScore
     * @return $this
     */
    public function setGlobalScore(int $globalScore): self;

    /**
     * Get "New Product" Boost Value
     *
     * @return int
     */
    public function getIsNewBoost(): int;

    /**
     * Set "New Product" Boost Value
     *
     * @param int $isNewBoost
     * @return $this
     */
    public function setIsNewBoost(int $isNewBoost): self;

    /**
     * Check if product is technically salable (In Stock).
     * @return bool
     */
    public function isSalable(): bool;

    /**
     * Set salable status.
     * @param bool $isSalable
     * @return $this
     */
    public function setIsSalable(bool $isSalable): self;

    /**
     * Check if product is marked as discontinued.
     * @return bool
     */
    public function isDiscontinued(): bool;

    /**
     * Set discontinued status.
     * @param bool $isDiscontinued
     * @return $this
     */
    public function setIsDiscontinued(bool $isDiscontinued): self;

    /**
     * Get Created At Timestamp
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Set Created At Timestamp
     *
     * @param string|null $createdAt
     * @return $this
     */
    public function setCreatedAt(?string $createdAt): self;

    /**
     * Get the 'Set Product as New From' date.
     * Used to detect freshness or back-in-stock merchandising pushes.
     *
     * @return string|null
     */
    public function getNewsFromDate(): ?string;

    /**
     * Set the 'Set Product as New From' date.
     *
     * @param string|null $newsFromDate
     * @return $this
     */
    public function setNewsFromDate(?string $newsFromDate): self;

    /**
     * Get Updated At Timestamp
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * Set Updated At Timestamp
     *
     * @param string|null $updatedAt
     * @return $this
     */
    public function setUpdatedAt(?string $updatedAt): self;
}