<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Service\Config as CoreConfig;
use BoldMinded\DexterCore\Service\ConfigFile;

/**
 * Bridges the Statamic-shaped config (indexes → searchables → fields) into the
 * flat structure dexter-core's Config expects.
 *
 * Core reads scope-keyed values:
 *
 *   indices.entries.<type>       => index name
 *   indexableFields.entries      => field list
 *   indexableFields.entries[<type>]
 *
 * Statamic's config nests fields under each searchable instead, which scopes
 * them correctly per content type. This class translates one into the other so
 * core's pipelines and commands run unmodified.
 */
class CoreConfigAdapter
{
    /**
     * Statamic searchable prefix => dexter-core scope.
     */
    private const SCOPE_MAP = [
        IndexConfig::TYPE_COLLECTION => 'entries',
        IndexConfig::TYPE_TAXONOMY => 'categories',
        IndexConfig::TYPE_ASSETS => 'files',
        IndexConfig::TYPE_USERS => 'users',
    ];

    public function __construct(private IndexConfig $config)
    {
    }

    public static function make(): self
    {
        return new self(IndexConfig::make());
    }

    /**
     * A dexter-core ConfigInterface built from the Statamic-shaped config.
     */
    public function toCoreConfig(): ConfigInterface
    {
        // Core's Config merges a defaults file with user config. The addon has
        // no separate defaults file, so point ConfigFile at a directory with
        // none and supply everything as user config.
        return new CoreConfig(
            new ConfigFile(__DIR__ . '/../../config/core/'),
            $this->toCoreArray()
        );
    }

    /**
     * The translated array, useful on its own for inspection and testing.
     */
    public function toCoreArray(): array
    {
        $indices = [];
        $indexableFields = [];

        foreach ($this->config->searchables() as $searchable) {
            if ($searchable['error'] !== null) {
                continue;
            }

            $scope = self::SCOPE_MAP[$searchable['type']] ?? null;

            if ($scope === null) {
                continue;
            }

            $type = $this->coreType($searchable);

            $indices[$scope][$type] = $searchable['index'];

            if ($searchable['fields'] !== []) {
                $indexableFields[$scope . '[' . $type . ']'] = $searchable['fields'];
            }
        }

        return array_merge($this->passthrough(), [
            'env' => $this->rawConfig('env', 'local'),
            'suffix' => $this->rawConfig('suffix', ''),
            'searchLimit' => $this->config->searchLimit(),
            'primaryKey' => $this->rawConfig('primaryKey', 'objectID'),
            'includeFullText' => (bool) $this->rawConfig('includeFullText', true),
            'statuses' => $this->rawConfig('statuses', ['published']),
            'stopWords' => $this->rawConfig('stop_words', []),
            'indices' => $indices,
            'indexableFields' => $indexableFields,
        ]);
    }

    /**
     * Config keys dexter-core reads verbatim.
     *
     * These keep core's own camelCase names in the Statamic config rather than
     * being renamed and translated: core reads them by literal string, several
     * with dot paths built at runtime (`$provider . '.key'`), so a snake_case
     * alias here would have to be unpicked in three places and would break the
     * moment core added a key. Anything present is forwarded as-is.
     *
     * @return array<string, mixed>
     */
    private function passthrough(): array
    {
        $keys = [
            'aiProvider',
            'assetUrls',
            'enableAdvancedSearch',
            'enableSemanticSearch',
            'embeddingProvider',
            'localHostSuffixes',
            'maxInlineImageBytes',
            'minimumRankingScore',
            'openAi',
            'openRouter',
            'parseDocumentContents',
            'parseImageContents',
        ];

        $forwarded = [];

        foreach ($keys as $key) {
            $value = config('dexter.' . $key);

            if ($value !== null) {
                $forwarded[$key] = $value;
            }
        }

        // Core reads algolia.mode when semantic search flips it to
        // neuralSearch, so the driver block has to be reachable by its own
        // name as well as through `drivers`.
        $algolia = config('dexter.drivers.algolia');

        if (is_array($algolia)) {
            $forwarded['algolia'] = $algolia;
        }

        return $forwarded;
    }

    /**
     * Core identifies content types by a prefixed key. EE used numeric ids
     * because channel names change; Statamic handles are the stable reference
     * available here, so they are used directly.
     */
    private function coreType(array $searchable): string
    {
        return match ($searchable['type']) {
            IndexConfig::TYPE_COLLECTION => 'collection_' . $searchable['handle'],
            IndexConfig::TYPE_TAXONOMY => 'taxonomy_' . $searchable['handle'],
            IndexConfig::TYPE_ASSETS => 'container_' . $searchable['handle'],
            IndexConfig::TYPE_USERS => 'users',
            default => $searchable['handle'],
        };
    }

    private function rawConfig(string $key, mixed $default): mixed
    {
        return config('dexter.' . $key, $default);
    }
}
