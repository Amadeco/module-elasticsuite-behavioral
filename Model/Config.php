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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration Accessor for Behavioral Merchandising.
 *
 * Handles retrieval of system configuration values with strict typing
 * and fallback default values. Acts as a single source of truth for
 * all module configurations defined in system.xml.
 */
final class Config
{
    /**
     * Native ElasticSuite Tracker Setting path.
     */
    private const string XML_PATH_TRACKER_ENABLED = 'smile_elasticsuite_tracker/general/enabled';

    /**
     * General Settings paths.
     */
    private const string XML_PATH_ENABLE = 'behavioral_merchandising/general/enable';
    private const string XML_PATH_PERIOD = 'behavioral_merchandising/general/analysis_period';
    private const string XML_PATH_DEBUG = 'behavioral_merchandising/general/debug_mode';

    /**
     * Weight Settings paths.
     */
    private const string XML_PATH_W_CTR = 'behavioral_merchandising/weights/weight_ctr';
    private const string XML_PATH_W_ATC = 'behavioral_merchandising/weights/weight_atc';
    private const string XML_PATH_W_SALES = 'behavioral_merchandising/weights/weight_sales';
    private const string XML_PATH_W_REVENUE = 'behavioral_merchandising/weights/weight_revenue';
    private const string XML_PATH_W_RATING = 'behavioral_merchandising/weights/weight_rating';
    private const string XML_PATH_W_BOUNCE = 'behavioral_merchandising/weights/weight_bounce_penalty';

    /**
     * Constraint Settings paths.
     */
    private const string XML_PATH_CHECK_STOCK = 'behavioral_merchandising/constraints/check_stock';
    private const string XML_PATH_CHECK_DISCONTINUED = 'behavioral_merchandising/constraints/check_discontinued';

    /**
     * Anti-Echo / Bayesian Settings paths.
     */
    private const string XML_PATH_CTR_CEILING = 'behavioral_merchandising/anti_echo/ctr_normalization_ceiling';
    private const string XML_PATH_CONFIDENCE = 'behavioral_merchandising/anti_echo/bayesian_min_views';
    private const string XML_PATH_FRESHNESS = 'behavioral_merchandising/anti_echo/freshness_duration';
    private const string XML_PATH_BOOST_COMING_SOON = 'behavioral_merchandising/anti_echo/boost_coming_soon';
    private const string XML_PATH_SHUFFLE_ENABLE = 'behavioral_merchandising/anti_echo/enable_shuffle';
    private const string XML_PATH_SHUFFLE_WEIGHT = 'behavioral_merchandising/anti_echo/shuffle_weight';

    /**
     * Default Values for general and anti-echo settings.
     */
    public const int DEFAULT_ANALYSIS_PERIOD = 60;
    public const float DEFAULT_CTR_CEILING = 5.0;
    public const int DEFAULT_CONFIDENCE_THRESHOLD = 50;
    public const int DEFAULT_FRESHNESS_DURATION = 30;
    public const int DEFAULT_SHUFFLE_WEIGHT = 2;

    /**
     * Default Weights for the scoring algorithm.
     */
    public const float DEFAULT_W_CTR = 1.0;
    public const float DEFAULT_W_ATC = 1.0;
    public const float DEFAULT_W_SALES = 1.0;
    public const float DEFAULT_W_REVENUE = 1.0;
    public const float DEFAULT_W_RATING = 0.5;
    public const float DEFAULT_W_BOUNCE = 20.0;

    /**
     * Minimum views required before the bounce penalty is considered.
     * @var int
     */
    public const int THRESHOLD_BOUNCE_VIEWS = 100;

    /**
     * Minimum sales required to avoid being considered a "bounce".
     * @var int
     */
    public const int THRESHOLD_BOUNCE_SALES = 1;

    /**
     * Algorithm Fallbacks and Baseline Thresholds
     */
    public const float DEFAULT_GLOBAL_CTR = 0.02;
    public const float BOUNCE_SENSITIVITY = 0.2;
    public const float DEFAULT_MAX_SALES = 1.0;
    public const float DEFAULT_MAX_REVENUE = 1.0;
    public const float DEFAULT_MAX_ATC = 50.0;

    /**
     * Config constructor.
     *
     * @param ScopeConfigInterface $scopeConfig The Magento scope configuration interface.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Check if the behavioral merchandising module is enabled.
     *
     * @param int|string|null $storeId The store identifier.
     * @return bool True if enabled, false otherwise.
     */
    public function isEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the native Smile ElasticSuite Tracker is enabled.
     *
     * @param int|string|null $storeId The store identifier.
     * @return bool True if tracker is enabled, false otherwise.
     */
    public function isTrackerEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_TRACKER_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if debug mode is enabled for logging detailed algorithmic calculations.
     *
     * @param int|string|null $storeId The store identifier.
     * @return bool True if debug mode is active.
     */
    public function isDebugEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DEBUG,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get the analysis lookback period in days.
     *
     * @param int|string|null $storeId The store identifier.
     * @return int Number of days to look back for data. Falls back to DEFAULT_ANALYSIS_PERIOD.
     */
    public function getAnalysisPeriod(int|string|null $storeId = null): int
    {
        $value = (int) $this->getConfigValue(self::XML_PATH_PERIOD, $storeId);

        return $value > 0 ? $value : self::DEFAULT_ANALYSIS_PERIOD;
    }

    /**
     * Retrieve all behavioral algorithm weights as a configured array.
     *
     * @param int|string|null $storeId The store identifier.
     * @return array{
     * ctr: float,
     * atc: float,
     * sales: float,
     * revenue: float,
     * rating: float,
     * bounce_penalty: float
     * } Array of configured weights.
     */
    public function getWeights(int|string|null $storeId = null): array
    {
        return [
            'ctr' => (float) $this->getConfigValue(self::XML_PATH_W_CTR, $storeId) ?: self::DEFAULT_W_CTR,
            'atc' => (float) $this->getConfigValue(self::XML_PATH_W_ATC, $storeId) ?: self::DEFAULT_W_ATC,
            'sales' => (float) $this->getConfigValue(self::XML_PATH_W_SALES, $storeId) ?: self::DEFAULT_W_SALES,
            'revenue' => (float) $this->getConfigValue(self::XML_PATH_W_REVENUE, $storeId) ?: self::DEFAULT_W_REVENUE,
            'rating' => (float) $this->getConfigValue(self::XML_PATH_W_RATING, $storeId) ?: self::DEFAULT_W_RATING,
            'bounce_penalty' => (float) $this->getConfigValue(self::XML_PATH_W_BOUNCE, $storeId) ?: self::DEFAULT_W_BOUNCE,
        ];
    }

    /**
     * Check if the algorithm should evaluate out-of-stock products.
     *
     * @param int|string|null $storeId The store identifier.
     * @return bool True to calculate scores for out-of-stock items, false to ignore them.
     */
    public function isStockCheckEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CHECK_STOCK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the algorithm should apply a zero score to discontinued products.
     *
     * @param int|string|null $storeId The store identifier.
     * @return bool True to penalize discontinued items.
     */
    public function isDiscontinuedCheckEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CHECK_DISCONTINUED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get the CTR normalization ceiling value (the "Perfect Score" threshold).
     *
     * @param int|string|null $storeId The store identifier.
     * @return float The ceiling percentage value. Falls back to DEFAULT_CTR_CEILING.
     */
    public function getCtrNormalizationCeiling(int|string|null $storeId = null): float
    {
        $value = (float) $this->getConfigValue(self::XML_PATH_CTR_CEILING, $storeId);

        return $value > 0 ? $value : self::DEFAULT_CTR_CEILING;
    }

    /**
     * Get the minimum views required for Bayesian confidence smoothing.
     *
     * @param int|string|null $storeId The store identifier.
     * @return int The view threshold count. Falls back to DEFAULT_CONFIDENCE_THRESHOLD.
     */
    public function getBayesianConfidenceThreshold(int|string|null $storeId = null): int
    {
        $value = (int) $this->getConfigValue(self::XML_PATH_CONFIDENCE, $storeId);

        return $value > 0 ? $value : self::DEFAULT_CONFIDENCE_THRESHOLD;
    }

    /**
     * Get the duration (in days) defining how long a product is considered "Fresh".
     *
     * @param int|string|null $storeId The store identifier.
     * @return int Duration in days. Falls back to DEFAULT_FRESHNESS_DURATION.
     */
    public function getFreshnessDuration(int|string|null $storeId = null): int
    {
        $value = (int) $this->getConfigValue(self::XML_PATH_FRESHNESS, $storeId);

        return $value > 0 ? $value : self::DEFAULT_FRESHNESS_DURATION;
    }

    /**
     * Check if "Coming Soon" (Out-of-Stock but new) products should receive the freshness boost.
     *
     * @param int|string|null $storeId The store identifier.
     * @return bool True if coming soon boost is enabled.
     */
    public function isComingSoonBoostEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BOOST_COMING_SOON,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the Discovery Shuffle mechanism (Anti-Echo) is enabled.
     *
     * @param int|string|null $storeId The store identifier.
     * @return bool True if shuffling is enabled.
     */
    public function isShuffleEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHUFFLE_ENABLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get the intensity weight of the shuffle mechanism.
     * Clamped between 0 and 10 to prevent algorithmic skewing.
     *
     * @param int|string|null $storeId The store identifier.
     * @return int Shuffle weight between 0 and 10.
     */
    public function getShuffleWeight(int|string|null $storeId = null): int
    {
        $value = $this->getConfigValue(self::XML_PATH_SHUFFLE_WEIGHT, $storeId);

        // If null/empty, return default
        if ($value === null || $value === '') {
            return self::DEFAULT_SHUFFLE_WEIGHT;
        }

        return max(0, min(10, (int) $value));
    }

    /**
     * Internal helper to safely retrieve raw configuration values.
     *
     * @param string $path The XML configuration path.
     * @param int|string|null $storeId The store identifier.
     * @return string|null The raw config value as a string, or null if not set.
     */
    private function getConfigValue(string $path, int|string|null $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_scalar($value) ? (string) $value : null;
    }
}
