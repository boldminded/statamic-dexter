<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Permissions;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use BoldMinded\Dexter\Tests\TestCase;

/**
 * The remaining mutating CP actions: clear, delete and reindex.
 *
 * These share the allowlist and POST-only guarantees the export tests cover,
 * so the emphasis here is on those invariants holding across every action.
 */
class IndexActionsTest extends TestCase
{
    use FakesRoles;

    private FakeIndexProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dexter.env', 'local');
        config()->set('dexter.suffix', '');
        config()->set('dexter.indexes', [
            'content' => ['driver' => 'meilisearch', 'searchables' => []],
        ]);

        $this->provider = new FakeIndexProvider();

        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('make')->willReturn($this->provider);
        $this->app->instance(IndexerFactory::class, $factory);
    }

    private function actingAsManager(): self
    {
        $this->setTestRoles(['manager' => ['access cp', Permissions::VIEW, Permissions::MANAGE]]);

        return $this->actingAs(User::make()->assignRole('manager')->save());
    }

    #[Test]
    public function it_clears_the_resolved_index(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.clear', 'content'))
            ->assertSessionHas('success');

        $this->assertSame(['local_content'], $this->provider->clearedWith);
    }

    #[Test]
    public function it_deletes_the_resolved_index(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.delete', 'content'))
            ->assertSessionHas('success');

        $this->assertSame(['local_content'], $this->provider->deletedIndexes);
    }

    #[Test]
    public function it_rejects_clearing_an_index_that_is_not_configured(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.clear', 'not-configured'))
            ->assertNotFound();

        $this->assertSame([], $this->provider->clearedWith);
    }

    #[Test]
    public function it_rejects_deleting_an_index_that_is_not_configured(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.delete', 'not-configured'))
            ->assertNotFound();

        $this->assertSame([], $this->provider->deletedIndexes);
    }

    #[Test]
    public function it_requires_an_index_to_reindex(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.reindex'), ['index' => ''])
            ->assertSessionHas('error');
    }

    #[Test]
    public function it_rejects_reindexing_an_index_that_is_not_configured(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.reindex'), ['index' => 'not-configured'])
            ->assertNotFound();
    }

    /**
     * The Content dropdown lists sources across every index, so the pair
     * arrives from two independent fields and has to be re-checked.
     */
    #[Test]
    public function it_rejects_a_searchable_that_belongs_to_another_index(): void
    {
        // Both collections have to exist: a target is only offered when it has
        // at least one resolvable source, so without these the request would
        // 404 on an empty target list before reaching the mismatch check.
        Collection::make('pages')->save();
        Collection::make('menu_items')->save();

        config()->set('dexter.indexes', [
            'content' => [
                'driver' => 'meilisearch',
                'searchables' => ['collection:pages' => ['fields' => ['title']]],
            ],
            'products' => [
                'driver' => 'meilisearch',
                'searchables' => ['collection:menu_items' => ['fields' => ['title']]],
            ],
        ]);

        // The index field posts a rebuild target -- the resolved index name --
        // rather than the bare config key, since a localized index offers one
        // target per site.
        $this->actingAsManager()
            ->post(cp_route('dexter.reindex'), [
                'index' => 'local_content',
                'searchable' => 'collection:menu_items',
            ])
            ->assertSessionHas('error');
    }

    /**
     * Every mutating action is POST only, so none can be fired by a link, a
     * prefetch or an <img> pointed at the URL.
     */
    #[Test]
    public function mutating_actions_are_not_reachable_by_get(): void
    {
        $manager = $this->actingAsManager();

        foreach (['dexter.clear', 'dexter.delete', 'dexter.import', 'dexter.export'] as $route) {
            $manager->get(cp_route($route, 'content'))->assertNotFound();
        }

        $manager->get(cp_route('dexter.reindex'))->assertNotFound();
    }
}
