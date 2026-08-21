<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\Dexter\Service\IndexConfig;
use BoldMinded\Dexter\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

/**
 * The Rebuild dropdown offers one target per index per site, because a
 * localized index is several provider-side indexes and rebuilding "the index"
 * would be ambiguous about which.
 *
 * Assets and users complicate this: they carry no locale, so CommandFactory
 * resolves them with a null one and writes them to the bare index name. They
 * therefore need a target of their own rather than riding along with a site.
 */
class RebuildTargetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Site::setSites([
            'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
            'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
        ]);

        Collection::make('pages')->sites(['default', 'fr'])->save();
        Taxonomy::make('menu_category')->sites(['default', 'fr'])->save();

        // A searchable only becomes a rebuild source once its handle resolves,
        // so the container has to exist for the assets targets to be offered.
        AssetContainer::make('assets')->disk('local')->save();

        config()->set('dexter.env', 'local');
        config()->set('dexter.suffix', '');
    }

    private function config(array $indexes): IndexConfig
    {
        config()->set('dexter.indexes', $indexes);

        return IndexConfig::make();
    }

    #[Test]
    public function a_localized_index_offers_one_target_per_site(): void
    {
        $targets = $this->config([
            'content' => [
                'driver' => 'meilisearch',
                'sites' => ['default', 'fr'],
                'searchables' => ['collection:pages' => ['fields' => ['title']]],
            ],
        ])->rebuildTargets();

        $this->assertSame(
            ['local_content_default', 'local_content_fr'],
            array_column($targets, 'value')
        );

        $this->assertSame(['default', 'fr'], array_column($targets, 'site'));
    }

    #[Test]
    public function an_unlocalized_index_offers_a_single_target(): void
    {
        $targets = $this->config([
            'content' => [
                'driver' => 'meilisearch',
                'searchables' => ['collection:pages' => ['fields' => ['title']]],
            ],
        ])->rebuildTargets();

        $this->assertCount(1, $targets);
        $this->assertSame('local_content', $targets[0]['value']);
        $this->assertNull($targets[0]['site']);
    }

    /**
     * Assets have no site, so they resolve to the bare name no matter which
     * locale is being rebuilt. Without a target of their own there would be no
     * way to rebuild them from the CP at all.
     */
    #[Test]
    public function assets_get_their_own_unlocalized_target(): void
    {
        $targets = $this->config([
            'content' => [
                'driver' => 'meilisearch',
                'sites' => ['default', 'fr'],
                'searchables' => [
                    'collection:pages' => ['fields' => ['title']],
                    'assets:assets' => ['fields' => ['alt']],
                ],
            ],
        ])->rebuildTargets();

        $this->assertSame(
            ['local_content_default', 'local_content_fr', 'local_content'],
            array_column($targets, 'value')
        );

        $byValue = array_column($targets, null, 'value');

        // The per-site targets carry only the content that has a site...
        $this->assertSame(
            ['collection:pages'],
            array_column($byValue['local_content_default']['sources'], 'value')
        );

        // ...and the bare target carries only the content that does not.
        $this->assertSame(
            ['assets:assets'],
            array_column($byValue['local_content']['sources'], 'value')
        );
    }

    /**
     * A localized index holding nothing but localized content has no reason to
     * offer a bare target: nothing would ever be written there.
     */
    #[Test]
    public function no_bare_target_when_every_searchable_has_a_site(): void
    {
        $targets = $this->config([
            'content' => [
                'driver' => 'meilisearch',
                'sites' => ['default', 'fr'],
                'searchables' => [
                    'collection:pages' => ['fields' => ['title']],
                    'taxonomy:menu_category' => ['fields' => ['title']],
                ],
            ],
        ])->rebuildTargets();

        $this->assertNotContains('local_content', array_column($targets, 'value'));
    }

    #[Test]
    public function a_target_whose_sources_all_fail_to_resolve_is_omitted(): void
    {
        $targets = $this->config([
            'content' => [
                'driver' => 'meilisearch',
                'sites' => ['default', 'fr'],
                'searchables' => ['collection:does_not_exist' => ['fields' => ['title']]],
            ],
        ])->rebuildTargets();

        $this->assertSame([], $targets);
    }

    /**
     * The Index settings rows render from this same list, so an index whose
     * content has no locale must not gain per-site targets: they would be
     * offered for export and import while never existing on the provider.
     */
    #[Test]
    public function an_index_of_assets_offers_only_its_bare_target(): void
    {
        $targets = $this->config([
            'assets' => [
                'driver' => 'meilisearch',
                // Declared, but assets have no locale to be split by.
                'sites' => ['default', 'fr'],
                'searchables' => ['assets:assets' => ['fields' => ['alt']]],
            ],
        ])->rebuildTargets();

        $this->assertSame(['local_assets'], array_column($targets, 'value'));
    }

    #[Test]
    public function a_target_is_found_by_its_form_value(): void
    {
        $config = $this->config([
            'content' => [
                'driver' => 'meilisearch',
                'sites' => ['default', 'fr'],
                'searchables' => ['collection:pages' => ['fields' => ['title']]],
            ],
        ]);

        $target = $config->rebuildTarget('local_content_fr');

        $this->assertNotNull($target);
        $this->assertSame('content', $target['index']);
        $this->assertSame('fr', $target['site']);
    }

    /**
     * The lookup is the rebuild action's allowlist, so anything not built from
     * the config has to come back null rather than being treated as a key.
     */
    #[Test]
    public function an_unknown_value_resolves_to_nothing(): void
    {
        $config = $this->config([
            'content' => [
                'driver' => 'meilisearch',
                'sites' => ['default', 'fr'],
                'searchables' => ['collection:pages' => ['fields' => ['title']]],
            ],
        ]);

        // The bare config key is no longer a valid target for a localized
        // index -- it names no real provider-side index.
        $this->assertNull($config->rebuildTarget('content'));
        $this->assertNull($config->rebuildTarget('local_content_de'));
    }
}
