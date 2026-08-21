<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Environment prefix
    |--------------------------------------------------------------------------
    |
    | Prefixed to every resolved index name so environments never share index
    | storage. A "content" index in local becomes "local_content".
    |
    */

    'env' => env('DEXTER_ENV', env('APP_ENV', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Set false to stop indexing on content save and delete. Console commands
    | still run, which is useful while seeding or migrating content.
    |
    */

    'enabled' => env('DEXTER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Queue indexing
    |--------------------------------------------------------------------------
    |
    | When true, saves dispatch a queued job instead of writing to the search
    | provider inline, so a slow provider never blocks a content save.
    | Requires a running queue worker. Deletes always run inline -- they are a
    | single cheap call, and queueing them would race the content's removal.
    |
    */

    'queue' => env('DEXTER_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Index suffix
    |--------------------------------------------------------------------------
    |
    | Appended to every resolved index name. Useful for multi-site setups.
    | May be a closure, but note that a closure prevents `config:cache`.
    |
    */

    'suffix' => '',

    /*
    |--------------------------------------------------------------------------
    | Default result limit
    |--------------------------------------------------------------------------
    |
    | Applied when a search does not request its own limit, so an empty query
    | can never pull an unbounded result set.
    |
    */

    'searchLimit' => 100,

    /*
    |--------------------------------------------------------------------------
    | Public search endpoint
    |--------------------------------------------------------------------------
    |
    | Exposes /!/dexter/search for client-side search interfaces.
    |
    | Off by default, and deliberately so: the endpoint is unauthenticated, so
    | anything in a configured index becomes publicly readable through it.
    | Enable it only once you are satisfied the indexed fields are safe to
    | serve to anyone -- user records in particular are rarely appropriate.
    |
    */

    'publicSearch' => env('DEXTER_PUBLIC_SEARCH', false),

    /*
    |--------------------------------------------------------------------------
    | Primary key
    |--------------------------------------------------------------------------
    |
    | The document property holding each record's unique id. Algolia's
    | convention is "objectID"; Meilisearch only permits A-Z, a-z, 0-9, - and _
    | in a primary key, so "objectID" is valid there too.
    |
    | May be a closure receiving ($indexName, $values) to vary it per index.
    |
    */

    'primaryKey' => 'objectID',

    /*
    |--------------------------------------------------------------------------
    | Full text
    |--------------------------------------------------------------------------
    |
    | Adds a __full_text property containing all indexed text concatenated.
    |
    */

    'includeFullText' => true,

    /*
    |--------------------------------------------------------------------------
    | Asset URLs
    |--------------------------------------------------------------------------
    |
    | How an assets field is indexed. Statamic stores only the path, so the
    | asset is resolved against its field's configured container.
    |
    |   'path'     bg-1.jpg                              (as stored)
    |   'url'      /assets/bg-1.jpg                      (relative)
    |   'absolute' http://example.com/assets/bg-1.jpg    (includes the domain)
    |
    | Defaults to 'absolute', matching the EE and Craft builds. Note that an
    | absolute URL bakes the current domain into every document, so an index
    | built on staging points at staging everywhere; switch to 'url' if the
    | index is shared across environments.
    |
    */

    'assetUrls' => 'absolute',

    /*
    |--------------------------------------------------------------------------
    | Inline image limit
    |--------------------------------------------------------------------------
    |
    | AI image description works by giving the provider a URL it fetches
    | itself, which fails on any site it cannot reach -- local development,
    | staging behind auth, an intranet. Dexter inlines the image as a data URI
    | in that case, and skips anything larger than this. Providers cap request
    | bodies, and base64 inflates by roughly a third.
    |
    */

    'maxInlineImageBytes' => 15 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Local host suffixes
    |--------------------------------------------------------------------------
    |
    | Extra hostname suffixes an AI provider cannot reach, so images on them
    | are inlined rather than fetched by URL. Appended to the reserved TLDs
    | Dexter already knows -- .test, .localhost, .local, .ddev and similar --
    | so adding your own convention keeps the built-in protection.
    |
    | A leading dot is optional: 'internal' and '.internal' behave the same.
    |
    |   'localHostSuffixes' => ['internal', '.corp.example.com'],
    |
    */

    'localHostSuffixes' => [],

    /*
    |--------------------------------------------------------------------------
    | AI provider
    |--------------------------------------------------------------------------
    |
    | Used to describe documents and images during indexing, and to generate
    | embeddings when semantic search is on. Leave the key empty to disable
    | every AI feature -- Dexter falls back to a no-op provider rather than
    | failing, so an unconfigured site simply indexes without enrichment.
    |
    */

    'aiProvider' => env('AI_PROVIDER', 'openAi'),
    'embeddingProvider' => env('AI_EMBEDDING_PROVIDER', 'openAi'),

    'openAi' => [
        'key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'embedModel' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
        'temperature' => 1.0,
        'frequencyPenalty' => 0.0,
        'presencePenalty' => 0.0,
        'maxTokens' => 300,
    ],

    'openRouter' => [
        'key' => env('OPENROUTER_API_KEY', ''),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o'),
        'embedModel' => env('OPENROUTER_EMBED_MODEL', 'openai/text-embedding-3-small'),
        'temperature' => 1.0,
        'frequencyPenalty' => 0.0,
        'presencePenalty' => 0.0,
        'maxTokens' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document parsing
    |--------------------------------------------------------------------------
    |
    | PDF, Word, CSV, XML and text assets are read at index time and their
    | contents added to __full_text, so a search can match words inside a file
    | rather than only its filename.
    |
    | With an AI provider configured, the extracted text is also summarised.
    | The create* flags write that summary back to the asset itself; the
    | replace* flags let a later re-index overwrite what an author has since
    | edited, so they default to false.
    |
    | Field handles are Statamic blueprint handles on the asset container's
    | blueprint. Dot notation reaches nested fields.
    |
    */

    'parseDocumentContents' => [
        'enabled' => false,
        'describePrompt' => 'Summarize this text.',
        'maxPages' => 5,
        'maxWords' => 1000,

        'createAltText' => true,
        'createDescription' => true,
        'createCategories' => true,

        'replaceAltText' => false,
        'replaceDescription' => false,
        'replaceCategories' => false,

        'altTextFieldHandle' => 'alt',
        'descriptionFieldHandle' => '',
        'categoriesFieldHandle' => '',

        // Taxonomy handle that generated keywords become terms in. Leave
        // empty to keep them on the document only.
        'taxonomyHandle' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Image parsing
    |--------------------------------------------------------------------------
    |
    | Sends jpg, png, gif and webp assets to the AI provider to describe what
    | the image shows, including any text in it, and adds that to __full_text.
    | This is what makes images findable by their contents rather than their
    | filename.
    |
    | Costs one API call per image per index, so it is off by default.
    |
    */

    'parseImageContents' => [
        'enabled' => false,
        'describePrompt' => 'Describe this image in detail, including any text that may be present.',

        'createAltText' => true,
        'createDescription' => true,
        'createCategories' => true,

        'replaceAltText' => false,
        'replaceDescription' => false,
        'replaceCategories' => false,

        'altTextFieldHandle' => 'alt',
        'descriptionFieldHandle' => '',
        'categoriesFieldHandle' => '',

        'taxonomyHandle' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic search
    |--------------------------------------------------------------------------
    |
    | Meilisearch: generates embeddings from __full_text at index time, so a
    | query can match meaning rather than wording. Requires includeFullText.
    | Queries must pass a hybrid filter naming the embedder:
    |
    |     hybrid: { embedder: "fullText", semanticRatio: 1.0 }
    |
    | Algolia: sets the index mode to neuralSearch, overriding drivers.algolia
    | .mode. Needs an Elevate plan or greater.
    |
    */

    'enableSemanticSearch' => false,

    /*
    |--------------------------------------------------------------------------
    | Minimum ranking score
    |--------------------------------------------------------------------------
    |
    | Results scoring below this are dropped after the search returns. The
    | scales differ per provider: Meilisearch is roughly 0.5–1.5, and needs
    | showRankingScore: true on the query; Algolia needs getRankingInfo: true
    | and neural search enabled. null disables the filtering.
    |
    */

    'minimumRankingScore' => null,

    /*
    |--------------------------------------------------------------------------
    | Advanced search
    |--------------------------------------------------------------------------
    |
    | Runs the results through the AI provider a second time to filter them
    | against the query, honouring logic like NOT and OR that a plain index
    | query cannot express. Only ever removes results, never adds them.
    | Requires enableSemanticSearch.
    |
    */

    'enableAdvancedSearch' => false,

    /*
    |--------------------------------------------------------------------------
    | Indexable statuses
    |--------------------------------------------------------------------------
    |
    | Entry statuses that may be indexed. Statamic's statuses are published,
    | draft, scheduled and expired. Only entries carry a status; terms, assets
    | and users are unaffected.
    |
    */

    'statuses' => ['published'],

    /*
    |--------------------------------------------------------------------------
    | Stop words
    |--------------------------------------------------------------------------
    |
    | Removed from the generated __full_text property. Set to an empty array to
    | keep all words -- worth doing on a multilingual site, where an English
    | list strips nothing useful from other languages and may remove words that
    | are meaningful in them.
    |
    */

    'stop_words' => [
        'a', 'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and', 'any', 'are',
        'as', 'at', 'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but',
        'by', 'could', 'did', 'do', 'does', 'doing', 'down', 'during', 'each', 'few', 'for', 'from',
        'further', 'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'herself', 'him',
        'himself', 'his', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'itself', 'let\'s',
        'me', 'more', 'most', 'my', 'myself', 'nor', 'of', 'on', 'once', 'only', 'or', 'other',
        'ought', 'our', 'ours', 'ourselves', 'out', 'over', 'own', 'same', 'she', 'should', 'so',
        'some', 'such', 'than', 'that', 'the', 'their', 'theirs', 'them', 'themselves', 'then',
        'there', 'these', 'they', 'this', 'those', 'through', 'to', 'too', 'under', 'until', 'up',
        'very', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom',
        'why', 'will', 'with', 'would', 'you', 'your', 'yours', 'yourself', 'yourselves',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipelines
    |--------------------------------------------------------------------------
    |
    | Each runs in order over the extracted values, receiving the previous
    | stage's output. A pipeline returning an empty array stops the document
    | from being indexed.
    |
    | Add your own class here to customise indexed values. Each is constructed
    | with ($indexable, $config) and invoked with the values array.
    |
    | The built-in pipelines are not listed here. They live in ScopePipelines
    | so trimming this config cannot drop one, and so a new built-in reaches
    | existing installs without republishing. Per scope, they are:
    |
    |   every scope   FieldMapPipeline, then FullTextPipeline last
    |   entries       StatusPipeline
    |   files         FileDescribePipeline
    |
    | FullTextPipeline is always appended last, after anything added below --
    | it concatenates what the earlier stages leave behind, so a pipeline
    | running after it would not be searchable.
    |
    | `pipelines` applies to every scope. The scope-specific keys are additive
    | on top of it, and are the place for anything that should only run for
    | one content type -- the equivalent keys in the EE and Craft builds.
    |
    */

    'pipelines' => [
        // \App\Dexter\MyPipeline::class,
    ],

    'entryPipelines' => [],
    'categoryPipelines' => [],
    'filePipelines' => [],
    'userPipelines' => [],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Credentials per provider. Statamic ships no Meilisearch driver, so
    | Meilisearch support is available only through this addon.
    |
    */

    'drivers' => [

        'algolia' => [
            'app_id' => env('ALGOLIA_APP_ID', ''),
            'secret' => env('ALGOLIA_SECRET', ''),
            // keywordSearch, or neuralSearch on the Premium/Elevate plans.
            // enableSemanticSearch overrides this to neuralSearch.
            'mode' => env('ALGOLIA_MODE', 'keywordSearch'),
        ],

        'meilisearch' => [
            'url' => env('MEILISEARCH_URL', 'http://localhost:7700'),
            'key' => env('MEILISEARCH_KEY', ''),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Indexes
    |--------------------------------------------------------------------------
    |
    | Mirrors the structure of config/statamic/search.php. Each index declares
    | a driver and a set of searchables, using Statamic's own prefixed handle
    | syntax: collection:, taxonomy:, assets:, and users.
    |
    | Unlike Statamic's native search, each searchable declares its own field
    | list, and those fields support dot-notation and wildcards so nested
    | content (Replicator, Bard, Grid, Group) is reachable without custom code.
    |
    */
    'indexes' => [
        'content' => [ // This is the index name, choose whatever you want
            'driver' => env('DEXTER_DRIVER', 'algolia'),
            'searchables' => [
                /* Example:
                'collection:pages' => [
                    'fields' => [
                        'title',
                        'subtitle',
                        'permalink',

                        // Nested replicator. The wildcard spans every set
                        // type; sets without the field contribute nothing.
                        'page_builder.*.title',
                        'page_builder.*.subtitle',
                        'page_builder.*.image',
                        'page_builder.*.text_column_*',
                        'page_builder.*.contact_info_title',
                        'page_builder.*.contact_form_title',

                        // Prefixed group import
                        'contact_settings.contact_*',
                    ],
                ],
                'collection:something_else' => [
                    'fields' => [
                        'title',
                        'subtitle',
                        'permalink',
                    ],
                ],
                */
            ],
        ],
    ],

];
