<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Permissions;
use BoldMinded\Dexter\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;

/**
 * The export action writes provider settings to config/{driver}/{index}.json.
 *
 * Every test here fakes the provider -- these assert the controller's file
 * handling, authorization and naming, not that Meilisearch is reachable.
 */
class ExportIndexSettingsTest extends TestCase
{
    use FakesRoles;

    private FakeIndexProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // Export addresses a rebuild target, and a target only exists for an
        // index with at least one resolvable source -- so these have to be
        // real collections rather than empty searchable lists.
        Collection::make('pages')->save();
        Collection::make('products')->save();

        config()->set('dexter.env', 'local');
        config()->set('dexter.suffix', '');
        config()->set('dexter.indexes', [
            'content' => [
                'driver' => 'meilisearch',
                'searchables' => ['collection:pages' => ['fields' => ['title']]],
            ],
            'products' => [
                'driver' => 'algolia',
                'searchables' => ['collection:products' => ['fields' => ['title']]],
            ],
        ]);

        $this->provider = new FakeIndexProvider(['searchableAttributes' => ['title']]);

        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('make')->willReturn($this->provider);
        $this->app->instance(IndexerFactory::class, $factory);

        $this->cleanUpExports();
    }

    protected function tearDown(): void
    {
        $this->cleanUpExports();

        parent::tearDown();
    }

    private function cleanUpExports(): void
    {
        File::deleteDirectory(config_path('meilisearch'));
        File::deleteDirectory(config_path('algolia'));
    }

    /**
     * 'access cp' is Statamic's own gate on the CP middleware -- without it
     * no request reaches the controller at all, whatever Dexter permissions
     * the role carries.
     */
    private function actingAsManager(): self
    {
        $this->setTestRoles(['manager' => ['access cp', Permissions::VIEW, Permissions::MANAGE]]);

        return $this->actingAs(User::make()->assignRole('manager')->save());
    }

    /**
     * A user who may see the Dexter screen but not act on it.
     */
    private function actingAsViewer(): self
    {
        $this->setTestRoles(['viewer' => ['access cp', Permissions::VIEW]]);

        return $this->actingAs(User::make()->assignRole('viewer')->save());
    }

    #[Test]
    public function it_writes_settings_to_the_driver_directory_named_for_the_index(): void
    {
        $response = $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $response->assertRedirect(cp_route('dexter.index'));

        $path = config_path('meilisearch/content.json');
        $this->assertFileExists($path);
        $this->assertSame(
            ['searchableAttributes' => ['title']],
            json_decode(File::get($path), true)
        );
    }

    #[Test]
    public function it_uses_the_configured_driver_as_the_directory(): void
    {
        $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'local_products']);

        $this->assertFileExists(config_path('algolia/products.json'));
        $this->assertFileDoesNotExist(config_path('meilisearch/products.json'));
    }

    #[Test]
    public function it_names_the_file_for_the_config_key_not_the_resolved_name(): void
    {
        config()->set('dexter.env', 'production');

        // The target value carries the env prefix, since it names the actual
        // provider-side index.
        $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'production_content']);

        // The env prefix belongs to the provider-side index, not the file --
        // these files are meant to be committed and moved between envs.
        $this->assertFileExists(config_path('meilisearch/content.json'));
        $this->assertFileDoesNotExist(config_path('meilisearch/production_content.json'));
    }

    #[Test]
    public function it_asks_the_provider_for_the_resolved_index_name(): void
    {
        config()->set('dexter.env', 'staging');
        config()->set('dexter.suffix', '_uk');

        $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'staging_content_uk']);

        $this->assertSame(['staging_content_uk'], $this->provider->exportedWith);
    }

    #[Test]
    public function it_creates_the_driver_directory_when_missing(): void
    {
        $this->assertDirectoryDoesNotExist(config_path('meilisearch'));

        $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $this->assertDirectoryExists(config_path('meilisearch'));
    }

    #[Test]
    public function it_writes_pretty_printed_json_with_unescaped_slashes(): void
    {
        $this->provider = new FakeIndexProvider(['url' => 'http://example.com/path']);
        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('make')->willReturn($this->provider);
        $this->app->instance(IndexerFactory::class, $factory);

        $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $contents = File::get(config_path('meilisearch/content.json'));

        $this->assertStringContainsString("{\n    \"url\"", $contents);
        $this->assertStringContainsString('http://example.com/path', $contents);
        $this->assertStringNotContainsString('http:\/\/', $contents);
        $this->assertStringEndsWith("}\n", $contents);
    }

    #[Test]
    public function it_overwrites_a_previous_export(): void
    {
        File::ensureDirectoryExists(config_path('meilisearch'));
        File::put(config_path('meilisearch/content.json'), '{"stale": true}');

        $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $decoded = json_decode(File::get(config_path('meilisearch/content.json')), true);

        $this->assertArrayNotHasKey('stale', $decoded);
        $this->assertSame(['searchableAttributes' => ['title']], $decoded);
    }

    #[Test]
    public function it_flashes_the_written_path_on_success(): void
    {
        $response = $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $response->assertSessionHas(
            'success',
            'Exported settings for local_content to config/meilisearch/content.json.'
        );
    }

    #[Test]
    public function it_flashes_a_failure_and_writes_nothing_when_the_provider_throws(): void
    {
        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('make')->willReturn(
            new FakeIndexProvider(exportThrows: new \RuntimeException('provider unreachable'))
        );
        $this->app->instance(IndexerFactory::class, $factory);

        $response = $this->actingAsManager()
            ->withExceptionHandling()
            ->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $response->assertSessionHas('error');
        $this->assertFileDoesNotExist(config_path('meilisearch/content.json'));
    }

    #[Test]
    public function it_rejects_an_index_that_is_not_configured(): void
    {
        $response = $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'not-configured']);

        $response->assertNotFound();
        $this->assertDirectoryDoesNotExist(config_path('meilisearch'));
    }

    #[Test]
    public function it_flashes_a_failure_when_the_index_has_no_driver(): void
    {
        // The source still has to resolve, or the index would have no target
        // and this would 404 before reaching the driver check.
        config()->set('dexter.indexes', [
            'content' => ['searchables' => ['collection:pages' => ['fields' => ['title']]]],
        ]);

        $response = $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $response->assertSessionHas('error');
        $this->assertDirectoryDoesNotExist(config_path('meilisearch'));
    }

    /**
     * Export destroys nothing, but it writes into the app's config directory,
     * so it sits behind MANAGE alongside clear and delete rather than the
     * widely-granted VIEW.
     */
    #[Test]
    public function it_forbids_a_user_with_only_the_view_permission(): void
    {
        // Statamic bounces an unauthorized CP request rather than returning
        // 403, so the proof is that nothing was written or asked of the
        // provider -- not the status code.
        $this->actingAsViewer()
            ->post(cp_route('dexter.export'), ['index' => 'local_content'])
            ->assertRedirect();

        $this->assertFileDoesNotExist(config_path('meilisearch/content.json'));
        $this->assertSame([], $this->provider->exportedWith);
    }

    /**
     * Export writes to disk, so it must not be triggerable by a link, a
     * prefetch or an <img> pointed at the URL. Statamic's CP route group
     * 404s an unmatched method rather than returning 405.
     */
    #[Test]
    public function it_is_not_reachable_by_a_get_request(): void
    {
        $this->actingAsManager()
            ->get(cp_route('dexter.export'))
            ->assertNotFound();

        $this->assertFileDoesNotExist(config_path('meilisearch/content.json'));
    }

    #[Test]
    public function it_forbids_a_guest(): void
    {
        $response = $this->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $this->assertContains($response->getStatusCode(), [401, 403, 302]);
        $this->assertFileDoesNotExist(config_path('meilisearch/content.json'));
    }
}
