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

/**
 * Class CalculateScores
 *
 * Cron job entry point for scheduled behavioral analysis.
 * Configured via etc/crontab.xml to run daily (default: 03:00 AM).
 *
 * Note: Error handling is deliberately omitted here. This allows Magento's native
 * ProcessCronQueueObserver to catch exceptions, log them to the standard exception.log,
 * and correctly mark the cron_schedule table entry with an 'error' status.
 */
class CalculateScores
{
    /**
     * @param BehavioralServiceInterface $service Service contract for business logic.
     * @param Config                     $config  Module configuration reader.
     */
    public function __construct(
        private readonly BehavioralServiceInterface $service,
        private readonly Config $config
    ) {
    }

    /**
     * Execute the behavioral analysis cron job.
     *
     * Checks if the module is enabled in the configuration before proceeding.
     * Any exceptions thrown by the service will bubble up to the Magento Cron framework.
     *
     * @return void
     * @throws \Exception If the service execution fails, it bubbles up to be handled by Magento.
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $this->service->execute();
    }
}
