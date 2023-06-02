<?php
declare(strict_types=1);

namespace Wwwision\AssetUsage\Model;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class Node
{

    public string $id;
    public string $workspaceName;
    public string $dimensionsHash;
    public NodeType $nodeType;
    public array $properties;

    public function __construct(string $id, string $workspaceName, string $dimensionsHash, NodeType $nodeType, array $properties)
    {
        $this->id = $id;
        $this->workspaceName = $workspaceName;
        $this->dimensionsHash = $dimensionsHash;
        $this->nodeType = $nodeType;
        $this->properties = $properties;
    }

    public function hash(): string
    {
        return md5($this->id . '|' . $this->workspaceName . '|' . $this->dimensionsHash);
    }

}