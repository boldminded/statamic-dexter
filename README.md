# Dexter

**Algolia and Meilisearch indexing for Statamic, with nested field extraction, document parsing, and AI enrichment.**

Dexter indexes your Statamic entries, terms, assets, and users into Algolia or Meilisearch. It extracts content from nested fields such as Bard, Replicator, and Grid, parses text out of PDF and Office documents, and can enrich records with AI-generated summaries, keywords, and embeddings.

## Installation

Install the addon via composer:

```
composer require boldminded/dexter-statamic
```

Install the addon into Statamic, which publishes the config file:

```
php please addon:install boldminded/dexter-statamic
```

If you need to publish or re-publish the config file on its own:

```
php please vendor:publish --tag=dexter-config
```

This creates `config/dexter.php` in your project.

## Documentation

Full documentation, including configuration, index setup, templating tags, console commands, and AI enrichment, is available at:

**[docs.boldminded.com/dexter/docs-statamic](https://docs.boldminded.com/dexter/docs-statamic)**
