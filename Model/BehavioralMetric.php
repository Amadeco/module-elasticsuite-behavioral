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

namespace Amadeco\ElasticSuiteBehavioral\Model;

use Amadeco\ElasticSuiteBehavioral\Api\Data\BehavioralMetricInterface;
use Magento\Framework\Api\AbstractSimpleObject;

/**
 * Class BehavioralMetric
 *
 * Data Transfer Object (DTO) for behavioral metrics.
 * Implements getters and setters for all indexable behavioral fields.
 */
class BehavioralMetric extends AbstractSimpleObject implements BehavioralMetricInterface
{
    /**
     * @inheritdoc
     */
    public function getProductId(): int
    {
        return (int) $this->_get(self::PRODUCT_ID);
    }

    /**
     * @inheritdoc
     */
    public function setProductId(int $productId): self
    {
        return $this->setData(self::PRODUCT_ID, $productId);
    }

    /**
     * @inheritdoc
     */
    public function getStoreId(): int
    {
        return (int) $this->_get(self::STORE_ID);
    }

    /**
     * @inheritdoc
     */
    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getRawViews(): int
    {
        return (int) $this->_get(self::RAW_VIEWS);
    }

    /**
     * @inheritdoc
     */
    public function setRawViews(int $rawViews): self
    {
        return $this->setData(self::RAW_VIEWS, $rawViews);
    }

    /**
     * @inheritdoc
     */
    public function getRawClicks(): int
    {
        return (int) $this->_get(self::RAW_CLICKS);
    }

    /**
     * @inheritdoc
     */
    public function setRawClicks(int $rawClicks): self
    {
        return $this->setData(self::RAW_CLICKS, $rawClicks);
    }

    /**
     * @inheritdoc
     */
    public function getRawAddToCarts(): int
    {
        return (int) $this->_get(self::RAW_ADD_TO_CARTS);
    }

    /**
     * @inheritdoc
     */
    public function setRawAddToCarts(int $qty): self
    {
        return $this->setData(self::RAW_ADD_TO_CARTS, $qty);
    }

    /**
     * @inheritdoc
     */
    public function getRawSales(): float
    {
        return (float) $this->_get(self::RAW_SALES);
    }

    /**
     * @inheritdoc
     */
    public function setRawSales(float $rawSales): self
    {
        return $this->setData(self::RAW_SALES, $rawSales);
    }

    /**
     * @inheritdoc
     */
    public function getRawRevenue(): float
    {
        return (float) $this->_get(self::RAW_REVENUE);
    }

    /**
     * @inheritdoc
     */
    public function setRawRevenue(float $rawRevenue): self
    {
        return $this->setData(self::RAW_REVENUE, $rawRevenue);
    }

    /**
     * @inheritdoc
     */
    public function getRatingSummary(): int
    {
        return (int) $this->_get(self::RATING_SUMMARY);
    }

    /**
     * @inheritdoc
     */
    public function setRatingSummary(int $ratingSummary): self
    {
        return $this->setData(self::RATING_SUMMARY, $ratingSummary);
    }

    /**
     * @inheritdoc
     */
    public function getRatingScore(): int
    {
        return (int) $this->_get(self::RATING_SCORE);
    }

    /**
     * @inheritdoc
     */
    public function setRatingScore(int $ratingScore): self
    {
        return $this->setData(self::RATING_SCORE, $ratingScore);
    }

    /**
     * @inheritdoc
     */
    public function getGlobalScore(): int
    {
        return (int) $this->_get(self::GLOBAL_SCORE);
    }

    /**
     * @inheritdoc
     */
    public function setGlobalScore(int $globalScore): self
    {
        return $this->setData(self::GLOBAL_SCORE, $globalScore);
    }

    /**
     * @inheritdoc
     */
    public function getIsNewBoost(): int
    {
        return (int) $this->_get(self::IS_NEW_BOOST);
    }

    /**
     * @inheritdoc
     */
    public function setIsNewBoost(int $isNewBoost): self
    {
        return $this->setData(self::IS_NEW_BOOST, $isNewBoost);
    }

    /**
     * @inheritdoc
     */
    public function isSalable(): bool
    {
        return (bool) $this->_get(self::IS_SALABLE);
    }

    /**
     * @inheritdoc
     */
    public function setIsSalable(bool $isSalable): self
    {
        return $this->setData(self::IS_SALABLE, $isSalable);
    }

    /**
     * @inheritdoc
     */
    public function isDiscontinued(): bool
    {
        return (bool) $this->_get(self::IS_DISCONTINUED);
    }

    /**
     * @inheritdoc
     */
    public function setIsDiscontinued(bool $isDiscontinued): self
    {
        return $this->setData(self::IS_DISCONTINUED, $isDiscontinued);
    }

    /**
     * @inheritdoc
     */
    public function getCreatedAt(): ?string
    {
        $value = $this->_get(self::CREATED_AT);
        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritdoc
     */
    public function setCreatedAt(?string $createdAt): self
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * @inheritdoc
     */
    public function getNewsFromDate(): ?string
    {
        $value = $this->_get(self::NEWS_FROM_DATE);
        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritdoc
     */
    public function setNewsFromDate(?string $newsFromDate): self
    {
        return $this->setData(self::NEWS_FROM_DATE, $newsFromDate);
    }

    /**
     * @inheritdoc
     */
    public function getUpdatedAt(): ?string
    {
        $value = $this->_get(self::UPDATED_AT);
        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritdoc
     */
    public function setUpdatedAt(?string $updatedAt): self
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}