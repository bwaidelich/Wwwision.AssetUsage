<?php
declare(strict_types=1);

namespace Wwwision\AssetUsage;

use Doctrine\DBAL\Connection;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Strategy\AssetUsageStrategyInterface;
use Neos\Neos\Domain\Model\Dto\AssetUsageInNodeProperties;
use Wwwision\AssetUsage\Model\AssetUsage;
use function array_map;
use function iterator_to_array;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * @Flow\Proxy("singleton")
 */
final class AssetUsageInNodePropertiesStrategy implements AssetUsageStrategyInterface
{

    private AssetUsageIndex $assetUsageIndex;
    private Connection $connection;
    private PersistenceManager $persistenceManager;

    public function __construct(AssetUsageIndex $assetUsageIndex, Connection $connection, PersistenceManager $persistenceManager)
    {
        $this->assetUsageIndex = $assetUsageIndex;
        $this->connection = $connection;
        $this->persistenceManager = $persistenceManager;
    }

    public function isInUse(AssetInterface $asset): bool
    {
        return $this->getUsageCount($asset) > 0;
    }

    public function getUsageCount(AssetInterface $asset): int
    {
        $assetId = $this->persistenceManager->getIdentifierByObject($asset);
        return $this->assetUsageIndex->countByAssetId($assetId);
    }

    /**
     * @return array<AssetUsageInNodeProperties>
     */
    public function getUsageReferences(AssetInterface $asset): array
    {
        $assetId = $this->persistenceManager->getIdentifierByObject($asset);
        return array_map(
            fn (AssetUsage $usage) => $this->transformUsage($asset, $usage),
            iterator_to_array($this->assetUsageIndex->findByAssetId($assetId))
        );
    }

    private function transformUsage(AssetInterface $asset, AssetUsage $usage): AssetUsageInNodeProperties
    {
        $dimensionsSerialized = $this->connection->fetchOne('SELECT dimensionvalues FROM neos_contentrepository_domain_model_nodedata WHERE identifier = :nodeId AND workspace = :workspaceName AND dimensionshash = :dimensionsHash LIMIT 1', [
            'nodeId' => $usage->nodeId,
            'workspaceName' => $usage->workspaceName,
            'dimensionsHash' => $usage->dimensionsHash,
        ]);
        $dimensionValues = json_decode($dimensionsSerialized, true, 512, JSON_THROW_ON_ERROR);
        return new AssetUsageInNodeProperties($asset, $usage->nodeId, $usage->workspaceName, $dimensionValues, $usage->nodeTypeName);
    }
}
