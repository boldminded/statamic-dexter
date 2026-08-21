<?php

namespace BoldMinded\Dexter\Console;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\IndexableFactory;
use BoldMinded\Dexter\Service\FieldMatcher;
use BoldMinded\Dexter\Service\IndexConfig;
use Illuminate\Console\Command;

/**
 * Prints the document Dexter would send to the search provider, without
 * contacting one. Runs the real Indexable adapters and the real field map.
 */
class PreviewCommand extends Command
{
    protected $signature = 'dexter:preview
                            {--index= : Only preview this index}
                            {--searchable= : Only preview this searchable, e.g. collection:pages}
                            {--limit=2 : Items per searchable}';

    protected $description = 'Preview the documents Dexter would index';

    public function handle(IndexableFactory $factory, IndexConfig $config): int
    {
        $limit = (int) $this->option('limit');
        $onlyIndex = $this->option('index');
        $onlySearchable = $this->option('searchable');

        /** @var ConfigInterface $core */
        $core = app()->make(ConfigInterface::class);

        foreach ($config->indexNames() as $indexName) {
            if ($onlyIndex !== null && $indexName !== $onlyIndex) {
                continue;
            }

            $this->newLine();
            $this->line('<fg=black;bg=cyan> INDEX </> <options=bold>' . $indexName . '</> '
                . '<fg=gray>driver=' . $config->driverFor($indexName)
                . ' resolves to ' . $config->resolveIndexName($indexName) . '</>');

            foreach ($config->searchables($indexName) as $searchable) {
                if ($onlySearchable !== null && $searchable['key'] !== $onlySearchable) {
                    continue;
                }

                $this->previewSearchable($factory, $core, $searchable, $limit);
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function previewSearchable(
        IndexableFactory $factory,
        ConfigInterface $core,
        array $searchable,
        int $limit
    ): void {
        $this->newLine();
        $this->line('  <options=bold>' . $searchable['key'] . '</>');

        if ($searchable['error'] !== null) {
            $this->line('    <fg=red>' . $searchable['error'] . '</>');

            return;
        }

        $commands = app()->make(\BoldMinded\Dexter\Service\Indexer\CommandFactory::class);
        $found = false;

        foreach ($factory->stream($searchable, $limit) as $indexable) {
            $found = true;
            $this->previewItem($commands, $indexable);
        }

        if (! $found) {
            $this->line('    <fg=gray>no content found</>');
        }
    }

    /**
     * Field map for this searchable, read back through core's Config so the
     * preview exercises the same path indexing will.
     */
    private function rulesFor(ConfigInterface $core, array $searchable): array
    {
        $scope = match ($searchable['type']) {
            IndexConfig::TYPE_COLLECTION => 'entries',
            IndexConfig::TYPE_TAXONOMY => 'categories',
            IndexConfig::TYPE_ASSETS => 'files',
            IndexConfig::TYPE_USERS => 'users',
            default => null,
        };

        if ($scope === null) {
            return $searchable['fields'];
        }

        $type = match ($searchable['type']) {
            IndexConfig::TYPE_COLLECTION => 'collection_' . $searchable['handle'],
            IndexConfig::TYPE_TAXONOMY => 'taxonomy_' . $searchable['handle'],
            IndexConfig::TYPE_ASSETS => 'container_' . $searchable['handle'],
            default => 'users',
        };

        return $core->get('indexableFields.' . $scope . '[' . $type . ']')
            ?? $searchable['fields'];
    }

    /**
     * Runs the real core IndexCommand, so what prints is the document that
     * would be sent to the provider -- pipelines included.
     */
    private function previewItem(
        \BoldMinded\Dexter\Service\Indexer\CommandFactory $commands,
        IndexableInterface $indexable
    ): void {
        $raw = $indexable->getValues();
        $command = $commands->make($indexable);

        $this->newLine();

        if ($command === null) {
            $this->line(sprintf(
                '    <fg=yellow>%s</> <fg=red>no index configured for this source</>',
                $this->label($indexable, $raw)
            ));

            return;
        }

        $document = $command->execute();

        $this->line(sprintf(
            '    <fg=yellow>%s</> <fg=gray>(%d of %d values kept, id=%s)</>',
            $this->label($indexable, $raw),
            count(FieldMatcher::flatten($document)),
            count(FieldMatcher::flatten($raw)),
            $command->getUniqueId()
        ));

        if ($document === []) {
            $this->line('      <fg=gray>skipped by pipeline (status, or nothing matched)</>');

            return;
        }

        foreach (FieldMatcher::flatten($document) as $path => $value) {
            $label = $path === '__full_text' ? '<fg=magenta>' . $path . '</>' : '<fg=green>' . $path . '</>';
            $this->line('      ' . $label . ' = ' . $this->preview($value));
        }
    }

    private function label(IndexableInterface $indexable, array $values): string
    {
        return (string) ($values['title'] ?? $values['name'] ?? $values['path'] ?? $indexable->getId());
    }

    private function preview(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            $value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '?', $value));
        }

        $string = trim(preg_replace('/\s+/', ' ', (string) $value));

        return mb_strlen($string) > 70
            ? mb_substr($string, 0, 70) . '…'
            : $string;
    }
}
