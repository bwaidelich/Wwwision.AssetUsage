<?php
declare(strict_types=1);

namespace Wwwision\AssetUsage\Command;

use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Symfony\Component\Console\Helper\ProgressBar;
use Wwwision\AssetUsage\AssetUsageIndex;
use function count;

final class AssetUsageCommandController extends CommandController
{
    private AssetUsageIndex $assetUsageIndex;

    public function __construct(AssetUsageIndex $assetUsageIndex)
    {
        parent::__construct();
        $this->assetUsageIndex = $assetUsageIndex;
    }

    /**
     * Iterate through all nodes and extract the referenced asset ids to build up the usage index
     *
     * @param int $offset If specified, the node data table is selected from with this offset
     * @param int|null $limit If specified, only the given number of node data records will be processed
     * @throws StopCommandException
     */
    public function updateIndexCommand(int $offset = 0, int $limit = null): void
    {
        $errors = [];
        $numberOfAddedUsages = 0;
        $numberOfRemovedUsages = 0;
        $this->assetUsageIndex->on(AssetUsageIndex::EVENT_ERROR, static function (string $errorMessage) use (&$errors) {
            $errors[] = $errorMessage;
        });
        $this->assetUsageIndex->on(AssetUsageIndex::EVENT_USAGE_ADDED, static function () use (&$numberOfAddedUsages) {
            $numberOfAddedUsages ++;
        });
        $this->assetUsageIndex->on(AssetUsageIndex::EVENT_USAGE_REMOVED, static function () use (&$numberOfRemovedUsages) {
            $numberOfRemovedUsages ++;
        });

        $numberOfNodes = $this->assetUsageIndex->countNodes();
        $this->outputLine('Looking for asset usages in <b>%d</b> node%s', [$numberOfNodes, $numberOfNodes === 1 ? '' : 's']);

        $progressBar = new ProgressBar($this->output->getOutput(), $numberOfNodes);
        $progressBar->setFormat(ProgressBar::FORMAT_DEBUG);
        $this->assetUsageIndex->on(AssetUsageIndex::EVENT_PROGRESS, static fn() => $progressBar->advance());
        $this->assetUsageIndex->update($offset, $limit);
        $progressBar->finish();

        $this->outputLine();
        $this->outputLine('Added <b>%d</b> and removed <b>%d</b> usage%s', [$numberOfAddedUsages, $numberOfRemovedUsages, $numberOfAddedUsages + $numberOfRemovedUsages === 1 ? '' : 's']);
        $numberOfErrors = count($errors);
        if ($numberOfErrors === 0) {
            $this->outputLine('<success>Finished without errors</success>');
        } else {
            $this->outputLine('<error>Encountered <b>%d</b> error%s</error>', [$numberOfErrors, $numberOfErrors === 1 ? '' : 's']);
            foreach ($errors as $error) {
                $this->outputLine('  %s', [$error]);
            }
            $this->quit(1);
        }
    }

    /**
     * @param bool $force
     * @return void
     */
    public function resetIndexCommand(bool $force = false): void
    {
        $numberOfUsages = $this->assetUsageIndex->count();
        if ($numberOfUsages === 0) {
            $this->outputLine('There are no entries in the asset usage index');
            return;
        }
        if ($force !== true) {
            if (!$this->output->askConfirmation(sprintf('Do you really want to remove %s entr%s from the usage table? (skip this confirmation with --force) ', $numberOfUsages, $numberOfUsages === 1 ? 'y' : 'ies'), false)) {
                $this->outputLine('Cancelled');
                return;
            }
        }
        $this->assetUsageIndex->deleteAll();
        $this->outputLine('<success>Removed %d entr%s from the asset usage index</success>', [$numberOfUsages, $numberOfUsages === 1 ? 'y' : 'ies']);
    }
}