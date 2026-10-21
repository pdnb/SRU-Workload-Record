<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\File;

class RichText
{
    private static ?HTMLPurifier $purifier = null;

    /**
     * Clean editor HTML down to the formatting the rich editor can produce.
     * Plain text without markup (legacy entries) is returned unchanged so its line breaks survive.
     */
    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);

        if (! self::isHtml($html)) {
            return $html;
        }

        $clean = trim(self::purifier()->purify($html));

        return self::toPlainText($clean) === '' ? '' : $clean;
    }

    /**
     * Safe HTML for display; legacy plain text is escaped with its line breaks kept.
     */
    public static function render(?string $value): string
    {
        $value = (string) $value;

        if (! self::isHtml($value)) {
            return nl2br(e($value), false);
        }

        return self::sanitize($value);
    }

    public static function toPlainText(?string $html): string
    {
        $text = preg_replace('/<br\s*\/?>|<\/(p|li|ul|ol)>/i', "$0\n", (string) $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/[ \t]+/u", ' ', $text);
        $text = preg_replace("/\s*\n\s*/u", "\n", $text);

        return trim($text);
    }

    private static function isHtml(string $value): bool
    {
        return preg_match('/<\/?[a-z][^>]*>/i', $value) === 1;
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }

        $cachePath = storage_path('framework/cache/htmlpurifier');
        File::ensureDirectoryExists($cachePath);

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,ul,ol,li,a[href]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('AutoFormat.RemoveEmpty', true);

        return self::$purifier = new HTMLPurifier($config);
    }
}
