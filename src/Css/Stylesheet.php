<?php

declare(strict_types=1);

namespace Spark\Pdf\Css;

use DOMDocument;

final class Stylesheet
{
    public static function collect(DOMDocument $document, callable $read, callable $mediaMatches, ?string $base): string
    {
        $css = '';
        foreach ($document->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->tagName);
            if (!in_array($tag, ['style', 'link'], true) || !$mediaMatches($element->getAttribute('media')))
                continue;
            if ($tag === 'style') {
                $css .= self::expand($element->textContent, $base, $read, $mediaMatches, []) . "\n";
            } elseif (!$element->hasAttribute('disabled') && in_array('stylesheet', preg_split('/\s+/', strtolower($element->getAttribute('rel'))) ?: [], true)) {
                $source = self::resolve($element->getAttribute('href'), $base);
                $content = $read($source);
                if ($content !== null)
                    $css .= self::expand($content, dirname($source), $read, $mediaMatches, [$source]) . "\n";
            }
        }
        return $css;
    }

    private static function expand(string $css, ?string $base, callable $read, callable $mediaMatches, array $seen): string
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
        $css = preg_replace_callback('~@import\s+(?:url\(\s*[\'"]?([^\)\'"\s]+)[\'"]?\s*\)|[\'"]([^\'"]+)[\'"])\s*([^;]*);~i', function (array $match) use ($base, $read, $mediaMatches, $seen): string {
            if (count($seen) >= 8 || !$mediaMatches(trim($match[3])))
                return '';
            $source = self::resolve($match[1] !== '' ? $match[1] : $match[2], $base);
            if (in_array($source, $seen, true))
                return '';
            $content = $read($source);
            return $content === null ? '' : self::expand($content, dirname($source), $read, $mediaMatches, [...$seen, $source]);
        }, $css) ?? '';
        return preg_replace_callback('~url\(\s*([\'"]?)(.*?)\1\s*\)~i', static function (array $match) use ($base): string {
            $source = self::resolve(trim($match[2]), $base);
            return 'url("' . str_replace(['\\', '"'], ['\\\\', '\\"'], $source) . '")';
        }, $css) ?? '';
    }

    private static function resolve(string $source, ?string $base): string
    {
        if ($source === '' || $source[0] === '#' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $source))
            return $source;
        if ($base !== null && preg_match('~^https?://~i', $base)) {
            $parts = parse_url($base);
            if (str_starts_with($source, '//'))
                return $parts['scheme'] . ':' . $source;
            if (str_starts_with($source, '/'))
                return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $source;
            return rtrim($base, '/') . '/' . $source;
        }
        if (preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $source))
            return $source;
        $directory = $base ?? getcwd();
        if (!preg_match('~^(?:/|[A-Za-z]:[\\\\/]|[a-z][a-z0-9+.-]*:)~i', $directory))
            $directory = getcwd() . '/' . $directory;
        return rtrim($directory, '/\\') . '/' . $source;
    }
}
