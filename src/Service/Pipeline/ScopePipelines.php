<?php

namespace BoldMinded\Dexter\Service\Pipeline;

/**
 * The pipeline list for a scope: built-in defaults, then the site's additions.
 *
 * Built-ins live here rather than in the published config so a site cannot
 * lose one by trimming its config, and so a new built-in reaches existing
 * installs without them republishing. This mirrors the EE and Craft builds,
 * where each scope has its own list and the config key holds only additions.
 *
 * Scope names are dexter-core's: entries, categories, files, users.
 */
class ScopePipelines
{
    /**
     * Runs for every scope, before anything scope-specific.
     *
     * FieldMapPipeline reduces values to the configured field map, so it has
     * to come first -- a later pipeline should never see a field the site
     * excluded.
     */
    private const COMMON = [
        FieldMapPipeline::class,
    ];

    /**
     * Built-in pipelines per scope, in order, excluding FullTextPipeline --
     * that is always appended last by build().
     */
    private const DEFAULTS = [
        'entries' => [
            StatusPipeline::class,
        ],
        'categories' => [],
        'files' => [
            // Appends described document and image contents. Reads the text
            // FullTextPipeline would otherwise assemble, so it is ordered
            // before it and does its own concatenation.
            FileDescribePipeline::class,
        ],
        'users' => [],
    ];

    /**
     * Config key holding the site's additions for each scope.
     */
    private const CONFIG_KEYS = [
        'entries' => 'entryPipelines',
        'categories' => 'categoryPipelines',
        'files' => 'filePipelines',
        'users' => 'userPipelines',
    ];

    /**
     * @param  array<string, mixed>  $config the `dexter` config array
     * @return string[] pipeline class names, in order
     */
    public static function build(string $scope, array $config): array
    {
        $pipelines = array_merge(
            self::COMMON,
            self::DEFAULTS[$scope] ?? [],
            self::additions($scope, $config),
        );

        // Always last: it concatenates whatever the earlier pipelines left
        // behind, so anything appended after it would not be searchable.
        $pipelines[] = FullTextPipeline::class;

        $seen = [];
        $resolved = [];

        foreach ($pipelines as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $key = self::normalise($class);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $resolved[] = $class;
        }

        return $resolved;
    }

    /**
     * The site's additions for a scope.
     *
     * `pipelines` is the pre-scope key this addon originally shipped. It still
     * applies to every scope so existing configs keep working; the scope keys
     * are additive on top of it.
     *
     * Built-ins are stripped out. Configs published before the built-ins moved
     * into this class list them inline, in an order that predates the
     * FullTextPipeline-last rule -- FileDescribePipeline after it rather than
     * before, and StatusPipeline for every scope rather than entries. Honouring
     * those positions would carry that ordering forward, so placement is taken
     * from this class and the config contributes only genuine additions.
     *
     * @param  array<string, mixed>  $config
     * @return string[]
     */
    private static function additions(string $scope, array $config): array
    {
        $shared = $config['pipelines'] ?? [];
        $scoped = $config[self::CONFIG_KEYS[$scope] ?? ''] ?? [];

        // Every built-in is stripped from the shared key. Configs published
        // before the built-ins moved into this class list them all there, and
        // honouring those positions would carry that ordering forward.
        $shared = self::reject(
            is_array($shared) ? $shared : [],
            self::builtIns(),
        );

        // Only this scope's own built-ins are stripped from the scope key.
        // Naming a class there is deliberate, so a class that is built-in for
        // another scope -- StatusPipeline, which ships for entries -- is a
        // legitimate addition and is kept.
        $scoped = self::reject(
            is_array($scoped) ? $scoped : [],
            array_merge(self::COMMON, self::DEFAULTS[$scope] ?? [], [FullTextPipeline::class]),
        );

        return array_merge($shared, $scoped);
    }

    /**
     * @param  array<mixed>  $classes
     * @param  string[]  $reject
     * @return string[]
     */
    private static function reject(array $classes, array $reject): array
    {
        $reject = array_map(self::normalise(...), $reject);

        return array_values(array_filter(
            $classes,
            fn ($class) => is_string($class)
                && ! in_array(self::normalise($class), $reject, true),
        ));
    }

    /**
     * Every class this addon places itself, whatever the scope.
     *
     * @return string[]
     */
    private static function builtIns(): array
    {
        return array_merge(
            self::COMMON,
            array_merge(...array_values(self::DEFAULTS)),
            [FullTextPipeline::class],
        );
    }

    /**
     * A class name in one comparable form.
     *
     * `Foo::class` yields no leading separator, but a config may quote the
     * name with one, and PHP treats both as the same class. Comparing raw
     * strings would miss that and let a built-in through twice.
     */
    private static function normalise(string $class): string
    {
        return strtolower(ltrim($class, '\\'));
    }
}
