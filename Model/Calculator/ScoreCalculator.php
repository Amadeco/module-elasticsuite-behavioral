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

use Amadeco\ElasticSuiteBehavioral\Api\Data\BehavioralMetricInterface;
use Amadeco\ElasticSuiteBehavioral\Model\Config;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Pure business logic engine responsible for calculating normalized behavioral scores.
 *
 * Optimized for high-throughput (O(1) memory) by caching store-level configurations
 * and preventing repetitive object instantiations inside the calculation loops.
 * Adheres strictly to SRP by delegating specific mathematical concepts to specialized classes.
 */
class ScoreCalculator
{
    /**
     * Mathematical boundaries for the scoring algorithm.
     */
    private const int MAX_SCORE = 100;
    private const int MIN_SCORE = 0;

    /**
     * Date processing constants.
     */
    private const string TIMEZONE_UTC = 'UTC';

    /**
     * @var array<int, array<string, mixed>> L1 Cache for store-level configurations to prevent O(N) lookups.
     */
    private array $storeConfigCache = [];

    /**
     * @var array<int, \DateTimeImmutable> L1 Cache for the "Now" reference per store.
     */
    private array $nowCache = [];

    /**
     * @param BayesianSmoother $smoother Handles logarithmic normalization and Bayesian averages.
     * @param Config $config Module configuration provider.
     * @param ShuffleCalculator $shuffleCalculator Handles the Anti-Echo deterministic randomization.
     * @param TimezoneInterface $timezone Manages chronological state (Injected for testability).
     * @param LoggerInterface $logger Handles debug output.
     */
    public function __construct(
        private readonly BayesianSmoother $smoother,
        private readonly Config $config,
        private readonly ShuffleCalculator $shuffleCalculator,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Calculate the final score and formatted row for a single product.
     *
     * @param BehavioralMetricInterface $metric Raw metrics DTO.
     * @param array<string, float|int>  $stats  Global dataset statistics (max sales, max revenue, etc).
     *
     * @return array<string, mixed> Calculated data set ready for database persistence.
     */
    public function calculateRow(BehavioralMetricInterface $metric, array $stats): array
    {
        $pid = $metric->getProductId();
        $storeId = $metric->getStoreId();

        // Ensure configuration is loaded into local memory for this store
        $storeConfig = $this->getStoreConfig($storeId);

        // 1. Calculate Freshness Boost (Smart Freshness)
        $freshnessBoost = $this->calculateFreshnessBoost(
            $metric->getCreatedAt(),
            $metric->getNewsFromDate(),
            $pid,
            $storeId,
            $storeConfig
        );

        // --- 2. THE AVAILABILITY GATE ---

        // Rule A: Stock Check
        if (!$metric->isSalable()) {
            $isComingSoon = (bool)($storeConfig['is_coming_soon'] ?? false);
            $allowedFreshness = $isComingSoon ? $freshnessBoost : self::MIN_SCORE;

            if ($storeConfig['is_debug']) {
                $this->log($storeId, $pid, "GATE: Blocked by Out of Stock.", [
                    'coming_soon_enabled' => $isComingSoon,
                    'freshness_preserved' => $allowedFreshness,
                ]);
            }

            return $this->getZeroScoreRow($metric, $allowedFreshness);
        }

        // Rule B: Discontinued Check
        if ($metric->isDiscontinued()) {
            if ($storeConfig['is_debug']) {
                $this->log($storeId, $pid, "GATE: Blocked by Discontinued Flag.");
            }
            return $this->getZeroScoreRow($metric, self::MIN_SCORE);
        }

        // --- 3. Performance Logic (For Salable Items) ---
        $performanceScore = $this->calculatePerformanceScore($metric, $stats, $storeConfig);

        // --- 4. Discovery Shuffle ---
        $finalScore = $this->applyShuffleLogic($performanceScore, $pid, $storeConfig);

        if ($storeConfig['is_debug']) {
            $this->log($storeId, $pid, "FINAL: Calculation Complete.", [
                'base_perf' => round($performanceScore, 2),
                'final'     => (int)$finalScore,
                'freshness' => $freshnessBoost,
            ]);
        }

        return [
            BehavioralMetricInterface::PRODUCT_ID       => $pid,
            BehavioralMetricInterface::STORE_ID         => $storeId,
            BehavioralMetricInterface::RAW_SALES        => $metric->getRawSales(),
            BehavioralMetricInterface::RAW_REVENUE      => $metric->getRawRevenue(),
            BehavioralMetricInterface::RAW_VIEWS        => $metric->getRawViews(),
            BehavioralMetricInterface::RAW_CLICKS       => $metric->getRawClicks(),
            BehavioralMetricInterface::RAW_ADD_TO_CARTS => $metric->getRawAddToCarts(),
            BehavioralMetricInterface::RATING_SCORE     => $metric->getRatingSummary(),
            BehavioralMetricInterface::GLOBAL_SCORE     => (int)max(self::MIN_SCORE, min(self::MAX_SCORE, $finalScore)),
            BehavioralMetricInterface::IS_NEW_BOOST     => $freshnessBoost,
        ];
    }

    /**
     * Helper to return a "Zeroed" row structure for penalized products.
     *
     * @param BehavioralMetricInterface $metric The product metric DTO.
     * @param int $freshnessBoost Optional boost value to preserve (default 0).
     * @return array<string, mixed>
     */
    private function getZeroScoreRow(BehavioralMetricInterface $metric, int $freshnessBoost = self::MIN_SCORE): array
    {
        return [
            BehavioralMetricInterface::PRODUCT_ID       => $metric->getProductId(),
            BehavioralMetricInterface::STORE_ID         => $metric->getStoreId(),
            BehavioralMetricInterface::RAW_SALES        => $metric->getRawSales(),
            BehavioralMetricInterface::RAW_REVENUE      => $metric->getRawRevenue(),
            BehavioralMetricInterface::RAW_VIEWS        => $metric->getRawViews(),
            BehavioralMetricInterface::RAW_CLICKS       => $metric->getRawClicks(),
            BehavioralMetricInterface::RAW_ADD_TO_CARTS => $metric->getRawAddToCarts(),
            BehavioralMetricInterface::RATING_SCORE     => $metric->getRatingSummary(),
            BehavioralMetricInterface::GLOBAL_SCORE     => self::MIN_SCORE,
            BehavioralMetricInterface::IS_NEW_BOOST     => $freshnessBoost,
        ];
    }

    /**
     * Computes the raw performance score based on weighted metrics and progressive penalties.
     *
     * @param BehavioralMetricInterface $metric
     * @param array<string, float|int> $stats
     * @param array<string, mixed> $storeConfig Memoized store configuration.
     * @return float Calculated performance score before constraints.
     */
    private function calculatePerformanceScore(
        BehavioralMetricInterface $metric,
        array $stats,
        array $storeConfig
    ): float {
        $pid = $metric->getProductId();
        $storeId = $metric->getStoreId();

        /** @var array<string, float> $weights */
        $weights = $storeConfig['weights'];

        $globalAverageCtr = (float)($stats['global_average_ctr'] ?? Config::DEFAULT_GLOBAL_CTR);

        // A. Normalize Hard Conversion Metrics (Logarithmic)
        $scoreSales = $this->smoother->normalizeLogarithmic(
            $metric->getRawSales(),
            (float)($stats['max_sales'] ?? Config::DEFAULT_MAX_SALES)
        );
        $scoreRevenue = $this->smoother->normalizeLogarithmic(
            $metric->getRawRevenue(),
            (float)($stats['max_revenue'] ?? Config::DEFAULT_MAX_REVENUE)
        );

        // B. Dynamic CTR Scoring (Bayesian)
        $scoreCtr = 0.0;
        $smoothedCtr = 0.0;

        if ($metric->getRawViews() > 0) {
            $smoothedCtr = $this->smoother->getSmoothedCtr(
                $metric->getRawClicks(),
                $metric->getRawViews(),
                $globalAverageCtr,
                (int)$storeConfig['bayesian_threshold']
            );
            $scoreCtr = $this->smoother->normalizeLogarithmic($smoothedCtr * self::MAX_SCORE, (float)$storeConfig['ctr_ceiling']);
        }

        // C. Normalize Add to Cart (Logarithmic)
        $scoreAtc = $this->smoother->normalizeLogarithmic(
            (float)$metric->getRawAddToCarts(),
            (float)($stats['max_atc'] ?? Config::DEFAULT_MAX_ATC)
        );

        // D. Weighted Sum
        $totalWeight = array_sum($weights);

        $weightedSum = ($scoreSales * $weights['sales']) +
                       ($scoreRevenue * $weights['revenue']) +
                       ($scoreCtr * $weights['ctr']) +
                       ($scoreAtc * $weights['atc']) +
                       ($metric->getRatingSummary() * $weights['rating']);

        // Prevent division by zero if weights are heavily misconfigured in Admin
        $baseScore = $totalWeight > 0.0 ? ($weightedSum / $totalWeight) : 0.0;

        // E. Apply Progressive Penalty
        $penalty = $this->calculateProgressivePenalty(
            $metric->getRawViews(),
            $metric->getRawClicks(),
            $globalAverageCtr,
            (float)$weights['bounce_penalty']
        );

        if ($storeConfig['is_debug']) {
            $this->log($storeId, $pid, "PERF: Breakdown calculated.", [
                'inputs' => [
                    'sales' => $metric->getRawSales(),
                    'rev'   => $metric->getRawRevenue(),
                    'views' => $metric->getRawViews(),
                    'click' => $metric->getRawClicks(),
                ],
                'normalized_scores' => [
                    'sales'   => round($scoreSales, 1),
                    'revenue' => round($scoreRevenue, 1),
                    'ctr'     => round($scoreCtr, 1),
                    'atc'     => round($scoreAtc, 1),
                ],
                'pre_penalty_score' => $baseScore,
                'penalty_deduction' => $penalty,
            ]);
        }

        return max(0.0, $baseScore - $penalty);
    }

    /**
     * Calculates a penalty strictly proportional to how bad the CTR is compared to the global average.
     *
     * @param int $views
     * @param int $clicks
     * @param float $globalCtr
     * @param float $maxPenalty
     * @return float Calculated penalty to subtract.
     */
    private function calculateProgressivePenalty(int $views, int $clicks, float $globalCtr, float $maxPenalty): float
    {
        if ($views < Config::THRESHOLD_BOUNCE_VIEWS || $maxPenalty <= 0.0) {
            return 0.0;
        }

        $productCtr = $clicks / $views;
        $failThreshold = $globalCtr * Config::BOUNCE_SENSITIVITY;

        if ($productCtr >= $failThreshold) {
            return 0.0;
        }

        $severity = 1.0 - ($productCtr / $failThreshold);
        return $maxPenalty * $severity;
    }

    /**
     * Blends the Performance Score with the Shuffle Score.
     *
     * @param float $performanceScore
     * @param int $productId
     * @param array<string, mixed> $storeConfig
     * @return float Final blended score.
     */
    private function applyShuffleLogic(float $performanceScore, int $productId, array $storeConfig): float
    {
        if (!$storeConfig['is_shuffle']) {
            return $performanceScore;
        }

        $shuffleScore = $this->shuffleCalculator->calculateShuffleScore($productId);
        $blendFactor  = $this->shuffleCalculator->getBlendFactor();

        return ($performanceScore * (1.0 - $blendFactor)) + ($shuffleScore * $blendFactor);
    }

    /**
     * Calculate Boost based on "News From Date" (Priority) OR "Created At".
     * Decay is calculated relative to the system's current time.
     *
     * @param string|null $createdAt
     * @param string|null $newsFromDate
     * @param int $pid
     * @param int $storeId
     * @param array<string, mixed> $storeConfig
     * @return int Boost value from 0 to 100.
     */
    private function calculateFreshnessBoost(
        ?string $createdAt,
        ?string $newsFromDate,
        int $pid,
        int $storeId,
        array $storeConfig
    ): int {
        $duration = (int)$storeConfig['freshness_duration'];
        if ($duration <= 0) {
            return self::MIN_SCORE;
        }

        $now = $this->getNowReference($storeId);

        try {
            // 1. Priority: Check "Set Product as New From"
            if ($newsFromDate !== null) {
                $newsDate = new \DateTimeImmutable($newsFromDate, new \DateTimeZone(self::TIMEZONE_UTC));
                $diff = $now->diff($newsDate);

                // If date is entirely in the future
                if ($diff->invert === 0) {
                    if ($storeConfig['is_debug']) {
                        $this->log($storeId, $pid, "FRESH: Future 'News From' date detected. Max Boost.");
                    }
                    return self::MAX_SCORE;
                }

                if ($diff->days < $duration) {
                    $boost = (int)(self::MAX_SCORE * (1.0 - ($diff->days / $duration)));
                    if ($storeConfig['is_debug']) {
                        $this->log($storeId, $pid, "FRESH: Applied via 'News From'.", ['days_old' => $diff->days, 'boost' => $boost]);
                    }
                    return $boost;
                }
            }

            // 2. Fallback: Check "Created At"
            if ($createdAt !== null) {
                $createdDate = new \DateTimeImmutable($createdAt, new \DateTimeZone(self::TIMEZONE_UTC));
                $diffDays = (int)$now->diff($createdDate)->days;

                if ($diffDays < $duration) {
                    $boost = (int)(self::MAX_SCORE * (1.0 - ($diffDays / $duration)));
                    if ($storeConfig['is_debug']) {
                        $this->log($storeId, $pid, "FRESH: Applied via 'Created At'.", ['days_old' => $diffDays, 'boost' => $boost]);
                    }
                    return $boost;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning("Amadeco Behavioral: Date calculation error for Product $pid: " . $e->getMessage());
        }

        return self::MIN_SCORE;
    }

    /**
     * Retrieves or builds the memoized configuration array for a specific store.
     * Prevents O(N) configuration lookups during massive catalog processing.
     *
     * @param int $storeId
     * @return array<string, mixed> Configuration metrics.
     */
    private function getStoreConfig(int $storeId): array
    {
        if (!isset($this->storeConfigCache[$storeId])) {
            $this->storeConfigCache[$storeId] = [
                'is_debug'           => $this->config->isDebugEnabled($storeId),
                'weights'            => $this->config->getWeights($storeId),
                'ctr_ceiling'        => $this->config->getCtrNormalizationCeiling($storeId),
                'bayesian_threshold' => $this->config->getBayesianConfidenceThreshold($storeId),
                'freshness_duration' => $this->config->getFreshnessDuration($storeId),
                'is_coming_soon'     => $this->config->isComingSoonBoostEnabled($storeId),
                'is_shuffle'         => $this->config->isShuffleEnabled($storeId),
            ];
        }

        return $this->storeConfigCache[$storeId];
    }

    /**
     * Retrieves a single memoized "Now" DateTime object per store using TimezoneInterface.
     * Prevents instantiating 100,000+ date objects per cron run while remaining mockable.
     *
     * @param int $storeId
     * @return \DateTimeImmutable
     */
    private function getNowReference(int $storeId): \DateTimeImmutable
    {
        if (!isset($this->nowCache[$storeId])) {
            // Retrieve current time in UTC (false flag prevents timezone conversion)
            $nowMutable = $this->timezone->date(null, null, false);

            // Convert to immutable to prevent accidental mutation across the loop
            $this->nowCache[$storeId] = \DateTimeImmutable::createFromMutable($nowMutable);
        }

        return $this->nowCache[$storeId];
    }

    /**
     * Internal centralized logger.
     *
     * @param int $storeId
     * @param int $pid
     * @param string $msg
     * @param array<string, mixed> $context
     * @return void
     */
    private function log(int $storeId, int $pid, string $msg, array $context = []): void
    {
        $this->logger->info(
            sprintf("[Behavioral Debug] [Store: %d] [Product: %d] %s", $storeId, $pid, $msg),
            $context
        );
    }
}
