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

namespace Amadeco\ElasticSuiteBehavioral\Model\Event\Processor;

use Smile\ElasticsuiteTracker\Api\EventProcessorInterface;

/**
 * Class VisibleIds
 * * Transforms the comma-separated string of IDs coming from the frontend
 * into an array of integers for proper Elasticsearch indexing.
 */
class VisibleIds implements EventProcessorInterface
{
    /**
     * @param array $eventData
     * @return array
     */
    public function process($eventData)
    {
        if (isset($eventData['page']['product_list']['visible_ids'])) {
            $rawData = $eventData['page']['product_list']['visible_ids'];

            if (is_string($rawData)) {
                $ids = explode(',', $rawData);
            } elseif (is_array($rawData)) {
                $ids = $rawData;
            } else {
                $ids = [];
            }

            if (!empty($ids)) {
                $eventData['page']['product_list']['visible_ids'] = array_values(
                    array_unique(
                        array_map('intval', $ids)
                    )
                );
            }
        }

        return $eventData;
    }
}