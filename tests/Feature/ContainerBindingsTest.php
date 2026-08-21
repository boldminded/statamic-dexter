<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\Dexter\Service\Fields\FieldTypeFactory;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use BoldMinded\Dexter\Service\IndexableFactory;
use BoldMinded\Dexter\Service\IndexConfig;
use BoldMinded\Dexter\Service\Indexer\ContentTypeFactory;
use BoldMinded\Dexter\Tests\TestCase;

/**
 * The registries are shared rather than rebuilt per use.
 *
 * A bulk reindex constructs one indexable per item, so a factory rebuilt on
 * each construction would rebuild its whole handler set per document.
 */
class ContainerBindingsTest extends TestCase
{
    public function test_registries_are_singletons(): void
    {
        foreach ([ContentTypeFactory::class, FieldTypeFactory::class, FieldValueWalker::class, IndexableFactory::class] as $class) {
            $this->assertSame(app($class), app($class), $class . ' should be shared');
        }
    }

    public function test_walker_and_indexable_factory_receive_the_shared_registries(): void
    {
        $this->assertSame(
            app(FieldTypeFactory::class),
            $this->readPrivate(app(FieldValueWalker::class), 'types')
        );

        $this->assertSame(
            app(ContentTypeFactory::class),
            $this->readPrivate(app(IndexableFactory::class), 'types')
        );
    }

    /**
     * IndexConfig stays newable with a plain array, and still resolves the
     * shared registry rather than building its own.
     */
    public function test_index_config_uses_the_shared_registry_however_it_is_built(): void
    {
        $shared = app(ContentTypeFactory::class);

        $this->assertSame($shared, $this->readPrivate(IndexConfig::make(), 'types'));
        $this->assertSame($shared, $this->readPrivate(new IndexConfig([]), 'types'));
    }

    public function test_index_config_accepts_an_explicit_registry(): void
    {
        $own = new ContentTypeFactory();

        $this->assertSame($own, $this->readPrivate(new IndexConfig([], $own), 'types'));
        $this->assertNotSame(app(ContentTypeFactory::class), $own);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }
}
