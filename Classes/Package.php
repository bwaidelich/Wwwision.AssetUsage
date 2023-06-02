<?php
declare(strict_types=1);
namespace Wwwision\AssetUsage;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Media\Domain\Service\AssetService;
use Neos\Neos\Service\PublishingService;

final class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(AssetService::class, 'assetRemoved', ContentRepositoryIntegration::class, 'onAssetRemoved');
        $dispatcher->connect(Node::class, 'nodePropertyChanged', ContentRepositoryIntegration::class, 'onNodePropertyChanged');
        $dispatcher->connect(Node::class, 'nodeRemoved', ContentRepositoryIntegration::class, 'onNodeRemoved');
        $dispatcher->connect(Node::class, 'nodeAdded', ContentRepositoryIntegration::class, 'onNodeAdded');
        $dispatcher->connect(PublishingService::class, 'nodeDiscarded', ContentRepositoryIntegration::class, 'onNodeDiscarded');
        $dispatcher->connect(Workspace::class, 'afterNodePublishing', ContentRepositoryIntegration::class, 'onAfterNodePublishing');
    }
}