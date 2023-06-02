<?php
declare(strict_types=1);

namespace Wwwision\AssetUsage\Model;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class AssetUsage
{

    public string $assetId;
    public string $nodeId;
    public string $workspaceName;
    public string $dimensionsHash;
    public string $nodeTypeName;

    public function __construct(string $assetId, string $nodeId, string $workspaceName, string $dimensionsHash, string $nodeTypeName)
    {
        $this->assetId = $assetId;
        $this->nodeId = $nodeId;
        $this->workspaceName = $workspaceName;
        $this->dimensionsHash = $dimensionsHash;
        $this->nodeTypeName = $nodeTypeName;
    }

}