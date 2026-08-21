<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\Dexter\Service\IndexableEntry;
use BoldMinded\Dexter\Service\IndexableTerm;
use BoldMinded\Dexter\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * Entries and terms carry a `permalink` -- the fully qualified URL -- next to
 * the relative `url` and `uri`, so an index can link straight at a result.
 */
class IndexablePermalinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->routes('/{slug}')->save();
        Collection::make('hidden')->save(); // No route.
        Taxonomy::make('topics')->save();
    }

    #[Test]
    public function an_entry_carries_its_absolute_url_as_permalink(): void
    {
        $entry = Entry::make()->collection('pages')->slug('contact')->data(['title' => 'Contact']);
        $entry->save();

        $values = (new IndexableEntry($entry))->getValues();

        $this->assertSame('/contact', $values['uri']);
        $this->assertSame(rtrim(config('app.url'), '/') . '/contact', $values['permalink']);
    }

    #[Test]
    public function a_term_carries_its_absolute_url_as_permalink(): void
    {
        Term::make()->taxonomy('topics')->slug('pasta')->data(['title' => 'Pasta'])->save();

        $term = Term::query()->where('taxonomy', 'topics')->first();

        $values = (new IndexableTerm($term))->getValues();

        $this->assertSame(
            rtrim(config('app.url'), '/') . '/topics/pasta',
            $values['permalink']
        );
    }

    /**
     * A collection without a route yields no URL at all. The key is dropped
     * rather than indexed as null, so a document never carries a dead link.
     */
    #[Test]
    public function a_non_routable_entry_omits_the_permalink(): void
    {
        $entry = Entry::make()->collection('hidden')->slug('secret')->data(['title' => 'Secret']);
        $entry->save();

        $values = (new IndexableEntry($entry))->getValues();

        $this->assertArrayNotHasKey('permalink', $values);
        $this->assertArrayNotHasKey('url', $values);
    }

    #[Test]
    public function permalink_is_indexed_by_default(): void
    {
        $entry = Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']);
        $entry->save();

        $this->assertContains('permalink', (new IndexableEntry($entry))->getDefaultFields());

        Term::make()->taxonomy('topics')->slug('wine')->data(['title' => 'Wine'])->save();
        $term = Term::query()->where('taxonomy', 'topics')->where('slug', 'wine')->first();

        $this->assertContains('permalink', (new IndexableTerm($term))->getDefaultFields());
    }

    /**
     * absoluteUrl() reads the redirect field, which augments and would turn a
     * bulk reindex into an N+1. A redirecting entry must still index its own
     * address, not wherever it points.
     */
    #[Test]
    public function a_redirecting_entry_indexes_its_own_address(): void
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('moved')
            ->data(['title' => 'Moved', 'redirect' => 'https://example.com/elsewhere']);
        $entry->save();

        $values = (new IndexableEntry($entry))->getValues();

        $this->assertSame(rtrim(config('app.url'), '/') . '/moved', $values['permalink']);
        $this->assertStringNotContainsString('example.com', $values['permalink']);
    }
}
