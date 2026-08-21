<?php

namespace BoldMinded\Dexter\Service\Pipeline;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\IndexableFileInterface;
use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\DexterCore\Service\DocumentParsers\FileParserFactory;
use BoldMinded\DexterCore\Service\FileDescriber;
use BoldMinded\Dexter\Service\FileUpdater;

/**
 * Reads an asset's contents and, with an AI provider configured, describes it.
 *
 * This is what makes a PDF findable by the words inside it and an image
 * findable by what it depicts rather than its filename. The generated values
 * are added to the indexed document and, when the config asks, written back
 * onto the asset itself.
 *
 * Only assets reach this pipeline; entries, terms and users pass straight
 * through. Each described file costs an API call, so both parsers are off by
 * default.
 */
class FileDescribePipeline
{
    public function __construct(
        private IndexableInterface $indexable,
        private ConfigInterface $config,
    ) {
    }

    public function __invoke(array $values): array
    {
        if ($values === [] || ! $this->indexable instanceof IndexableFileInterface) {
            return $values;
        }

        $which = $this->indexable->isImage() ? 'Image' : 'Document';

        if ($this->config->get(sprintf('parse%sContents.enabled', $which)) !== true) {
            return $values;
        }

        $logger = app(\BoldMinded\DexterCore\Contracts\LoggerInterface::class);

        $describer = new FileDescriber(
            config: $this->config,
            logger: $logger,
            fileParserFactory: new FileParserFactory($this->config),
        );

        try {
            $described = $describer->describe($this->indexable);
        } catch (\Throwable $e) {
            // An AI outage or an unreadable file should cost the enrichment,
            // not the whole document.
            $logger->error(sprintf(
                'Could not describe [%s]: %s',
                $this->indexable->getUniqueId(),
                $e->getMessage()
            ));

            return $values;
        }

        if ($described === '') {
            // FileDescriber catches its own exceptions and logs them at debug
            // level, so a genuine failure -- expired key, no credits, an
            // unreachable provider -- is indistinguishable here from having
            // nothing to say. Re-run it to surface the real reason, because a
            // silent no-op leaves an operator with no idea why enrichment
            // never appears.
            $this->explainSilence($logger);

            return $values;
        }

        $generated = $this->parse($describer, $described);

        if ($generated === []) {
            return $values;
        }

        // Indexed regardless of whether it is persisted, so the enrichment is
        // searchable even when the site keeps its assets untouched.
        if (($generated['description'] ?? '') !== '') {
            $values['__ai_description'] = $generated['description'];
        }

        if (($generated['altText'] ?? '') !== '') {
            $values['__ai_alt_text'] = $generated['altText'];
        }

        if (($generated['tags'] ?? []) !== []) {
            $values['__ai_tags'] = $generated['tags'];
        }

        // Fold into the searchable text, which is the point of describing.
        $values['__full_text'] = trim(
            ($values['__full_text'] ?? '')
            . ' ' . ($generated['description'] ?? '')
            . ' ' . implode(' ', $generated['tags'] ?? [])
        );

        (new FileUpdater($this->indexable, $this->config, $logger))->update($generated);

        return $values;
    }

    /**
     * Ask the parser again without the catch, purely to log why it failed.
     *
     * Only reached when describing produced nothing, so it costs one extra
     * attempt on an already-failing path and never on the happy one.
     */
    private function explainSilence($logger): void
    {
        try {
            (new FileParserFactory($this->config))->describeFile(
                $this->indexable->getAbsolutePath() ?: $this->indexable->getAbsoluteUrl(),
                $this->indexable->getMimeType(),
                $this->config->get(sprintf(
                    'parse%sContents',
                    $this->indexable->isImage() ? 'Image' : 'Document'
                )) ?: [],
            );
        } catch (\Throwable $e) {
            $logger->error(sprintf(
                'Describing [%s] produced nothing: %s',
                $this->indexable->getUniqueId(),
                $e->getMessage()
            ));

            return;
        }

        $logger->debug(sprintf(
            'Describing [%s] produced nothing, with no error reported.',
            $this->indexable->getUniqueId()
        ));
    }

    /**
     * The provider is asked for a JSON object, but a model may answer with
     * prose. A plain string is still a usable description.
     *
     * @return array{description?: string, altText?: string, tags?: array}
     */
    private function parse(FileDescriber $describer, string $described): array
    {
        if (! $describer->isJson($described)) {
            return ['description' => $described];
        }

        $decoded = json_decode($described, true);

        if (! is_array($decoded)) {
            return ['description' => $described];
        }

        return [
            'description' => (string) ($decoded['description'] ?? ''),
            'altText' => (string) ($decoded['altText'] ?? ''),
            'tags' => array_values(array_filter((array) ($decoded['tags'] ?? []))),
        ];
    }
}
