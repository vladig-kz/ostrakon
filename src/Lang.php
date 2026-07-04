<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Lang — text localization.
 *
 * Translation files live in /lang and return a 'key' => 'string' array. Among the keys
 * are metadata: '_language_code' (the language code stored in groups.lang) and
 * '_language_name' (the display name for the panel's language switcher).
 *
 * Language order (for the panel) = sorted FILE NAMES (e.g. 01-ru.php, 02-en.php) — change
 * it by renaming. The language code comes from '_language_code' INSIDE the file, NOT from
 * the name, so renaming doesn't break the binding.
 *
 * Parameter substitution: placeholders like {name} are replaced with values.
 */
final class Lang
{
    /** @var array<string, array{name:string, strings:array<string,string>}>|null code => data (in file order) */
    private static ?array $langs = null;

    /**
     * Get a localized string.
     *
     * @param array<string, string|int|float> $params values to substitute for {name}
     */
    public static function get(string $key, string $lang = 'ru', array $params = []): string
    {
        $langs = self::load();
        if (!isset($langs[$lang])) {
            $lang = array_key_first($langs) ?? ''; // unknown language → first available
        }

        $strings = $lang !== '' ? $langs[$lang]['strings'] : [];
        $text = $strings[$key] ?? $key; // no translation → the key itself (visible while debugging)

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** Whether a translation key actually exists (to render optional bits like field hints). */
    public static function has(string $key, string $lang = 'ru'): bool
    {
        $langs = self::load();
        if (!isset($langs[$lang])) {
            $lang = array_key_first($langs) ?? '';
        }
        return $lang !== '' && isset($langs[$lang]['strings'][$key]);
    }

    /**
     * Languages available for the switcher: code => name, in file order.
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        $out = [];
        foreach (self::load() as $code => $info) {
            $out[$code] = $info['name'];
        }
        return $out;
    }

    /**
     * Load (and cache) all languages. The key is the code from '_language_code'.
     *
     * @return array<string, array{name:string, strings:array<string,string>}>
     */
    private static function load(): array
    {
        if (self::$langs !== null) {
            return self::$langs;
        }
        self::$langs = [];

        $root  = defined('OSTRAKON_ROOT') ? OSTRAKON_ROOT : dirname(__DIR__);
        $files = glob($root . '/lang/*.php') ?: [];
        sort($files); // language order = file-name order

        foreach ($files as $file) {
            $data = require $file;
            if (!is_array($data)) {
                continue;
            }
            // Code from metadata; if absent, fall back to the file name (01-ru → ru).
            $code = (string) ($data['_language_code'] ?? preg_replace('/^\d+-/', '', basename($file, '.php')));
            if ($code === '') {
                continue;
            }
            $name = (string) ($data['_language_name'] ?? $code);
            self::$langs[$code] = ['name' => $name, 'strings' => $data];
        }

        return self::$langs;
    }
}
