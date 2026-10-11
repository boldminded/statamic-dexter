# Dexter

**Algolia and Meilisearch indexing for Statamic, with nested field extraction, document parsing, and AI enrichment.**

Statamic ships with a native Algolia driver, but it’s a basic connection and little more. Dexter goes much further. It makes your entire site searchable, tolerant of typos, and aware of context, with your choice of Algolia or Meilisearch behind it. It indexes entries, terms, assets, and users, digs into nested content most search tools skip, reads your documents and images, and can enrich records with AI so visitors find what they mean, not just what they typed.

- **Your choice of engine:** Works with Algolia or Meilisearch, so you aren’t locked into one provider
- **Typo-friendly, context-based search:** Visitors find the right content even when they misspell or phrase things loosely
- **Everything is searchable:** Indexes entries, terms, assets, and users
- **Nested field extraction:** Pulls content out of Bard, Replicator, and Grid fields
- **Document parsing:** Extracts text from PDF and Office documents
- **Image understanding:** Parses and describes images so they show up in results
- **AI enrichment:** Adds generated summaries, keywords, and embeddings to your records

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
