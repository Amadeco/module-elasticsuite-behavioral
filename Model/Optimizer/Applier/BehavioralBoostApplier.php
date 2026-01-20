<?php

/**
 * Amadeco_ElasticSuiteBehavioral
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @author    Amadeco Core Team
 */

declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Model\Optimizer\Applier;

use Amadeco\ElasticSuiteBehavioral\Model\Config;
use Smile\ElasticsuiteCatalogOptimizer\Api\Data\OptimizerInterface;
use Smile\ElasticsuiteCatalogOptimizer\Model\Optimizer\ApplierInterface;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;

/**
 * Class BehavioralBoostApplier
 *
 * Applies the behavioral function score to the query context.
 * Implements robust error handling for unmapped fields and logging for debug purposes.
 */
class BehavioralBoostApplier implements ApplierInterface
{
    /**
     * Safety bounds for weights to prevent arithmetic explosions in scoring.
     */
    private const float MIN_WEIGHT = 0.0;
    private const float MAX_WEIGHT = 20.0;

    /**
     * Default fallback weights if configuration is missing.
     */
    private const float DEFAULT_BASE = 2.0;
    private const float DEFAULT_FRESH = 1.5;

    /**
     * @param Config $config
     * @param QueryFactory $queryFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly QueryFactory $queryFactory
    ) {
    }

    /**
     * Build the function score query with behavioral logic.
     *
     * @param ContainerConfigurationInterface $containerConfiguration
     * @param OptimizerInterface $optimizer
     * @return array
     */
    public function getFunction(
        ContainerConfigurationInterface $containerConfiguration,
        OptimizerInterface $optimizer
    ): array {
        $config = $optimizer->getConfig();

        // 1. Retrieve and Sanitize Inputs (DRY: centralized sanitization)
        $baseWeight = $this->sanitizeWeight($config['base_weight'] ?? self::DEFAULT_BASE);
        $freshWeight = $this->sanitizeWeight($config['freshness_weight'] ?? self::DEFAULT_FRESH);

        /**
        // 2. Log execution details (Observability)
        $this->logger->info('Amadeco_ElasticSuiteBehavioral: Applying Optimizer', [
            'optimizer_id'   => $optimizer->getId(),
            'optimizer_name' => $optimizer->getName(),
            'weights'        => [
                'base'  => $baseWeight,
                'fresh' => $freshWeight,
            ]
        ]);
         */

        // 3. Build Query Name for Debugging
        $queryName = sprintf(
            'Behavioral Optimizer [%s] (Base: %.1f, Fresh: %.1f)',
            $optimizer->getName(),
            $baseWeight,
            $freshWeight
        );

        $query = $optimizer->getRuleCondition()->getSearchQuery();
        $query->setName(
            ($query->getName() !== '') ? "$queryName => {$query->getName()}" : $queryName
        );

        // 4. Construct Script Score
        // FIX: The Painless script now checks `containsKey` before access.
        // This prevents the "No field found" exception if the mapping is missing.
        return [
            'filter' => $query,
            'script_score' => [
                'script' => [
                    'source' => "
                        double score = 0;
                        if (doc.containsKey('behavioral_score') && doc['behavioral_score'].size() > 0) {
                            score = doc['behavioral_score'].value;
                        }

                        double fresh = 0;
                        if (doc.containsKey('is_new_boost') && doc['is_new_boost'].size() > 0) {
                            fresh = doc['is_new_boost'].value;
                        }

                        return (Math.log1p(score) * params.base) + (Math.log1p(fresh) * params.fresh);
                    ",
                    'params' => [
                        'base'  => $baseWeight,
                        'fresh' => $freshWeight,
                    ],
                ],
            ],
        ];
    }

    /**
     * Ensure weights are within safe execution bounds.
     *
     * @param mixed $value
     * @return float
     */
    private function sanitizeWeight(mixed $value): float
    {
        $floatVal = (float)$value;
        return max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, $floatVal));
    }
}