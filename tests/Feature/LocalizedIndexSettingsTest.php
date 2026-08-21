<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Permissions;
use BoldMinded\Dexter\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;

/**
 * Settings transfer for a localized index.
 *
 * A localized index is several provider-side indexes, and their settings are
 * genuinely different -- stop words, synonyms and ranking rules are
 * language-specific -- so export and import address one locale at a time
 * rather than the config key.
 */
class LocalizedIndexSettingsTest extends TestCase
{
    use FakesRoles;

    private FakeIndexProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Site::setSites([
            'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
            'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
        ]);

        Collection::make('pages')->sites(['default', 'fr'])->save();

        config()->set('dexter.env', 'local');
        config()->set('dexter.suffix', '');
        config()->set('dexter.indexes', [
            'content' => [
                'driver' => 'meilisearch',
                'sites' => ['default', 'fr'],
                'searchables' => ['collection:pages' => ['fields' => ['title']]],
            ],
        ]);

        $this->provider = new FakeIndexProvider(['searchableAttributes' => ['title']]);

        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('make')->willReturn($this->provider);
        $this->app->instance(IndexerFactory::class, $factory);

        File::deleteDirectory(config_path('meilisearch'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config_path('meilisearch'));

        parent::tearDown();
    }

    private function actingAsManager(): self
    {
        $this->setTestRoles(['manager' => ['access cp', Permissions::VIEW, Permissions::MANAGE]]);

        return $this->actingAs(User::make()->assignRole('manager')->save());
    }

    #[Test]
    public function it_exports_the_settings_of_the_requested_site(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.export'), ['index' => 'local_content_fr']);

        $this->assertSame(['local_content_fr'], $this->provider->exportedWith);
    }

    /**
     * The env prefix is still stripped from the filename -- these files are
     * committed and moved between environments -- but the site is kept, or one
     * locale's export would overwrite another's.
     */
    #[Test]
    public function it_names_the_file_for_the_site_without_the_env_prefix(): void
    {
        config()->set('dexter.env', 'production');

        $this->actingAsManager()
            ->post(cp_route('dexter.export'), ['index' => 'production_content_fr']);

        $this->assertFileExists(config_path('meilisearch/content_fr.json'));
        $this->assertFileDoesNotExist(config_path('meilisearch/production_content_fr.json'));
    }

    #[Test]
    public function each_site_exports_to_its_own_file(): void
    {
        $manager = $this->actingAsManager();

        $manager->post(cp_route('dexter.export'), ['index' => 'local_content_default']);
        $manager->post(cp_route('dexter.export'), ['index' => 'local_content_fr']);

        $this->assertFileExists(config_path('meilisearch/content_default.json'));
        $this->assertFileExists(config_path('meilisearch/content_fr.json'));

        $this->assertSame(
            ['local_content_default', 'local_content_fr'],
            $this->provider->exportedWith
        );
    }

    #[Test]
    public function it_imports_into_the_requested_site(): void
    {
        File::ensureDirectoryExists(config_path('meilisearch'));
        File::put(
            config_path('meilisearch/content_fr.json'),
            json_encode(['stopWords' => ['le', 'la']])
        );

        $this->actingAsManager()
            ->post(cp_route('dexter.import'), [
                'index' => 'local_content_fr',
                'settings' => 'content_fr.json',
            ]);

        $this->assertSame(
            [['index' => 'local_content_fr', 'settings' => ['stopWords' => ['le', 'la']]]],
            $this->provider->importedWith
        );
    }

    /**
     * A file exported from one locale can be applied to another: that is how a
     * tuned configuration is copied between sites, so index and file are
     * chosen independently rather than the file constraining the index.
     */
    #[Test]
    public function a_file_from_one_site_may_be_imported_into_another(): void
    {
        File::ensureDirectoryExists(config_path('meilisearch'));
        File::put(
            config_path('meilisearch/content_default.json'),
            json_encode(['searchableAttributes' => ['title']])
        );

        $this->actingAsManager()
            ->post(cp_route('dexter.import'), [
                'index' => 'local_content_fr',
                'settings' => 'content_default.json',
            ])
            ->assertSessionHas('success');

        $this->assertSame('local_content_fr', $this->provider->importedWith[0]['index']);
    }

    /**
     * A site the index is not built for names no real provider-side index, so
     * it is not a target at all and is refused before anything is written.
     */
    #[Test]
    public function it_refuses_a_site_the_index_is_not_built_for(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.export'), ['index' => 'local_content_de'])
            ->assertNotFound();

        $this->assertSame([], $this->provider->exportedWith);
        $this->assertFileDoesNotExist(config_path('meilisearch/content_de.json'));
    }

    /**
     * The bare name is not a target for an index whose content all carries a
     * site -- nothing is ever written there, so there are no settings to move.
     */
    #[Test]
    public function the_bare_name_is_not_a_target_when_every_searchable_has_a_site(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.export'), ['index' => 'local_content'])
            ->assertNotFound();

        $this->assertSame([], $this->provider->exportedWith);
    }
}
