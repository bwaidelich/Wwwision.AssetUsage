<?php
declare(strict_types=1);

namespace Wwwision\AssetUsage;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Utility;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;
use Wwwision\AssetUsage\Model\Node;

/**
 * @Flow\Proxy("singleton")
 */
final class ContentRepositoryIntegration
{
    private AssetUsageIndex $assetUsageIndex;

    public function __construct(AssetUsageIndex $assetUsageIndex)
    {
        $this->assetUsageIndex = $assetUsageIndex;
    }

    public function onAssetRemoved(AssetInterface $asset): void
    {
        $this->assetUsageIndex->deleteByAsset($asset);
    }

    /**
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    public function onNodePropertyChanged(NodeInterface $node, string $propertyName, $oldValue, $newValue): void
    {
        if ($newValue === $oldValue) {
            return;
        }
        // HACK: The next line is required in order to trigger the node type initialization
        $node->getNodeType()->getLabel();
        $propertyType = $node->getNodeType()->getPropertyType($propertyName);
        $assetIdsInOldValue = $this->assetUsageIndex->extractAssetIdsFromPropertyValue($propertyType, $oldValue);
        $assetIdsInNewValue = $this->assetUsageIndex->extractAssetIdsFromPropertyValue($propertyType, $newValue);
        if ($assetIdsInNewValue === $assetIdsInOldValue) {
            return;
        }
        $this->assetUsageIndex->updateUsagesForNode(self::transformNode($node));
    }

    public function onNodeAdded(NodeInterface $node): void
    {
        $this->assetUsageIndex->updateUsagesForNode(self::transformNode($node));
    }

    public function onNodeRemoved(NodeInterface $node): void
    {
        $this->assetUsageIndex->deleteUsagesForNode(self::transformNode($node));
    }

    public function onNodeDiscarded(NodeInterface $node): void
    {
        $this->assetUsageIndex->updateUsagesForNode(self::transformNode($node));
    }

    public function onAfterNodePublishing(NodeInterface $node): void
    {
        if ($node->isRemoved()) {
            $this->assetUsageIndex->deleteUsagesForNode(self::transformNode($node));
        } else {
            $this->assetUsageIndex->updateUsagesForNode(self::transformNode($node));
        }
    }

    private static function transformNode(NodeInterface $node): Node
    {
        $dimensionValues = $node->getDimensions();
        return new Node($node->getIdentifier(), $node->getWorkspace()->getName(), Utility::sortDimensionValueArrayAndReturnDimensionsHash($dimensionValues), $node->getNodeType(), iterator_to_array($node->getProperties()));
    }
}
