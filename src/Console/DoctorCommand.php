<?php

namespace BoldMinded\Dexter\Console;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\Dexter\Service\CoreConfigAdapter;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Console\Command;

/**
 * Reports whether dexter-core is wired in correctly. Contacts no network
 * services; this checks plumbing, not connectivity.
 */
class DoctorCommand extends Command
{
    protected $signature = 'dexter:doctor';

    protected $description = 'Check that dexter-core is correctly wired into Statamic';

    public function handle(): int
    {
        $ok = true;

        $this->newLine();
        $this->line('<options=bold>dexter-core</>');

        $ok = $this->check(
            'Core classes autoload',
            class_exists(\BoldMinded\DexterCore\Service\Config::class)
                && class_exists(\BoldMinded\DexterCore\Service\Indexer\Algolia::class)
                && class_exists(\BoldMinded\DexterCore\Service\Search\Meilisearch::class)
        ) && $ok;

        $ok = $this->check(
            'ConfigInterface resolves from the container',
            app()->make(ConfigInterface::class) instanceof ConfigInterface
        ) && $ok;

        $this->newLine();
        $this->line('<options=bold>HTTP client discovery</>');

        try {
            $client = get_class(Psr18ClientDiscovery::find());
            $ok = $this->check('PSR-18 client found', true, $client) && $ok;
        } catch (\Throwable $e) {
            $ok = $this->check('PSR-18 client found', false, $e->getMessage()) && $ok;
        }

        $ok = $this->check(
            'No redundant symfony/http-client',
            ! class_exists(\Symfony\Component\HttpClient\HttpClient::class),
            'Guzzle satisfies psr/http-client-implementation'
        ) && $ok;

        $this->newLine();
        $this->line('<options=bold>Providers</>');

        $indexConfig = \BoldMinded\Dexter\Service\IndexConfig::make();
        $factory = app()->make(\BoldMinded\Dexter\Service\Indexer\IndexerFactory::class);

        foreach ($indexConfig->indexNames() as $name) {
            $driver = $indexConfig->driverFor($name);
            $provider = $factory->make($name);
            $isDummy = $provider instanceof \BoldMinded\DexterCore\Service\Indexer\Dummy;

            $ok = $this->check(
                sprintf('%s → %s', $name, $indexConfig->resolveIndexName($name)),
                ! $isDummy,
                $isDummy
                    ? sprintf('driver=%s but no provider — check credentials', $driver ?? 'unset')
                    : sprintf('driver=%s, %s', $driver, class_basename($provider))
            ) && $ok;
        }

        $this->line(sprintf(
            '    <fg=gray>queue=%s  enabled=%s  primary key=%s</>',
            config('dexter.queue') ? 'on' : 'off',
            config('dexter.enabled', true) ? 'yes' : 'no',
            is_string(config('dexter.primaryKey')) ? config('dexter.primaryKey') : 'callable'
        ));

        $this->newLine();
        $this->line('<options=bold>Config translation</>');

        /** @var ConfigInterface $core */
        $core = app()->make(ConfigInterface::class);
        $adapter = app()->make(CoreConfigAdapter::class);
        $translated = $adapter->toCoreArray();

        foreach (['entries', 'categories', 'files', 'users'] as $scope) {
            $sources = $core->get('indices.' . $scope) ?? [];

            if ($sources === []) {
                continue;
            }

            foreach ($sources as $type => $indexName) {
                $fields = $translated['indexableFields'][$scope . '[' . $type . ']'] ?? [];
                $nested = count(array_filter($fields, fn ($f) => str_contains($f, '*')));

                $this->line(sprintf(
                    '  <fg=green>✓</> %-34s → <fg=yellow>%s</> <fg=gray>(%d fields, %d nested)</>',
                    $scope . '.' . $type,
                    $indexName,
                    count($fields),
                    $nested
                ));
            }
        }

        $orphans = \BoldMinded\Dexter\Service\IndexConfig::make()->orphans();

        if ($orphans !== []) {
            $this->newLine();
            foreach ($orphans as $orphan) {
                $this->line('  <fg=red>✗</> ' . $orphan['key'] . ' — ' . $orphan['error']);
            }
            $ok = false;
        }

        $this->newLine();
        $this->line($ok
            ? '<fg=black;bg=green> PASS </> dexter-core is wired in correctly.'
            : '<fg=white;bg=red> FAIL </> See failures above.');
        $this->newLine();

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function check(string $label, bool $passed, string $detail = ''): bool
    {
        $this->line(sprintf(
            '  %s %-42s %s',
            $passed ? '<fg=green>✓</>' : '<fg=red>✗</>',
            $label,
            $detail !== '' ? '<fg=gray>' . $detail . '</>' : ''
        ));

        return $passed;
    }
}
