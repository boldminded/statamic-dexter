<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Permissions;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use BoldMinded\Dexter\Tests\TestCase;

/**
 * Import is export's counterpart: a file exported to config/{driver}/ must be
 * accepted back by import, so these two stay in step.
 *
 * The file is chosen from that directory rather than uploaded, so the browser
 * only ever supplies a name. These tests pin that the name is resolved against
 * the directory listing and never used as a path.
 */
class ImportIndexSettingsTest extends TestCase
{
    use FakesRoles;

    private FakeIndexProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->save();

        config()->set('dexter.env', 'local');
        config()->set('dexter.suffix', '');
        config()->set('dexter.indexes', [
            'content' => [
                'driver' => 'meilisearch',
                'searchables' => ['collection:pages' => ['fields' => ['title']]],
            ],
        ]);

        $this->swapProvider(new FakeIndexProvider(['searchableAttributes' => ['title']]));

        File::deleteDirectory(config_path('meilisearch'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config_path('meilisearch'));

        parent::tearDown();
    }

    private function swapProvider(FakeIndexProvider $provider): void
    {
        $this->provider = $provider;

        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('make')->willReturn($provider);
        $this->app->instance(IndexerFactory::class, $factory);
    }

    private function actingAsManager(): self
    {
        $this->setTestRoles(['manager' => ['access cp', Permissions::VIEW, Permissions::MANAGE]]);

        return $this->actingAs(User::make()->assignRole('manager')->save());
    }

    /**
     * Write a settings file into the driver's directory, as an export would.
     */
    private function givenSettingsFile(string $name, string $contents): string
    {
        File::ensureDirectoryExists(config_path('meilisearch'));
        File::put($path = config_path('meilisearch/' . $name), $contents);

        return $path;
    }

    #[Test]
    public function it_passes_decoded_settings_and_the_resolved_name_to_the_provider(): void
    {
        $this->givenSettingsFile(
            'content.json',
            json_encode(['searchableAttributes' => ['title', 'body']])
        );

        $this->actingAsManager()->post(cp_route('dexter.import'), [
            'index' => 'local_content',
            'settings' => 'content.json',
        ]);

        $this->assertSame([[
            'index' => 'local_content',
            'settings' => ['searchableAttributes' => ['title', 'body']],
        ]], $this->provider->importedWith);
    }

    #[Test]
    public function it_accepts_a_file_produced_by_export(): void
    {
        // Export first, then feed the written file straight back in.
        $this->actingAsManager()->post(cp_route('dexter.export'), ['index' => 'local_content']);

        $this->assertFileExists(config_path('meilisearch/content.json'));

        $this->actingAsManager()
            ->post(cp_route('dexter.import'), [
                'index' => 'local_content',
                'settings' => 'content.json',
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            ['searchableAttributes' => ['title']],
            $this->provider->importedWith[0]['settings'],
            'A round trip must preserve the exported settings exactly.'
        );
    }

    #[Test]
    public function it_flashes_a_failure_when_the_file_is_not_valid_json(): void
    {
        $this->givenSettingsFile('broken.json', 'not json at all');

        $this->actingAsManager()
            ->post(cp_route('dexter.import'), [
                'index' => 'local_content',
                'settings' => 'broken.json',
            ])
            ->assertSessionHas('error');

        $this->assertSame([], $this->provider->importedWith);
    }

    #[Test]
    public function it_flashes_a_failure_when_the_provider_rejects_the_settings(): void
    {
        $this->givenSettingsFile('content.json', json_encode(['a' => 'b']));
        $this->swapProvider(new FakeIndexProvider(importSucceeds: false));

        $this->actingAsManager()
            ->post(cp_route('dexter.import'), [
                'index' => 'local_content',
                'settings' => 'content.json',
            ])
            ->assertSessionHas('error');
    }

    #[Test]
    public function it_requires_a_settings_file(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.import'), ['index' => 'local_content'])
            ->assertSessionHas('error');

        $this->assertSame([], $this->provider->importedWith);
    }

    #[Test]
    public function it_rejects_a_file_that_is_not_in_the_directory(): void
    {
        $this->actingAsManager()
            ->post(cp_route('dexter.import'), [
                'index' => 'local_content',
                'settings' => 'never-exported.json',
            ])
            ->assertSessionHas('error');

        $this->assertSame([], $this->provider->importedWith);
    }

    /**
     * The name is matched against the directory listing rather than joined
     * onto a path, so a traversal attempt is simply not found. Asserted
     * explicitly because the value arrives from the browser.
     */
    #[Test]
    public function it_refuses_to_read_outside_the_driver_directory(): void
    {
        $this->givenSettingsFile('content.json', json_encode(['a' => 'b']));

        $outside = config_path('outside.json');
        File::put($outside, json_encode(['secret' => true]));

        try {
            foreach ([
                '../outside.json',
                '../../config/outside.json',
                config_path('outside.json'),
                '/etc/passwd',
            ] as $attempt) {
                $this->actingAsManager()
                    ->post(cp_route('dexter.import'), [
                        'index' => 'local_content',
                        'settings' => $attempt,
                    ])
                    ->assertSessionHas('error');
            }

            $this->assertSame([], $this->provider->importedWith);
        } finally {
            File::delete($outside);
        }
    }

    /**
     * Only .json is listed, so a file dropped in the directory with another
     * extension cannot be selected even by name.
     */
    #[Test]
    public function it_ignores_files_that_are_not_json(): void
    {
        $this->givenSettingsFile('notes.txt', 'plain text');

        $this->actingAsManager()
            ->post(cp_route('dexter.import'), [
                'index' => 'local_content',
                'settings' => 'notes.txt',
            ])
            ->assertSessionHas('error');

        $this->assertSame([], $this->provider->importedWith);
    }

    #[Test]
    public function it_rejects_an_index_that_is_not_configured(): void
    {
        $this->givenSettingsFile('content.json', json_encode(['a' => 'b']));

        $this->actingAsManager()
            ->post(cp_route('dexter.import'), [
                'index' => 'not-configured',
                'settings' => 'content.json',
            ])
            ->assertNotFound();

        $this->assertSame([], $this->provider->importedWith);
    }

    #[Test]
    public function it_forbids_a_user_with_only_the_view_permission(): void
    {
        $this->givenSettingsFile('content.json', json_encode(['a' => 'b']));

        $this->setTestRoles(['viewer' => ['access cp', Permissions::VIEW]]);

        $this->actingAs(User::make()->assignRole('viewer')->save())
            ->post(cp_route('dexter.import'), [
                'index' => 'local_content',
                'settings' => 'content.json',
            ])
            ->assertRedirect();

        $this->assertSame([], $this->provider->importedWith);
    }

    #[Test]
    public function it_is_not_reachable_by_a_get_request(): void
    {
        $this->actingAsManager()
            ->get(cp_route('dexter.import'))
            ->assertNotFound();

        $this->assertSame([], $this->provider->importedWith);
    }
}
