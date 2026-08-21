<?php

namespace BoldMinded\Dexter;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\LoggerInterface;
use BoldMinded\DexterCore\Contracts\QueueInterface;
use BoldMinded\DexterCore\Contracts\TranslatorInterface;
use BoldMinded\Dexter\Service\CoreConfigAdapter;
use BoldMinded\Dexter\Service\DexterIndex;
use BoldMinded\Dexter\Service\Fields\FieldTypeFactory;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use BoldMinded\Dexter\Service\IndexableFactory;
use BoldMinded\Dexter\Service\IndexConfig;
use BoldMinded\Dexter\Service\Indexer\CommandFactory;
use BoldMinded\Dexter\Service\Indexer\ContentTypeFactory;
use BoldMinded\Dexter\Service\Indexer\Indexer;
use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Service\Indexer\Reindexer;
use BoldMinded\Dexter\Service\Logger;
use BoldMinded\Dexter\Service\Queue;
use BoldMinded\Dexter\Service\SearchFactory;
use BoldMinded\Dexter\Service\Searcher;
use BoldMinded\Dexter\Service\Translator;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Search;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__ . '/../routes/cp.php',
        'actions' => __DIR__ . '/../routes/actions.php',
    ];

    protected $tags = [
        Tags\DexterTags::class,
    ];

    protected $commands = [
        Console\PreviewCommand::class,
        Console\DoctorCommand::class,
        Console\ReindexCommand::class,
        Console\ClearCommand::class,
        Console\DeleteCommand::class,
    ];

    protected $viewNamespace = 'dexter';

    /**
     * Statamic publishes tagged assets after `please addon:install`. Without
     * this the config would have to be published by hand, which every install
     * needs to do before Dexter can index anything.
     */
    protected $publishAfterInstall = true;

    /**
     * Statamic's own config handling looks for config/{slug}.php and would
     * key this as `dexter-statamic`. Handled manually in bootAddon instead.
     */
    protected $config = false;

    public function bootAddon()
    {
        $this->mergeDexterConfig(__DIR__ . '/../config/dexter.php');

        // Published to config/dexter.php, not config/statamic/dexter.php:
        // this is Dexter's own config, not a Statamic subsystem's
        //
        // Statamic's own $config property is not used, because it derives the
        // filename from the addon slug -- config/dexter-statamic.php -- which
        // would make the key `dexter-statamic` and diverge from the other
        // builds for no gain.
        $this->publishes([
            __DIR__ . '/../config/dexter.php' => config_path('dexter.php'),
        ], 'dexter-config');

        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'dexter');

        Permissions::boot();

        $this->bootBindings();
        $this->bootNav();

        Event::subscribe(Listeners\UpdateIndex::class);

        $this->bootSearchDriver();
    }

    /**
     * Merge the packaged defaults under the published config, recursively.
     *
     * Laravel's mergeConfigFrom uses array_merge, which only merges the top
     * level. Every meaningful key here is nested -- openAi, parseImageContents,
     * drivers, indexes -- so a published file overriding one value inside a
     * block would silently drop that block's other defaults. Setting
     * openAi.model alone, for instance, would take maxTokens and temperature
     * with it.
     *
     * Lists are replaced rather than concatenated: a site listing its own
     * pipelines or statuses means those and no others, and appending would
     * make it impossible to remove a default.
     */
    private function mergeDexterConfig(string $path): void
    {
        // Skipped when the config is cached, matching Laravel: the cache
        // already holds the merged result.
        if ($this->app instanceof \Illuminate\Contracts\Foundation\CachesConfiguration
            && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set('dexter', $this->mergeRecursive(
            require $path,
            $config->get('dexter', [])
        ));
    }

    /**
     * @param  array<mixed>  $defaults
     * @param  array<mixed>  $published
     * @return array<mixed>
     */
    private function mergeRecursive(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $isAssociative = is_array($value) && $value !== [] && ! array_is_list($value);

            if ($isAssociative && isset($defaults[$key]) && is_array($defaults[$key])) {
                $defaults[$key] = $this->mergeRecursive($defaults[$key], $value);

                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * dexter-core is CMS-agnostic and depends on contracts, not concretes.
     * These bindings supply the Statamic implementations.
     */
    private function bootBindings(): void
    {
        // Both factories are stateless registries built once at boot: the
        // content types Dexter can index, and the fieldtype handlers that
        // extract their values. Shared so a bulk reindex does not rebuild
        // either one per item.
        $this->app->singleton(ContentTypeFactory::class);
        $this->app->singleton(FieldTypeFactory::class);

        $this->app->singleton(FieldValueWalker::class, fn ($app) => new FieldValueWalker(
            $app->make(FieldTypeFactory::class)
        ));

        $this->app->singleton(IndexableFactory::class, fn ($app) => new IndexableFactory(
            $app->make(ContentTypeFactory::class)
        ));

        $this->app->singleton(IndexConfig::class, fn () => IndexConfig::make());

        $this->app->singleton(CoreConfigAdapter::class, fn () => CoreConfigAdapter::make());

        $this->app->singleton(
            ConfigInterface::class,
            fn ($app) => $app->make(CoreConfigAdapter::class)->toCoreConfig()
        );

        $this->app->singleton(
            CommandFactory::class,
            fn ($app) => new CommandFactory($app->make(ConfigInterface::class))
        );

        $this->app->singleton(LoggerInterface::class, Logger::class);

        $this->app->singleton(SearchFactory::class, fn ($app) => new SearchFactory(
            $app->make(IndexConfig::class),
            $app->make(ConfigInterface::class),
            $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(Searcher::class, fn ($app) => new Searcher(
            $app->make(IndexConfig::class),
            $app->make(SearchFactory::class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->singleton(QueueInterface::class, Queue::class);
        $this->app->singleton(TranslatorInterface::class, Translator::class);

        $this->app->singleton(IndexerFactory::class, fn ($app) => new IndexerFactory(
            $app->make(IndexConfig::class),
            $app->make(ConfigInterface::class),
            $app->make(LoggerInterface::class),
            $app->make(QueueInterface::class),
            $app->make(TranslatorInterface::class),
        ));

        $this->app->singleton(Reindexer::class, fn ($app) => new Reindexer(
            $app->make(IndexConfig::class),
            $app->make(IndexableFactory::class),
            $app->make(Indexer::class),
        ));

        $this->app->singleton(Indexer::class, fn ($app) => new Indexer(
            $app->make(CommandFactory::class),
            $app->make(IndexerFactory::class),
            $app->make(ConfigInterface::class),
            $app->make(LoggerInterface::class),
        ));
    }

    /**
     * Register a read-only Statamic search driver backed by Dexter, so
     * {{ search }} and the CP can query indexes Dexter maintains.
     *
     * Opt-in per index: a site adds `'driver' => 'dexter'` to an entry in
     * config/statamic/search.php. Registering the driver alone changes
     * nothing.
     */
    private function bootSearchDriver(): void
    {
        Search::extend('dexter', function ($app, $config, $name, $locale = null) {
            return new DexterIndex($name, $config, $locale);
        });
    }

    private function bootNav(): void
    {
        Nav::extend(function ($nav) {
            $nav->tools('Dexter')
                ->route('dexter.index')
                ->icon('magnifying-glass')
                ->can(Permissions::VIEW);
        });
    }
}
