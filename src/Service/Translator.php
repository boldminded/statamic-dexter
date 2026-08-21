<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\TranslatorInterface;

/**
 * Resolves dexter-core's message keys against the addon's language files,
 * falling back to the key itself so a missing translation degrades to
 * something readable rather than an empty string.
 */
class Translator implements TranslatorInterface
{
    public function get(string $key): string
    {
        $translated = trans('dexter::messages.' . $key);

        if (is_string($translated) && $translated !== 'dexter::messages.' . $key) {
            return $translated;
        }

        return $key;
    }
}
