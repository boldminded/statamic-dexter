<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\IndexableFileInterface;
use BoldMinded\DexterCore\Service\AssetUrl;
use Statamic\Contracts\Assets\Asset;
use Statamic\Fields\Blueprint;

/**
 * Also implements IndexableFileInterface, which is what lets core's document
 * and image parsers (PDF, Word, CSV, XML, image) and the AI describe pipeline
 * operate on Statamic assets.
 */
class IndexableAsset extends AbstractIndexable implements IndexableFileInterface
{
    public function __construct(private Asset $asset)
    {
    }

    public function getScope(): string
    {
        return 'files';
    }

    public function getTypes(): array
    {
        return ['container_' . $this->asset->containerHandle()];
    }

    public function getEntity(): Asset
    {
        return $this->asset;
    }

    public function getId(): int|string
    {
        return $this->asset->id();
    }

    public function getUniqueId(): string
    {
        return 'asset_' . str_replace(['/', '.', '::'], '_', $this->asset->id());
    }

    protected function rawData(): array
    {
        return $this->asset->data()->all();
    }

    protected function blueprint(): ?Blueprint
    {
        return $this->asset->blueprint();
    }

    protected function systemValues(): array
    {
        return array_filter([
            'id' => $this->asset->id(),
            'reference' => $this->asset->reference(),
            'path' => $this->asset->path(),
            'filename' => $this->asset->filename(),
            'basename' => $this->asset->basename(),
            'extension' => $this->asset->extension(),
            'container' => $this->asset->containerHandle(),
            // Matches the assetUrls setting so a URL means the same thing
            // whether it came from an assets field or from the asset itself.
            'url' => AssetUrl::pick(
                AssetUrl::isValid(config('dexter.assetUrls'))
                    ? config('dexter.assetUrls')
                    : AssetUrl::DEFAULT,
                $this->asset->path(),
                $this->asset->url(),
                $this->asset->absoluteUrl(),
            ),
            'size' => $this->asset->size(),
            'is_image' => $this->asset->isImage(),
        ], fn ($value) => $value !== null);
    }

    public function getDefaultFields(): array
    {
        return ['id', 'reference', 'path', 'filename', 'extension', 'container', 'url'];
    }

    // IndexableFileInterface

    public function getMimeType(): string
    {
        return $this->asset->mimeType() ?? '';
    }

    /**
     * The address an AI provider is given for this asset.
     *
     * Returned as-is: core inlines images on hosts a provider cannot reach,
     * so unreachable URLs are handled there rather than per CMS.
     */
    public function getAbsoluteUrl(): string
    {
        return (string) ($this->asset->absoluteUrl() ?? '');
    }

    /**
     * Core's parsers read from the local filesystem. Statamic's resolvedPath()
     * combines the container disk path with the asset path, which is a local
     * path only for local disks -- assets on S3 and similar will not be
     * readable, so callers must handle failure rather than assume a file.
     */
    public function getAbsolutePath(): string
    {
        try {
            return (string) $this->asset->resolvedPath();
        } catch (\Throwable) {
            return '';
        }
    }

    public function isImage(): bool
    {
        return $this->asset->isImage();
    }
}
