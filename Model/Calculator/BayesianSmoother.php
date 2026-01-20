<?php

declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Model\Calculator;

/**
 * Utility class for smoothing and normalization.
 */
final class BayesianSmoother
{
    /**
     * Compute a Bayesian-smoothed CTR.
     *
     * @param int   $clicks
     * @param int   $views
     * @param float $globalAverageCtr      Global CTR (0..1)
     * @param int   $confidenceThreshold   Pseudo-count (e.g. 50)
     *
     * @return float Smoothed CTR (0..1)
     */
    public function getSmoothedCtr(
        int $clicks,
        int $views,
        float $globalAverageCtr,
        int $confidenceThreshold
    ): float {
        if ($views <= 0) {
            return 0.0;
        }

        $confidenceThreshold = max(0, $confidenceThreshold);
        $globalAverageCtr = max(0.0, min(1.0, $globalAverageCtr));

        $numerator = $clicks + ($confidenceThreshold * $globalAverageCtr);
        $denominator = $views + $confidenceThreshold;

        return $denominator > 0 ? (float) ($numerator / $denominator) : 0.0;
    }

    /**
     * Log normalization to reduce dominance of outliers.
     *
     * @param float $value    Raw value (>= 0)
     * @param float $ceiling  Value considered "max" ( > 0 )
     *
     * @return float Normalized score (0..100)
     */
    public function normalizeLogarithmic(float $value, float $ceiling): float
    {
        $value = max(0.0, $value);
        $ceiling = max(0.000001, $ceiling);

        $normalized = log(1.0 + $value) / log(1.0 + $ceiling);

        return max(0.0, min(100.0, $normalized * 100.0));
    }
}
