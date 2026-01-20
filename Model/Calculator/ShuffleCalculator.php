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

namespace Amadeco\ElasticSuiteBehavioral\Model\Calculator;

use Amadeco\ElasticSuiteBehavioral\Model\Config;

/**
 * Class ShuffleCalculator
 *
 * Responsible for calculating a deterministic "random" score used for the
 * Anti-Echo mechanism.
 *
 * Refactored: Now generates a fresh random seed per execution cycle.
 */
class ShuffleCalculator
{
    /**
     * @var string A unique salt generated once per execution (Command/Cron run).
     */
    private string $executionSeed;

    /**
     * @param Config $config Configuration provider.
     */
    public function __construct(
        private readonly Config $config
    ) {
        // Generate a random seed for this specific execution process.
        // This ensures that every time the Cron runs, the shuffle order changes completely.
        $this->executionSeed = uniqid('shuffle_', true);
    }

    /**
     * Calculate the shuffle component for a specific product.
     *
     * @param int $productId The product entity ID.
     * @return int A score between 0 and 100.
     */
    public function calculateShuffleScore(int $productId): int
    {
        if (!$this->config->isShuffleEnabled()) {
            return 0;
        }

        // Combine Product ID with the Execution Seed.
        // CRC32 is sufficient for uniform distribution (0-100) here.
        $seedString = sprintf('%d_%s', $productId, $this->executionSeed);
        $hash = crc32($seedString);

        // Normalize to 0-100 range
        return abs($hash) % 101;
    }

    /**
     * Calculate the blending factor based on configuration weight.
     *
     * @return float A float between 0.0 and 1.0.
     */
    public function getBlendFactor(): float
    {
        if (!$this->config->isShuffleEnabled()) {
            return 0.0;
        }

        $weight = $this->config->getShuffleWeight();
        // Cap at 0.5 (50%) to prevent randomness from overtaking performance.
        return min(0.5, max(0.0, $weight / 10.0));
    }
}