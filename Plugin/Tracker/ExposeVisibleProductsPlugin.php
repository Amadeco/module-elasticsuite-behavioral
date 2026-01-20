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

namespace Amadeco\ElasticSuiteBehavioral\Plugin\Tracker;

use Amadeco\ElasticSuiteBehavioral\Model\Collector\VisibleProductsProvider;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smile\ElasticsuiteTracker\Block\Variables\Page\Catalog as SubjectBlock;

/**
 * Class ExposeVisibleProductsPlugin
 *
 * Injects visible product IDs into the tracker.
 * Uses Magento Framework Serializer instead of native json_encode for standards compliance.
 */
class ExposeVisibleProductsPlugin
{
    /**
     * @param VisibleProductsProvider $provider     Registry containing visible IDs.
     * @param JsonSerializer          $jsonSerializer Magento framework JSON serializer.
     */
    public function __construct(
        private readonly VisibleProductsProvider $provider,
        private readonly JsonSerializer $jsonSerializer
    ) {}

    /**
     * Inject visible IDs into the frontend tracker variables.
     *
     * Data is serialized to a string to remain compatible with ElasticSuite's
     * template sanitization filters (StripTags).
     *
     * @param SubjectBlock $subject The tracker block instance.
     * @param array        $result  The original variables array.
     * @return array The modified variables array.
     */
    public function afterGetVariables(SubjectBlock $subject, array $result): array
    {
        $visibleIds = $this->provider->getVisibleProductIds();

        if (!empty($visibleIds)) {
            $result['product_list.visible_ids'] = implode(',', array_values($visibleIds));
            $result['product_list.visible_count'] = (string)count($visibleIds);
        }

        return $result;
    }
}