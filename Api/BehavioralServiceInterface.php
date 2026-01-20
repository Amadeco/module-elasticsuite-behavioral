<?php
/**
 * Amadeco_ElasticSuiteBehavioral
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @author    Amadeco Core Team
 */
declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Interface BehavioralServiceInterface
 *
 * Service Contract responsible for triggering the calculation of behavioral scores.
 * This service aggregates data from various sources (Sales, Tracker, Reviews),
 * computes a normalized score per product, and persists it to the database.
 *
 * @api
 */
interface BehavioralServiceInterface
{
    /**
     * Execute the behavioral analysis process.
     *
     * This method orchestrates the entire scoring flow:
     * 1. Aggregates data (Views, Clicks, Sales).
     * 2. Calculates scores using the configured algorithm.
     * 3. Updates the 'behavioral_score' attribute for products.
     * 4. Triggers necessary index invalidations.
     *
     * @return void
     * @throws LocalizedException If the configuration is invalid or execution fails.
     */
    public function execute(): void;
}