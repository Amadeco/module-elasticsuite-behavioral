<?php

declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration Accessor for Behavioral Merchandising.
 *
 * Handles retrieval of system configuration values with strict typing
 * and fallback default values.
 */
final class Config
{
    // Native ElasticSuite Tracker Setting
    private const string XML_PATH_TRACKER_ENABLED = 'smile_elasticsuite_tracker/general/enabled';

    // General Settings
    private const string XML_PATH_ENABLE = 'behavioral_merchandising/general/enable';
    private const string XML_PATH_PERIOD = 'behavioral_merchandising/general/analysis_period';
    private const string XML_PATH_DEBUG = 'behavioral_merchandising/general/debug_mode';

    // Weight Settings
    private const string XML_PATH_W_CTR = 'behavioral_merchandising/weights/weight_ctr';
    private const string XML_PATH_W_ATC = 'behavioral_merchandising/weights/weight_atc';
    private const string XML_PATH_W_SALES = 'behavioral_merchandising/weights/weight_sales';
    private const string XML_PATH_W_REVENUE = 'behavioral_merchandising/weights/weight_revenue';
    private const string XML_PATH_W_RATING = 'behavioral_merchandising/weights/weight_rating';
    private const string XML_PATH_W_BOUNCE = 'behavioral_merchandising/weights/weight_bounce_penalty';

    // Constraint Settings
    private const string XML_PATH_CHECK_STOCK = 'behavioral_merchandising/constraints/check_stock';
    private const string XML_PATH_CHECK_DISCONTINUED = 'behavioral_merchandising/constraints/check_discontinued';

    // Anti-Echo / Bayesian Settings
    private const string XML_PATH_CTR_CEILING = 'behavioral_merchandising/anti_echo/ctr_normalization_ceiling';
    private const string XML_PATH_CONFIDENCE = 'behavioral_merchandising/anti_echo/bayesian_min_views';
    private const string XML_PATH_FRESHNESS = 'behavioral_merchandising/anti_echo/freshness_duration';
    private const string XML_PATH_BOOST_COMING_SOON = 'behavioral_merchandising/anti_echo/boost_coming_soon';
    private const string XML_PATH_SHUFFLE_ENABLE = 'behavioral_merchandising/anti_echo/enable_shuffle';
    private const string XML_PATH_SHUFFLE_WEIGHT = 'behavioral_merchandising/anti_echo/shuffle_weight';

    // Default Values
    private const int DEFAULT_ANALYSIS_PERIOD = 60;
    private const float DEFAULT_CTR_CEILING = 15.0;
    private const int DEFAULT_CONFIDENCE_THRESHOLD = 50;
    private const int DEFAULT_FRESHNESS_DURATION = 30;
    private const int DEFAULT_SHUFFLE_WEIGHT = 2;

    // Default Weights
    private const float DEFAULT_W_CTR = 1.0;
    private const float DEFAULT_W_ATC = 1.0;
    private const float DEFAULT_W_SALES = 1.0;
    private const float DEFAULT_W_REVENUE = 1.0;
    private const float DEFAULT_W_RATING = 0.5;
    private const float DEFAULT_W_BOUNCE = 10.0;

    // Business Logic Thresholds
    public const int THRESHOLD_BOUNCE_VIEWS = 50;
    public const int THRESHOLD_BOUNCE_SALES = 1;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Check if the module functionality is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
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
     * @param int|string|null $storeId
     * @return bool
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
     * Check if debug mode is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
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
     * Get the analysis period in days.
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getAnalysisPeriod(int|string|null $storeId = null): int
    {
        $value = (int) $this->getConfigValue(self::XML_PATH_PERIOD, $storeId);

        return $value > 0 ? $value : self::DEFAULT_ANALYSIS_PERIOD;
    }

    /**
     * Retrieve all behavioral weights configuration.
     *
     * @param int|string|null $storeId
     * @return array{
     * sales: float,
     * revenue: float,
     * ctr: float,
     * atc: float,
     * rating: float,
     * bounce_penalty: float
     * }
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
     * Should we calculate score for Out of Stock products?
     *
     * @param int|string|null $storeId
     * @return bool
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
     * Should we kill the score if 'discontinued' attribute is true?
     *
     * @param int|string|null $storeId
     * @return bool
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
     * Get the CTR normalization ceiling value.
     *
     * @param int|string|null $storeId
     * @return float
     */
    public function getCtrNormalizationCeiling(int|string|null $storeId = null): float
    {
        $value = (float) $this->getConfigValue(self::XML_PATH_CTR_CEILING, $storeId);

        return $value > 0 ? $value : self::DEFAULT_CTR_CEILING;
    }

    /**
     * Get the minimum views required for Bayesian confidence.
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getBayesianConfidenceThreshold(int|string|null $storeId = null): int
    {
        $value = (int) $this->getConfigValue(self::XML_PATH_CONFIDENCE, $storeId);

        return $value > 0 ? $value : self::DEFAULT_CONFIDENCE_THRESHOLD;
    }

    /**
     * Get the duration for data freshness definition.
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getFreshnessDuration(int|string|null $storeId = null): int
    {
        $value = (int) $this->getConfigValue(self::XML_PATH_FRESHNESS, $storeId);

        return $value > 0 ? $value : self::DEFAULT_FRESHNESS_DURATION;
    }

    /**
     * Check if "Coming Soon" (OOS) products should receive the freshness boost.
     *
     * @param int|string|null $storeId
     * @return bool
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
     * Check if shuffling of results is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
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
     * Get the weight/impact of the shuffle mechanism.
     *
     * Clamped between 0 and 10 to prevent algorithmic skewing.
     *
     * @param int|string|null $storeId
     * @return int
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
     * Internal helper to retrieve raw config values.
     *
     * @param string $path
     * @param int|string|null $storeId
     * @return string|null
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