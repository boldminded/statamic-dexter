<?php

namespace BoldMinded\Dexter\Tests\Unit;

use BoldMinded\Dexter\Service\Pipeline\FieldMapPipeline;
use BoldMinded\Dexter\Service\Pipeline\FileDescribePipeline;
use BoldMinded\Dexter\Service\Pipeline\FullTextPipeline;
use BoldMinded\Dexter\Service\Pipeline\ScopePipelines;
use BoldMinded\Dexter\Service\Pipeline\StatusPipeline;
use PHPUnit\Framework\TestCase;

class ScopePipelinesTest extends TestCase
{
    public function test_built_ins_apply_per_scope_without_any_config(): void
    {
        $this->assertSame([
            FieldMapPipeline::class,
            StatusPipeline::class,
            FullTextPipeline::class,
        ], ScopePipelines::build('entries', []));

        $this->assertSame([
            FieldMapPipeline::class,
            FileDescribePipeline::class,
            FullTextPipeline::class,
        ], ScopePipelines::build('files', []));

        // Status is entries-only; describing is files-only.
        $this->assertSame([
            FieldMapPipeline::class,
            FullTextPipeline::class,
        ], ScopePipelines::build('categories', []));

        $this->assertSame([
            FieldMapPipeline::class,
            FullTextPipeline::class,
        ], ScopePipelines::build('users', []));
    }

    public function test_full_text_is_forced_last_even_when_a_site_appends_after_it(): void
    {
        $pipelines = ScopePipelines::build('entries', [
            'pipelines' => [ExampleTestPipeline::class],
        ]);

        $this->assertSame(FullTextPipeline::class, end($pipelines));
    }

    /**
     * A config published before the built-ins moved into ScopePipelines lists
     * them inline, ordered FullText before FileDescribe. Honouring those
     * positions would carry that ordering forward, so the result has to match
     * a clean config exactly.
     */
    public function test_a_legacy_published_list_resolves_like_a_clean_one(): void
    {
        $legacy = [
            'pipelines' => [
                StatusPipeline::class,
                FieldMapPipeline::class,
                FullTextPipeline::class,
                FileDescribePipeline::class,
            ],
        ];

        foreach (['entries', 'categories', 'files', 'users'] as $scope) {
            $this->assertSame(
                ScopePipelines::build($scope, []),
                ScopePipelines::build($scope, $legacy),
                "scope [$scope] should ignore inline built-ins",
            );
        }
    }

    public function test_a_built_in_is_stripped_however_it_is_spelled(): void
    {
        $pipelines = ScopePipelines::build('files', [
            'pipelines' => ['\\' . FileDescribePipeline::class],
        ]);

        $this->assertSame(
            ScopePipelines::build('files', []),
            $pipelines,
            'a leading separator names the same class',
        );
    }

    /**
     * Stripping is scoped: naming a class under a scope key is deliberate, so
     * one that ships built-in for a different scope is kept.
     */
    public function test_a_scope_key_may_add_a_pipeline_built_in_elsewhere(): void
    {
        $pipelines = ScopePipelines::build('files', [
            'filePipelines' => [StatusPipeline::class],
        ]);

        $this->assertSame([
            FieldMapPipeline::class,
            FileDescribePipeline::class,
            StatusPipeline::class,
            FullTextPipeline::class,
        ], $pipelines);
    }

    public function test_scope_additions_run_after_shared_ones(): void
    {
        $pipelines = ScopePipelines::build('entries', [
            'pipelines' => [ExampleTestPipeline::class],
            'entryPipelines' => [OtherTestPipeline::class],
        ]);

        $this->assertSame([
            FieldMapPipeline::class,
            StatusPipeline::class,
            ExampleTestPipeline::class,
            OtherTestPipeline::class,
            FullTextPipeline::class,
        ], $pipelines);
    }

    public function test_a_scope_key_only_applies_to_its_own_scope(): void
    {
        $config = ['filePipelines' => [ExampleTestPipeline::class]];

        $this->assertContains(ExampleTestPipeline::class, ScopePipelines::build('files', $config));
        $this->assertNotContains(ExampleTestPipeline::class, ScopePipelines::build('entries', $config));
    }

    public function test_unusable_entries_are_dropped_rather_than_fatal(): void
    {
        $pipelines = ScopePipelines::build('entries', [
            'pipelines' => ['Definitely\\Not\\A\\Class', 123, null, []],
        ]);

        $this->assertSame(ScopePipelines::build('entries', []), $pipelines);
    }

    public function test_an_unknown_scope_still_gets_the_common_pipelines(): void
    {
        $this->assertSame([
            FieldMapPipeline::class,
            FullTextPipeline::class,
        ], ScopePipelines::build('something_else', []));
    }
}

class ExampleTestPipeline
{
    public function __invoke(array $values): array
    {
        return $values;
    }
}

class OtherTestPipeline
{
    public function __invoke(array $values): array
    {
        return $values;
    }
}
