<?php

namespace BoldMinded\Dexter\Tests\Unit;

use BoldMinded\Dexter\Service\Fields\FieldTypeFactory;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use BoldMinded\Dexter\Tests\TestCase;
use Statamic\Fields\Field;

/**
 * Characterization tests for field extraction.
 *
 * Written when ValueExtractor was split into FieldValueWalker plus the
 * per-type handlers, to pin the behaviour that split was meant to preserve.
 * Every expectation here is the output the single-class version produced.
 */
class FieldValueWalkerTest extends TestCase
{
    private function walker(): FieldValueWalker
    {
        return new FieldValueWalker();
    }

    private function field(string $handle, string $type, array $config = []): Field
    {
        return new Field($handle, array_merge(['type' => $type], $config));
    }

    public function test_scalar_strings_are_stripped_of_markup(): void
    {
        $values = $this->walker()->extract(
            $this->field('body', 'text'),
            '<p>Hello &amp; welcome</p>'
        );

        $this->assertSame(['body' => 'Hello & welcome'], $values);
    }

    public function test_booleans_are_preserved_rather_than_stringified(): void
    {
        $values = $this->walker()->extract($this->field('featured', 'toggle'), true);

        $this->assertSame(['featured' => true], $values);
    }

    public function test_skipped_field_types_yield_nothing(): void
    {
        $this->assertSame([], $this->walker()->extract($this->field('sep', 'section'), 'x'));
        $this->assertSame([], $this->walker()->extract($this->field('c', 'color'), '#fff'));
    }

    public function test_null_and_empty_values_yield_nothing(): void
    {
        $this->assertSame([], $this->walker()->extract($this->field('body', 'text'), null));
        $this->assertSame([], $this->walker()->extract($this->field('body', 'text'), ''));
    }

    public function test_markdown_is_reduced_to_plain_text(): void
    {
        $values = $this->walker()->extract(
            $this->field('body', 'markdown'),
            "# Title\n\n<em>emphasis</em>"
        );

        $this->assertSame(['body' => "# Title\n\nemphasis"], $values);
    }

    public function test_relationship_fields_keep_stored_references(): void
    {
        $values = $this->walker()->extract(
            $this->field('related', 'entries'),
            ['id-one', 'id-two']
        );

        $this->assertSame(['related' => ['id-one', 'id-two']], $values);
    }

    public function test_group_nests_under_the_parent_handle(): void
    {
        $field = $this->field('seo', 'group', [
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'description', 'field' => ['type' => 'text']],
            ],
        ]);

        $values = $this->walker()->extract($field, [
            'title' => 'Page title',
            'description' => 'Page description',
        ]);

        $this->assertSame([
            'seo.title' => 'Page title',
            'seo.description' => 'Page description',
        ], $values);
    }

    public function test_grid_rows_are_indexed_by_position(): void
    {
        $field = $this->field('rows', 'grid', [
            'fields' => [
                ['handle' => 'label', 'field' => ['type' => 'text']],
            ],
        ]);

        $values = $this->walker()->extract($field, [
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);

        $this->assertSame([
            'rows.0.label' => 'First',
            'rows.1.label' => 'Second',
        ], $values);
    }

    public function test_replicator_resolves_each_set_to_its_own_fields(): void
    {
        $field = $this->field('builder', 'replicator', [
            'sets' => [
                'hero' => [
                    'fields' => [
                        ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ],
                ],
                'quote' => [
                    'fields' => [
                        ['handle' => 'text', 'field' => ['type' => 'text']],
                    ],
                ],
            ],
        ]);

        $values = $this->walker()->extract($field, [
            ['type' => 'hero', 'heading' => 'Welcome'],
            ['type' => 'quote', 'text' => 'Said a thing'],
        ]);

        $this->assertSame([
            'builder.0.heading' => 'Welcome',
            'builder.1.text' => 'Said a thing',
        ], $values);
    }

    public function test_replicator_finds_sets_nested_under_a_display_group(): void
    {
        $field = $this->field('builder', 'replicator', [
            'sets' => [
                'main' => [
                    'sets' => [
                        'hero' => [
                            'fields' => [
                                ['handle' => 'heading', 'field' => ['type' => 'text']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $values = $this->walker()->extract($field, [
            ['type' => 'hero', 'heading' => 'Grouped'],
        ]);

        $this->assertSame(['builder.0.heading' => 'Grouped'], $values);
    }

    /**
     * A set type the blueprint no longer declares still indexes its scalars,
     * so renaming a set does not silently drop its content.
     */
    public function test_unknown_replicator_set_falls_back_to_scalars(): void
    {
        $field = $this->field('builder', 'replicator', ['sets' => []]);

        $values = $this->walker()->extract($field, [
            ['type' => 'gone', 'id' => 'abc', 'enabled' => true, 'heading' => 'Orphaned'],
        ]);

        $this->assertSame(['builder.0.heading' => 'Orphaned'], $values);
    }

    public function test_bard_saved_as_html_is_reduced_to_prose(): void
    {
        $values = $this->walker()->extract(
            $this->field('body', 'bard'),
            '<p>Some <strong>bold</strong> text</p>'
        );

        $this->assertSame(['body' => 'Some bold text'], $values);
    }

    public function test_bard_prosemirror_nodes_are_collected_as_text(): void
    {
        $value = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'First'],
                    ['type' => 'text', 'text' => 'second'],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Third']],
            ],
        ];

        $values = $this->walker()->extract($this->field('body', 'bard'), $value);

        $this->assertSame(['body' => 'First second Third'], $values);
    }

    public function test_bard_sets_are_extracted_alongside_prose(): void
    {
        $field = $this->field('body', 'bard', [
            'sets' => [
                'callout' => [
                    'fields' => [
                        ['handle' => 'message', 'field' => ['type' => 'text']],
                    ],
                ],
            ],
        ]);

        $value = [
            [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Intro']],
            ],
            [
                'type' => 'set',
                'attrs' => ['values' => ['type' => 'callout', 'message' => 'Note this']],
            ],
        ];

        $values = $this->walker()->extract($field, $value);

        $this->assertSame([
            'body.0.message' => 'Note this',
            'body' => 'Intro',
        ], $values);
    }

    /**
     * Dispatch is on the declared field type, never the value's shape, so a
     * nested container inside a container resolves at every level.
     */
    public function test_nested_containers_recurse(): void
    {
        $field = $this->field('builder', 'replicator', [
            'sets' => [
                'section' => [
                    'fields' => [
                        [
                            'handle' => 'rows',
                            'field' => [
                                'type' => 'grid',
                                'fields' => [
                                    ['handle' => 'label', 'field' => ['type' => 'text']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $values = $this->walker()->extract($field, [
            [
                'type' => 'section',
                'rows' => [
                    ['label' => 'Deep'],
                ],
            ],
        ]);

        $this->assertSame(['builder.0.rows.0.label' => 'Deep'], $values);
    }

    public function test_assets_field_falls_back_to_stored_path_when_unresolvable(): void
    {
        config(['dexter.assetUrls' => 'path']);

        $values = $this->walker()->extract(
            $this->field('images', 'assets', ['container' => 'missing']),
            ['bg-1.jpg', 'bg-2.jpg']
        );

        $this->assertSame(['images' => ['bg-1.jpg', 'bg-2.jpg']], $values);
    }

    /**
     * The shape follows the field definition rather than the current contents,
     * so it stays stable as content changes.
     */
    public function test_single_file_assets_field_collapses_to_a_string(): void
    {
        config(['dexter.assetUrls' => 'path']);

        $values = $this->walker()->extract(
            $this->field('hero', 'assets', ['container' => 'missing', 'max_files' => 1]),
            ['bg-1.jpg']
        );

        $this->assertSame(['hero' => 'bg-1.jpg'], $values);
    }

    public function test_factory_resolves_handlers_for_special_types_only(): void
    {
        $factory = new FieldTypeFactory();

        foreach (['bard', 'replicator', 'grid', 'group', 'assets', 'markdown', 'entries'] as $type) {
            $this->assertTrue($factory->isSupported($type), $type . ' should have a handler');
        }

        // Ordinary fieldtypes fall through to the walker's scalar extraction.
        $this->assertFalse($factory->isSupported('text'));
        $this->assertNull($factory->make('text'));
    }
}
