<?php

namespace BoldMinded\Dexter\Service\Pipeline;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\DexterCore\Service\StopWordRemover;

/**
 * Adds a __full_text property concatenating all indexed text.
 *
 * Required by the file-describe pipeline, and useful on its own for providers
 * configured to search a single attribute.
 */
class FullTextPipeline
{
    private StopWordRemover $stopWordRemover;

    public function __construct(
        private IndexableInterface $indexable,
        private ConfigInterface $config
    ) {
        $this->stopWordRemover = new StopWordRemover();
    }

    public function __invoke(array $values): array
    {
        if ($values === []) {
            return [];
        }

        if ($this->config->get('includeFullText') === false) {
            return $values;
        }

        $values['__full_text'] = $this->flatten($values);

        return $values;
    }

    /**
     * Identity and routing values. They belong in the document as their own
     * properties, but concatenating them into the searchable text hurts
     * relevance -- a query for "desserts" should not match every entry whose
     * URL happens to contain it.
     */
    private const EXCLUDED = [
        'id', 'slug', 'url', 'uri', 'path', 'filename', 'basename',
        'extension', 'collection', 'taxonomy', 'container', 'site',
        'status', 'published', 'date', 'size', 'is_image', '__full_text',
    ];

    private function flatten(array $values): string
    {
        $flat = [];

        foreach ($values as $key => $value) {
            if (in_array($key, self::EXCLUDED, true)) {
                continue;
            }

            $branch = is_array($value) ? $value : [$value];

            array_walk_recursive($branch, function ($leaf) use (&$flat) {
                if ($leaf && is_scalar($leaf) && ! is_numeric($leaf) && ! is_bool($leaf)) {
                    $flat[] = (string) $leaf;
                }
            });
        }

        $text = strip_tags(implode(' ', array_unique($flat)));
        $text = preg_replace('/(?:https?:\/\/|www\.)\S+/i', '', $text);

        $stopWords = $this->config->get('stopWords');

        if (is_array($stopWords) && $stopWords !== []) {
            $text = $this->stopWordRemover->remove($text, $stopWords);
        }

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
