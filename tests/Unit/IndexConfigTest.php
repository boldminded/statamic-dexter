<?php

namespace BoldMinded\Dexter\Tests\Unit;

use BoldMinded\Dexter\Service\IndexConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * IndexConfig is constructed with a plain array, so the name/driver resolution
 * the export path depends on is testable without booting the app.
 */
class IndexConfigTest extends TestCase
{
    private function config(array $overrides = []): IndexConfig
    {
        return new IndexConfig(array_merge([
            'env' => 'local',
            'suffix' => '',
            'indexes' => [
                'content' => ['driver' => 'meilisearch', 'searchables' => []],
                'products' => ['driver' => 'algolia', 'searchables' => []],
            ],
        ], $overrides));
    }

    #[Test]
    public function it_lists_configured_index_names(): void
    {
        $this->assertSame(['content', 'products'], $this->config()->indexNames());
    }

    #[Test]
    public function it_returns_an_empty_list_when_no_indexes_are_configured(): void
    {
        $this->assertSame([], (new IndexConfig([]))->indexNames());
    }

    #[Test]
    public function it_resolves_the_driver_for_an_index(): void
    {
        $config = $this->config();

        $this->assertSame('meilisearch', $config->driverFor('content'));
        $this->assertSame('algolia', $config->driverFor('products'));
    }

    #[Test]
    public function it_returns_null_for_an_unknown_index_driver(): void
    {
        $this->assertNull($this->config()->driverFor('nope'));
    }

    #[Test]
    public function it_returns_null_when_an_index_declares_no_driver(): void
    {
        $config = $this->config(['indexes' => ['content' => ['searchables' => []]]]);

        $this->assertNull($config->driverFor('content'));
    }

    #[Test]
    public function it_prefixes_the_resolved_name_with_the_environment(): void
    {
        $this->assertSame('local_content', $this->config()->resolveIndexName('content'));
    }

    #[Test]
    public function it_appends_a_configured_suffix(): void
    {
        $config = $this->config(['env' => 'production', 'suffix' => '_uk']);

        $this->assertSame('production_content_uk', $config->resolveIndexName('content'));
    }

    #[Test]
    public function it_resolves_a_closure_suffix_per_index(): void
    {
        $config = $this->config(['suffix' => fn (string $index) => '_' . strtoupper($index)]);

        $this->assertSame('local_content_CONTENT', $config->resolveIndexName('content'));
    }

    #[Test]
    public function it_falls_back_to_the_local_environment_prefix(): void
    {
        $config = new IndexConfig(['indexes' => ['content' => []]]);

        $this->assertSame('local_content', $config->resolveIndexName('content'));
    }

    #[Test]
    public function it_reads_driver_credentials(): void
    {
        $config = $this->config([
            'drivers' => ['meilisearch' => ['url' => 'http://ms:7700', 'key' => 'abc']],
        ]);

        $this->assertSame(['url' => 'http://ms:7700', 'key' => 'abc'], $config->driverCredentials('meilisearch'));
        $this->assertSame([], $config->driverCredentials('algolia'));
    }

    #[Test]
    public function it_defaults_the_search_limit(): void
    {
        $this->assertSame(100, $this->config()->searchLimit());
        $this->assertSame(25, $this->config(['searchLimit' => 25])->searchLimit());
    }
}
