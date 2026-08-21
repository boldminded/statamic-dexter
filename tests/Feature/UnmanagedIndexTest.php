<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Permissions;
use BoldMinded\Dexter\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;

/**
 * Indexes the provider holds that this site does not configure.
 *
 * They are addressed by resolved name rather than a config key, because by
 * definition no key maps to them -- so they get their own routes, and those
 * routes carry their own allowlist: the name must be one the provider actually
 * reports, and must not belong to a configured index.
 *
 * The screen only offers these actions for a row it marks unmanaged, so the
 * classification is tested alongside them: a bug there silently hides the
 * actions, or offers them for an index Dexter is actively writing to.
 */
class UnmanagedIndexTest extends TestCase
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

        // IndexConfig is a singleton holding a snapshot of config('dexter'),
        // taken the first time it is resolved. The config above is set after
        // the app boots, so the instance has to go or the controller reads the
        // pre-test config and owns nothing.
        $this->app->forgetInstance(\BoldMinded\Dexter\Service\IndexConfig::class);

        // A leftover alongside the two the config owns: the unlocalized name
        // this index used before it gained `sites`.
        $this->swapProvider(new FakeIndexProvider(indexes: [
            'local_content_default' => 4,
            'local_content_fr' => 2,
            'local_content' => 8,
        ]));
    }

    protected function swapProvider(FakeIndexProvider $provider): void
    {
        $this->provider = $provider;

        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('make')->willReturn($provider);
        $this->app->instance(IndexerFactory::class, $factory);
    }

    protected function actingAsManager(): self
    {
        $this->setTestRoles(['manager' => ['access cp', Permissions::VIEW, Permissions::MANAGE]]);

        return $this->actingAs(User::make()->assignRole('manager')->save());
    }

    #[Test]
    public function it_clears_an_index_the_config_does_not_own(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.unmanaged.clear'), ['name' => 'local_content'])
            ->assertSessionHas('success');

        $this->assertSame(['local_content'], $this->provider->clearedWith);
    }

    #[Test]
    public function it_deletes_an_index_the_config_does_not_own(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.unmanaged.delete'), ['name' => 'local_content'])
            ->assertSessionHas('success');

        $this->assertSame(['local_content'], $this->provider->deletedIndexes);
    }

    /**
     * A configured index has its own key-based actions, which apply the
     * allowlist and act across every locale. Reaching one through here would
     * empty a single locale and report success.
     */
    #[Test]
    public function it_refuses_an_index_the_config_owns(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.unmanaged.delete'), ['name' => 'local_content_fr'])
            ->assertSessionHas('error');

        $this->assertSame([], $this->provider->deletedIndexes);
    }

    /**
     * Only names the provider reports can be acted on, so a crafted request
     * cannot make Dexter issue deletes for arbitrary strings.
     */
    #[Test]
    public function it_refuses_a_name_the_provider_does_not_report(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.unmanaged.delete'), ['name' => 'something_else'])
            ->assertSessionHas('error');

        $this->assertSame([], $this->provider->deletedIndexes);
    }

    #[Test]
    public function it_requires_a_name(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.unmanaged.delete'), [])
            ->assertSessionHas('error');

        $this->assertSame([], $this->provider->deletedIndexes);
    }

    #[Test]
    public function it_forbids_a_user_with_only_the_view_permission(): void
    {
        $this->setTestRoles(['viewer' => ['access cp', Permissions::VIEW]]);

        $this->actingAs(User::make()->assignRole('viewer')->save())
            ->post(cp_route('dexter.unmanaged.delete'), ['name' => 'local_content'])
            ->assertRedirect();

        $this->assertSame([], $this->provider->deletedIndexes);
    }

    #[Test]
    public function mutating_actions_are_not_reachable_by_get(): void
    {
        $manager = $this->actingAsManager();

        $manager->get(cp_route('dexter.unmanaged.clear'))->assertNotFound();
        $manager->get(cp_route('dexter.unmanaged.delete'))->assertNotFound();

        $this->assertSame([], $this->provider->deletedIndexes);
        $this->assertSame([], $this->provider->clearedWith);
    }

    /**
     * The screen is where these actions are offered, so the row has to be
     * marked unmanaged for the endpoint to be reachable at all.
     */
    #[Test]
    public function the_screen_marks_only_the_leftover_index_unmanaged(): void
    {
        $response = $this->actingAsManager()->get(cp_route('dexter.index'));

        $response->assertOk();

        $available = collect($response->viewData('available'))
            ->mapWithKeys(fn (array $row) => [$row['name'] => $row['configured']])
            ->all();

        $this->assertSame([
            'local_content' => false,
            'local_content_default' => true,
            'local_content_fr' => true,
        ], $available);
    }

    #[Test]
    public function the_screen_offers_the_unmanaged_actions_for_that_row(): void
    {
        $this->actingAsManager()
            ->get(cp_route('dexter.index'))
            ->assertSee(cp_route('dexter.unmanaged.delete'), false)
            ->assertSee(cp_route('dexter.unmanaged.clear'), false);
    }

    /**
     * With nothing left over there is nothing to offer, and the destructive
     * forms must not appear at all.
     */
    #[Test]
    public function the_screen_omits_them_when_every_index_is_configured(): void
    {
        $this->swapProvider(new FakeIndexProvider(indexes: [
            'local_content_default' => 4,
            'local_content_fr' => 2,
        ]));

        $this->actingAsManager()
            ->get(cp_route('dexter.index'))
            ->assertDontSee(cp_route('dexter.unmanaged.delete'), false);
    }
}
