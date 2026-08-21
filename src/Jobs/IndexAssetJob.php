<?php

namespace BoldMinded\Dexter\Jobs;

use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\IndexableAsset;
use Statamic\Facades\Asset;

class IndexAssetJob extends AbstractIndexJob
{
    protected function resolve(): ?IndexableInterface
    {
        $asset = Asset::find($this->payload['id'] ?? null);

        return $asset ? new IndexableAsset($asset) : null;
    }
}
