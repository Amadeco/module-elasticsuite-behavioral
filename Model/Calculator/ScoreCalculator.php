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
use Psr\Log\LoggerInterface;

/**
 * Class ScoreCalculator
 *
 * Pure business logic engine responsible for calculating normalized behavioral scores.
 * Enhanced with granular debug logging for algorithm transparency.
 */
class ScoreCalculator
{
    /**
     * Sensitivity factor for the bounce penalty.
     */
    private const float BOUNCE_SENSITIVITY = 0.2;

    public function __construct(
        private readonly BayesianSmoother $smoother,
        private readonly Config $config,
        private readonly ShuffleCalculator $shuffleCalculator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Calculate the final score for a single product.
     *
     * @param BehavioralMetricInterface $metric Raw metrics DTO.
     * @param array                     $stats  Global dataset statistics.
     *
     * @return array<string, mixed> Calculated data set for database persistence.
     */
    public function calculateRow(BehavioralMetricInterface $metric, array $stats): array
    {
        $pid = $metric->getProductId();
        $storeId = $metric->getStoreId();

        // 1. Calculate Freshness Boost (Smart Freshness)
        $freshnessBoost = $this->calculateFreshnessBoost(
            $metric->getCreatedAt(),
            $metric->getNewsFromDate(),
            $pid,
            $storeId
        );

        // --- 2. THE AVAILABILITY GATE ---

        // Rule A: Stock Check
        if (!$metric->isSalable()) {
            $isComingSoon = $this->config->isComingSoonBoostEnabled($storeId);
            $allowedFreshness = $isComingSoon ? $freshnessBoost : 0;

            if ($this->shouldLog($storeId)) {
                $this->log($storeId, $pid, "GATE: Blocked by Out of Stock.", [
                    'coming_soon_enabled' => $isComingSoon,
                    'freshness_preserved' => $allowedFreshness,
                ]);
            }

            return $this->getZeroScoreRow($metric, $allowedFreshness);
        }

        // Rule B: Discontinued Check
        if ($metric->isDiscontinued()) {
            if ($this->shouldLog($storeId)) {
                $this->log($storeId, $pid, "GATE: Blocked by Discontinued Flag.");
            }
            return $this->getZeroScoreRow($metric, 0);
        }

        // --- 3. Performance Logic (For Salable Items) ---
        $performanceScore = $this->calculatePerformanceScore($metric, $stats);

        // --- 4. Discovery Shuffle ---
        $finalScore = $this->applyShuffleLogic($performanceScore, $pid);

        if ($this->shouldLog($storeId)) {
            $this->log($storeId, $pid, "FINAL: Calculation Complete.", [
                'base_perf' => round($performanceScore, 2),
                'final'     => (int)$finalScore,
                'freshness' => $freshnessBoost,
            ]);
        }

        return [
            BehavioralMetricInterface::PRODUCT_ID   => $pid,
            BehavioralMetricInterface::STORE_ID     => $storeId,
            BehavioralMetricInterface::RAW_SALES    => $metric->getRawSales(),
            BehavioralMetricInterface::RAW_REVENUE  => $metric->getRawRevenue(),
            BehavioralMetricInterface::RAW_VIEWS    => $metric->getRawViews(),
            BehavioralMetricInterface::RAW_CLICKS   => $metric->getRawClicks(),
            BehavioralMetricInterface::RATING_SCORE => $metric->getRatingSummary(),
            BehavioralMetricInterface::GLOBAL_SCORE => (int)max(0, min(100, $finalScore)),
            BehavioralMetricInterface::IS_NEW_BOOST => $freshnessBoost,
        ];
    }

    /**
     * Helper to return a "Zeroed" row structure.
     *
     * @param BehavioralMetricInterface $metric
     * @param int                       $freshnessBoost Optional boost value to preserve (default 0).
     * @return array<string, mixed>
     */
    private function getZeroScoreRow(BehavioralMetricInterface $metric, int $freshnessBoost = 0): array
    {
        return [
            BehavioralMetricInterface::PRODUCT_ID   => $metric->getProductId(),
            BehavioralMetricInterface::STORE_ID     => $metric->getStoreId(),
            BehavioralMetricInterface::RAW_SALES    => $metric->getRawSales(),
            BehavioralMetricInterface::RAW_REVENUE  => $metric->getRawRevenue(),
            BehavioralMetricInterface::RAW_VIEWS    => $metric->getRawViews(),
            BehavioralMetricInterface::RAW_CLICKS   => $metric->getRawClicks(),
            BehavioralMetricInterface::RATING_SCORE => $metric->getRatingSummary(),
            BehavioralMetricInterface::GLOBAL_SCORE => 0,
            BehavioralMetricInterface::IS_NEW_BOOST => $freshnessBoost,
        ];
    }

    /**
     * Computes the raw performance score based on weighted metrics and progressive penalties.
     *
     * @param BehavioralMetricInterface $metric
     * @param array                     $stats
     * @return float
     */
    private function calculatePerformanceScore(BehavioralMetricInterface $metric, array $stats): float
    {
        $storeId = $metric->getStoreId();
        $pid = $metric->getProductId();

        $weights = $this->config->getWeights($storeId);
        $ctrCeiling = $this->config->getCtrNormalizationCeiling($storeId);
        $globalAverageCtr = (float)($stats['global_average_ctr'] ?? 0.02);

        // A. Normalize Hard Conversion Metrics (Logarithmic)
        $scoreSales = $this->smoother->normalizeLogarithmic(
            $metric->getRawSales(),
            (float)($stats['max_sales'] ?? 1.0)
        );
        $scoreRevenue = $this->smoother->normalizeLogarithmic(
            $metric->getRawRevenue(),
            (float)($stats['max_revenue'] ?? 1.0)
        );

        // B. Dynamic CTR Scoring (Bayesian)
        $scoreCtr = 0.0;
        $smoothedCtr = 0.0;
        if ($metric->getRawViews() > 0) {
            $smoothedCtr = $this->smoother->getSmoothedCtr(
                $metric->getRawClicks(),
                $metric->getRawViews(),
                $globalAverageCtr,
                $this->config->getBayesianConfidenceThreshold($storeId)
            );
            $scoreCtr = $this->smoother->normalizeLogarithmic($smoothedCtr * 100, $ctrCeiling);
        }

        // C. Normalize Add to Cart (Logarithmic)
        $scoreAtc = $this->smoother->normalizeLogarithmic(
            $metric->getRawAddToCarts(),
            (float)($stats['max_atc'] ?? 50.0)
        );

        // D. Weighted Sum
        $totalWeight = array_sum([
            $weights['sales'],
            $weights['revenue'],
            $weights['ctr'],
            $weights['atc'],
            $weights['rating'],
        ]);

        $weightedSum = ($scoreSales * $weights['sales']) +
                       ($scoreRevenue * $weights['revenue']) +
                       ($scoreCtr * $weights['ctr']) +
                       ($scoreAtc * $weights['atc']) +
                       ($metric->getRatingSummary() * $weights['rating']);

        $baseScore = $totalWeight > 0 ? ($weightedSum / $totalWeight) : 0.0;

        // E. Apply Progressive Penalty
        $penalty = $this->calculateProgressivePenalty(
            $metric->getRawViews(),
            $metric->getRawClicks(),
            $globalAverageCtr,
            $weights['bounce_penalty']
        );

        // Debug Logging for Performance Breakdown
        if ($this->shouldLog($storeId)) {
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
                'ctr_details' => [
                    'raw_ctr'      => $metric->getRawViews() > 0 ? $metric->getRawClicks() / $metric->getRawViews() : 0,
                    'smoothed_ctr' => $smoothedCtr,
                    'global_avg'   => $globalAverageCtr,
                ],
                'penalty_deduction' => $penalty,
                'pre_penalty_score' => $baseScore,
            ]);
        }

        return max(0.0, $baseScore - $penalty);
    }

    /**
     * Calculates a penalty strictly proportional to how bad the CTR is.
     *
     * @param int   $views
     * @param int   $clicks
     * @param float $globalCtr
     * @param float $maxPenalty
     * @return float
     */
    private function calculateProgressivePenalty(int $views, int $clicks, float $globalCtr, float $maxPenalty): float
    {
        if ($views <= 0 || $views < Config::THRESHOLD_BOUNCE_VIEWS) {
            return 0.0;
        }

        $productCtr = $clicks / $views;
        $failThreshold = $globalCtr * self::BOUNCE_SENSITIVITY;

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
     * @param int   $productId
     * @return float
     */
    private function applyShuffleLogic(float $performanceScore, int $productId): float
    {
        if (!$this->config->isShuffleEnabled()) {
            return $performanceScore;
        }

        $shuffleScore = $this->shuffleCalculator->calculateShuffleScore($productId);
        $blendFactor  = $this->shuffleCalculator->getBlendFactor();

        $final = ($performanceScore * (1.0 - $blendFactor)) + ($shuffleScore * $blendFactor);

        // Note: Shuffle debug is implicit in final score, detailed logging can be too noisy here.
        return $final;
    }

    /**
     * Calculate Boost based on "News From Date" (Priority) OR "Created At".
     *
     * @param string|null $createdAt
     * @param string|null $newsFromDate
     * @param int $pid
     * @param int $storeId
     * @return int
     */
    private function calculateFreshnessBoost(?string $createdAt, ?string $newsFromDate, int $pid, int $storeId): int
    {
        $duration = $this->config->getFreshnessDuration($storeId);
        if ($duration <= 0) {
            return 0;
        }

        $utc = new \DateTimeZone('UTC');
        $now = new \DateTime('now', $utc);

        try {
            // 1. Priority: Check "Set Product as New From"
            if ($newsFromDate) {
                $newsDate = new \DateTime($newsFromDate, $utc);
                $diff = $now->diff($newsDate);

                if ($diff->invert === 0) {
                    if ($this->shouldLog($storeId)) {
                        $this->log($storeId, $pid, "FRESH: Future 'News From' date detected. Max Boost.");
                    }
                    return 100;
                }

                if ($diff->days < $duration) {
                    $boost = (int)(100 * (1.0 - ($diff->days / $duration)));
                    if ($this->shouldLog($storeId)) {
                        $this->log($storeId, $pid, "FRESH: Applied via 'News From'.", ['days_old' => $diff->days, 'boost' => $boost]);
                    }
                    return $boost;
                }
            }

            // 2. Fallback: Check "Created At"
            if ($createdAt) {
                $createdDate = new \DateTime($createdAt, $utc);
                $diff = $now->diff($createdDate)->days;

                if ($diff < $duration) {
                    $boost = (int)(100 * (1.0 - ($diff / $duration)));
                    if ($this->shouldLog($storeId)) {
                        $this->log($storeId, $pid, "FRESH: Applied via 'Created At'.", ['days_old' => $diff, 'boost' => $boost]);
                    }
                    return $boost;
                }

                // Log why boost is 0 if debug is on
                if ($this->shouldLog($storeId)) {
                    $this->log($storeId, $pid, "FRESH: Product too old.", ['days_old' => $diff, 'limit' => $duration]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning("Date calculation error for Product $pid: " . $e->getMessage());
        }

        return 0;
    }

    /**
     * Check if logging is enabled for this store.
     * Use a minimal check to reduce overhead.
     *
     * @param int $storeId
     * @return bool
     */
    private function shouldLog(int $storeId): bool
    {
        return $this->config->isDebugEnabled($storeId);
    }

    /**
     * Internal centralized logger.
     *
     * @param int $storeId
     * @param int $pid
     * @param string $msg
     * @param array $context
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