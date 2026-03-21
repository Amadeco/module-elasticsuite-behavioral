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

use Amadeco\ElasticSuiteBehavioral\Api\Data\TrackerDataBagInterface;
use Magento\Framework\Api\AbstractSimpleObject;

/**
 * Class TrackerDataBag
 *
 * Implementation of the TrackerDataBagInterface.
 * Extends AbstractSimpleObject to integrate cleanly with Magento 2's DTO framework.
 */
class TrackerDataBag extends AbstractSimpleObject implements TrackerDataBagInterface
{
    /**
     * @inheritdoc
     */
    public function getImpressions(): array
    {
        return (array) $this->_get(self::IMPRESSIONS);
    }

    /**
     * @inheritdoc
     */
    public function setImpressions(array $impressions): self
    {
        return $this->setData(self::IMPRESSIONS, $impressions);
    }

    /**
     * @inheritdoc
     */
    public function getViews(): array
    {
        return (array) $this->_get(self::VIEWS);
    }

    /**
     * @inheritdoc
     */
    public function setViews(array $views): self
    {
        return $this->setData(self::VIEWS, $views);
    }

    /**
     * @inheritdoc
     */
    public function getAtcs(): array
    {
        return (array) $this->_get(self::ATCS);
    }

    /**
     * @inheritdoc
     */
    public function setAtcs(array $atcs): self
    {
        return $this->setData(self::ATCS, $atcs);
    }

    /**
     * @inheritdoc
     */
    public function getGlobalViews(): int
    {
        return (int) $this->_get(self::GLOBAL_VIEWS);
    }

    /**
     * @inheritdoc
     */
    public function setGlobalViews(int $globalViews): self
    {
        return $this->setData(self::GLOBAL_VIEWS, $globalViews);
    }

    /**
     * @inheritdoc
     */
    public function getGlobalClicks(): int
    {
        return (int) $this->_get(self::GLOBAL_CLICKS);
    }

    /**
     * @inheritdoc
     */
    public function setGlobalClicks(int $globalClicks): self
    {
        return $this->setData(self::GLOBAL_CLICKS, $globalClicks);
    }

    /**
     * @inheritdoc
     */
    public function getMaxAtc(): float
    {
        return (float) $this->_get(self::MAX_ATC);
    }

    /**
     * @inheritdoc
     */
    public function setMaxAtc(float $maxAtc): self
    {
        return $this->setData(self::MAX_ATC, $maxAtc);
    }
}
