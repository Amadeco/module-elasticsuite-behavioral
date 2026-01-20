<?php
/**
 * Amadeco_ElasticSuiteBehavioral
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @author    Amadeco Core Team
 */
declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Cron;

use Amadeco\ElasticSuiteBehavioral\Api\BehavioralServiceInterface;
use Amadeco\ElasticSuiteBehavioral\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Class CalculateScores
 *
 * Cron job entry point for scheduled behavioral analysis.
 * Configured via etc/crontab.xml to run daily (default: 03:00 AM).
 */
class CalculateScores
{
    /**
     * @param BehavioralServiceInterface $service Service contract for business logic.
     * @param Config                     $config  Module configuration reader.
     * @param LoggerInterface            $logger  PSR-3 logger for exception tracking.
     */
    public function __construct(
        private readonly BehavioralServiceInterface $service,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Execute the cron job.
     *
     * Checks if the module is enabled in configuration before proceeding.
     * Exceptions are caught and logged to prevent halting the Magento cron scheduler.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $this->service->execute();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Error during Behavioral Analysis Cron execution: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}