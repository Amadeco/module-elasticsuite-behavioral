<?php
/**
 * Amadeco_ElasticSuiteBehavioral
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @author    Amadeco Core Team
 */
declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Console\Command;

use Amadeco\ElasticSuiteBehavioral\Api\BehavioralServiceInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CalculateScoreCommand
 *
 * CLI Command to manually trigger the Behavioral Analysis process.
 * Usage: bin/magento behavioral:calculate
 */
class CalculateScoreCommand extends Command
{
    /**
     * @var string Command name
     */
    private const COMMAND_NAME = 'behavioral:calculate';

    /**
     * @param BehavioralServiceInterface $service Service contract for business logic.
     * @param State                      $state   App state to emulate admin scope.
     */
    public function __construct(
        private readonly BehavioralServiceInterface $service,
        private readonly State $state
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Triggers the behavioral score calculation and index invalidation manually.')
            ->setHelp($this->getCommandHelp());

        parent::configure();
    }

    /**
     * Execute the behavioral analysis command.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // 1. Safe Area Code Setting
            try {
                $this->state->setAreaCode(Area::AREA_ADMINHTML);
            } catch (LocalizedException $e) {
                // Area code is already set; proceed safely.
            }

            $output->writeln('<info>Starting Behavioral Analysis...</info>');
            $start = microtime(true);

            // 2. Execute Service Contract
            $this->service->execute();

            $duration = round(microtime(true) - $start, 2);
            $output->writeln("<info>Success: Behavioral scores calculated in {$duration}s.</info>");
            $output->writeln('<comment>Note: The "catalog_product" index has been invalidated. Run indexer:reindex if needed.</comment>');

            return Cli::RETURN_SUCCESS;

        } catch (LocalizedException $e) {
            $output->writeln('<error>Magento Error: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>System Error: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Get command help text
     *
     * @return string
     */
    private function getCommandHelp(): string
    {
        return <<<HELP
The <info>%command.name%</info> command triggers the behavioral analysis calculation engine.

<comment>Basic usage:</comment>
  <info>bin/magento %command.name%</info>

This process will:
1. Aggregate View and Click data from ElasticSuite Tracker.
2. Aggregate Sales and Revenue data from Magento Sales.
3. Calculate a normalized score (0-100) based on configured weights.
4. Update the 'behavioral_score' attribute for all products.
5. Invalidate the 'catalog_product' index to reflect changes.

<comment>Note:</comment> This operation can be resource-intensive on large catalogs.
HELP;
    }
}