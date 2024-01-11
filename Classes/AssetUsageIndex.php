<?php
declare(strict_types=1);

namespace Wwwision\AssetUsage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Model\ResourceBasedInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Utility\Exception\InvalidTypeException;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\TypeHandling;
use RuntimeException;
use Stringable;
use Throwable;
use Traversable;
use Wwwision\AssetUsage\Model\AssetUsage;
use Wwwision\AssetUsage\Model\Node;
use function array_diff;
use function array_map;
use function array_merge;
use function get_debug_type;
use function in_array;
use function is_subclass_of;
use function json_decode;
use function preg_match_all;
use function sprintf;
use const JSON_THROW_ON_ERROR;
use const PREG_SET_ORDER;

/**
 * @Flow\Proxy("singleton")
 */
final class AssetUsageIndex implements \Countable
{
    private const TABLE_NAME = 'wwwision_assetusage';

    public const EVENT_PROGRESS = 'progress';
    public const EVENT_USAGE_ADDED = 'usageAdded';
    public const EVENT_USAGE_REMOVED = 'usageRemoved';
    public const EVENT_ERROR = 'error';

    /**
     * @var array<string, array<callable>>
     */
    private array $eventHandlers = [];

    private Connection $connection;
    private NodeTypeManager $nodeTypeManager;
    private PersistenceManager $persistenceManager;
    private AssetRepository $assetRepository;

    public function __construct(Connection $connection, NodeTypeManager $nodeTypeManager, PersistenceManager $persistenceManager, AssetRepository $assetRepository)
    {
        $this->connection = $connection;
        $this->nodeTypeManager = $nodeTypeManager;
        $this->persistenceManager = $persistenceManager;
        $this->assetRepository = $assetRepository;
    }

    /**
     * @param string $event one of the EVENT_* constants
     */
    public function on(string $event, callable $callback): void
    {
        if (!isset($this->eventHandlers[$event])) {
            $this->eventHandlers[$event] = [];
        }
        $this->eventHandlers[$event][] = $callback;
    }

    public function updateUsagesForNode(Node $node): void
    {
        try {
            $this->connection->transactional(function () use ($node) {
                $storedAssetIds = $this->findAssetIdsByNode($node);
                $extractedAssetIds = $this->extractAssetIds($node);
                $removedAssetIds = array_diff($storedAssetIds, $extractedAssetIds);
                $addedAssetIds = array_diff($extractedAssetIds, $storedAssetIds);
                if ($addedAssetIds === [] && $removedAssetIds === []) {
                    return;
                }
                foreach ($removedAssetIds as $assetId) {
                    $this->deleteRows(['asset_id' => $assetId, 'node_id' => $node->id, 'workspace' => $node->workspaceName, 'dimensionshash' => $node->dimensionsHash]);
                    $this->dispatch(self::EVENT_USAGE_REMOVED, $assetId, $node);
                }
                foreach ($addedAssetIds as $assetId) {
                    $this->addUsageForNode($assetId, $node);
                    $this->dispatch(self::EVENT_USAGE_ADDED, $assetId, $node);
                }
            });
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('Failed to update usages for node "%s": %s', $node->id, $e->getMessage()), 1685719358, $e);
        }
    }

    public function deleteUsagesForNode(Node $node): void
    {
        $this->deleteRows(['node_id' => $node->id, 'workspace' => $node->workspaceName, 'dimensionshash' => $node->dimensionsHash,]);
    }

    public function deleteByAsset(AssetInterface $asset): void
    {
        $assetId = $this->getAssetId($asset);
        $this->deleteRows(['asset_id' => $assetId]);
        if ($asset instanceof AssetVariantInterface && $asset->getOriginalAsset()) {
            $this->deleteByAsset($asset->getOriginalAsset());
        }
    }


    public function deleteAll(): void
    {
        try {
            $this->connection->executeQuery('TRUNCATE TABLE ' . self::TABLE_NAME);
        } catch (DbalException $e) {
            throw new RuntimeException(sprintf('Failed to truncate table "%s": %s', self::TABLE_NAME, $e->getMessage()), 1685700130, $e);
        }
    }

    public function update(int $offset, int $limit = null): void
    {
        $query = 'SELECT persistence_object_identifier, workspace, identifier, nodetype, properties, dimensionshash FROM neos_contentrepository_domain_model_nodedata WHERE removed = 0';
        if ($limit !== null) {
            $query .= ' LIMIT ' . $limit;
        }
        if ($offset !== 0) {
            $query .= ' OFFSET ' . $offset;
        }
        try {
            $nodeDataIterator = $this->connection->executeQuery($query)->iterateAssociative();
        } catch (DbalException $e) {
            throw new RuntimeException(sprintf('Failed to load node data records: %s', $e->getMessage()), 1685720414, $e);
        }
        foreach ($nodeDataIterator as $index => $nodeDataRow) {
            try {
                $nodeType = $this->nodeTypeManager->getNodeType($nodeDataRow['nodetype']);
                $properties = json_decode($nodeDataRow['properties'], true, 512, JSON_THROW_ON_ERROR);
                $node = new Node($nodeDataRow['identifier'], $nodeDataRow['workspace'] ?? 'live', $nodeDataRow['dimensionshash'], $nodeType, $properties);
                $this->updateUsagesForNode($node);
            } catch (Throwable $exception) {
                $this->dispatch(self::EVENT_ERROR, sprintf('Error while processing node "%s": %s', $nodeDataRow['identifier'], $exception->getMessage()));
            }
            $this->dispatch(self::EVENT_PROGRESS, $index);
        }
    }

    public function countNodes(): int
    {
        try {
            return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM neos_contentrepository_domain_model_nodedata WHERE removed = 0');
        } catch (DbalException $e) {
            throw new RuntimeException(sprintf('Failed to count node data records: %s', $e->getMessage()), 1685720457, $e);
        }
    }

    public function count(): int
    {

        return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE_NAME);
    }

    /**
     * @param array<string> $assetIds
     * @throws DbalException
     */
    public function countByAssetIds(array $assetIds): int
    {
        return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE_NAME . ' WHERE asset_id IN (:assetIds)', ['assetIds' => $assetIds], ['assetIds' => Connection::PARAM_STR_ARRAY]);
    }

    /**
     * @param array<string> $assetIds
     * @return Traversable<AssetUsage>
     * @throws RuntimeException|DbalException
     */
    public function findByAssetIds(array $assetIds): Traversable
    {
        foreach ($this->connection->iterateAssociative('SELECT * FROM ' . self::TABLE_NAME . ' WHERE asset_id IN (:assetIds)', ['assetIds' => $assetIds], ['assetIds' => Connection::PARAM_STR_ARRAY]) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException(sprintf('Expected instance of array, got %s', get_debug_type($row)), 1685687541);
            }
            yield new AssetUsage($row['asset_id'], $row['node_id'], $row['workspace'], $row['dimensionshash'], $row['nodetype']);
        }
    }

    /**
     * @param string $type
     * @param mixed $value
     * @return array<string>
     */
    public function extractAssetIdsFromPropertyValue(string $type, $value): array
    {
        if ($value instanceof ResourceBasedInterface) {
            return [$this->getAssetId($value)];
        }
        if (is_string($value) || $value instanceof Stringable) {
            preg_match_all('/asset:\/\/(?<assetId>[\w-]*)/i', (string)$value, $matches, PREG_SET_ORDER);
            return array_map(static fn (array $match) => $match['assetId'], $matches);
        }
        if (is_subclass_of($type, ResourceBasedInterface::class)) {
            return isset($value['__identifier']) ? [$value['__identifier']] : [];
        }

        // Collection type?
        /** @var array{type: string, elementType: string|null, nullable: bool} $parsedType */
        try {
            $parsedType = TypeHandling::parseType($type);
        } catch (InvalidTypeException $e) {
            throw new RuntimeException(sprintf('Failed to parse type "%s": %s', $type, $e->getMessage()), 1685700706, $e);
        }
        if ($parsedType['elementType'] === null) {
            return [];
        }
        if (!is_subclass_of($parsedType['elementType'], ResourceBasedInterface::class) && !is_subclass_of($parsedType['elementType'], Stringable::class)) {
            return [];
        }
        /** @var array<array<string>> $assetIds */
        $assetIds = [];
        foreach ($value as $elementValue) {
            $assetIds[] = $this->extractAssetIdsFromPropertyValue($parsedType['elementType'], $elementValue);
        }
        return array_merge(...$assetIds);
    }

    // -------------------------------------------


    private function addUsageForNode(string $assetId, Node $node): void
    {
        try {
            $this->connection->insert(self::TABLE_NAME, ['asset_id' => $assetId, 'node_id' => $node->id, 'workspace' => $node->workspaceName, 'dimensionshash' => $node->dimensionsHash, 'nodetype' => $node->nodeType->getName()]);
        } catch (UniqueConstraintViolationException $_) {
        } catch (DbalException $e) {
            throw new RuntimeException(sprintf('Failed to add usage for asset "%s": %s', $assetId, $e->getMessage()), 1685700187, $e);
        }
    }

    private function extractAssetIds(Node $node): array
    {
        // HACK: The next line is required in order to trigger the node type initialization
        $node->nodeType->getLabel();
        /** @var array<string> $assetIds */
        $assetIds = [];
        foreach ($node->properties as $propertyName => $propertyValue) {
            if ($propertyValue === null || !$node->nodeType->hasConfiguration('properties.' . $propertyName)) {
                continue;
            }
            $propertyType = $node->nodeType->getPropertyType($propertyName);
            try {
                $assetIds[] = $this->extractAssetIdsFromPropertyValue($propertyType, $propertyValue);
            } catch (Throwable $exception) {
                throw new RuntimeException(sprintf('Failed to extract asset ids from property "%s": %s', $propertyName, $exception->getMessage()));
            }
        }
        $processedAssetIds = [];
        foreach (array_merge(...$assetIds) as $assetId) {
            if (in_array($assetId, $processedAssetIds, true)) {
                continue;
            }
            $processedAssetIds[] = $assetId;
            $asset = $this->assetRepository->findByIdentifier($assetId);
            if ($asset instanceof AssetVariantInterface && $asset->getOriginalAsset()) {
                $originalAssetId = $this->getAssetId($asset->getOriginalAsset());
                if (!in_array($originalAssetId, $processedAssetIds, true)) {
                    $processedAssetIds[] = $originalAssetId;
                }
            }
        }
        return $processedAssetIds;
    }

    private function dispatch(string $event, ...$arguments): void
    {
        foreach ($this->eventHandlers[$event] ?? [] as $callback) {
            $callback(...$arguments);
        }
    }

    private function deleteRows(array $criteria): void
    {
        try {
            $this->connection->delete(self::TABLE_NAME, $criteria);
        } catch (DbalException $e) {
            throw new RuntimeException(sprintf('Failed to delete rows from table "%s": %s', self::TABLE_NAME, $e->getMessage()), 1685700072, $e);
        }
    }

    /**
     * @return array<string>
     */
    private function findAssetIdsByNode(Node $node): array
    {
        try {
            return $this->connection->fetchFirstColumn('SELECT asset_id FROM ' . self::TABLE_NAME . ' WHERE node_id = :nodeId AND workspace = :workspaceName AND dimensionshash = :dimensionsHash', ['nodeId' => $node->id, 'workspaceName' => $node->workspaceName, 'dimensionsHash' => $node->dimensionsHash,]);
        } catch (DbalException $e) {
            throw new RuntimeException(sprintf('Failed to load used asset ids for node "%s": %s', $node->id, $e->getMessage()), 1685717889, $e);
        }
    }

    private function getAssetId(ResourceBasedInterface $asset): string
    {
        try {
            return $this->persistenceManager->getIdentifierByObject($asset);
        } catch (PropertyNotAccessibleException $e) {
            throw new RuntimeException(sprintf('Failed to determine id for asset "%s": %s', get_debug_type($asset), $e->getMessage()), 1685699939, $e);
        }
    }
}
