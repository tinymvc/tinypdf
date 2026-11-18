<?php

declare(strict_types=1);

namespace Spark\Pdf\Css;

use DOMElement;
use DOMText;

final class Selector
{
    public static function split(string $value, string $delimiter = ','): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\') {
                $i++;
            } elseif ($quote !== null) {
                if ($char === $quote)
                    $quote = null;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === $delimiter && $depth === 0) {
                $parts[] = trim(substr($value, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $parts[] = trim(substr($value, $start));
        return $parts;
    }

    public static function parse(string $selector): array
    {
        $parts = [];
        $compound = '';
        $combinator = ' ';
        $depth = 0;
        $quote = null;
        for ($i = 0, $length = strlen($selector); $i < $length; $i++) {
            $char = $selector[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $compound .= $char . $selector[++$i];
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote)
                    $quote = null;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($depth === 0 && (ctype_space($char) || str_contains('>+~', $char))) {
                if ($compound !== '') {
                    $parts[] = [$compound, $combinator];
                    $compound = '';
                    $combinator = ' ';
                }
                if (!ctype_space($char))
                    $combinator = $char;
                continue;
            }
            $compound .= $char;
        }
        if ($depth !== 0 || $quote !== null || $compound === '')
            return [];
        $parts[] = [$compound, $combinator];
        return $parts;
    }

    private static function tokens(string $compound): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($compound);
        while ($offset < $length) {
            if ($compound[$offset] === '[') {
                if (!preg_match('/\G\[\s*([\w:-]+)\s*(?:(~=|\|=|\^=|\$=|\*=|=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*([is])?\s*)?\]/iu', $compound, $match, PREG_UNMATCHED_AS_NULL, $offset))
                    return [];
                $tokens[] = ['attribute', $match[1], [$match[2] ?? '', $match[3] ?? $match[4] ?? $match[5] ?? '', strtolower($match[6] ?? '')]];
                $offset += strlen($match[0]);
                continue;
            }
            if (!preg_match('/\G([#.:]?)(\*|[\p{L}\p{N}\p{M}_-]+)/u', $compound, $match, 0, $offset))
                return [];
            $offset += strlen($match[0]);
            $argument = '';
            if ($match[1] === ':' && ($compound[$offset] ?? '') === '(') {
                $start = ++$offset;
                $depth = 1;
                $quote = null;
                while ($offset < $length && $depth > 0) {
                    $char = $compound[$offset];
                    if ($char === '\\') {
                        $offset += 2;
                        continue;
                    }
                    if ($quote !== null) {
                        if ($char === $quote)
                            $quote = null;
                    } elseif ($char === '"' || $char === "'") {
                        $quote = $char;
                    } elseif ($char === '(') {
                        $depth++;
                    } elseif ($char === ')') {
                        $depth--;
                    }
                    $offset++;
                }
                if ($depth !== 0)
                    return [];
                $argument = substr($compound, $start, $offset - $start - 1);
            }
            $tokens[] = [$match[1], $match[2], $argument];
        }
        return $tokens;
    }

    public static function specificity(string $selector): array
    {
        $score = [0, 0, 0];
        foreach (self::parse(trim($selector)) as [$compound]) {
            foreach (self::tokens($compound) as [$type, $name, $argument]) {
                if ($type === '#') {
                    $score[0]++;
                } elseif ($type === ':' && strtolower($name) === 'where') {
                    continue;
                } elseif ($type === ':' && in_array(strtolower($name), ['is', 'not'], true)) {
                    $nested = array_map(self::specificity(...), self::split($argument));
                    $maximum = max($nested ?: [[0, 0, 0]]);
                    foreach ($maximum as $i => $value)
                        $score[$i] += $value;
                } elseif ($type !== '') {
                    $score[1]++;
                } elseif ($name !== '*') {
                    $score[2]++;
                }
            }
        }
        return $score;
    }

    public static function matches(DOMElement $element, array $parts, ?int $index = null): bool
    {
        $index ??= count($parts) - 1;
        if ($index < 0)
            return false;
        [$compound, $combinator] = $parts[$index];
        if (!self::compound($element, $compound))
            return false;
        if ($index === 0)
            return true;
        if ($combinator === '+' || $combinator === '~') {
            for ($node = $element->previousSibling; $node !== null; $node = $node->previousSibling) {
                if (!$node instanceof DOMElement)
                    continue;
                if (self::matches($node, $parts, $index - 1))
                    return true;
                if ($combinator === '+')
                    return false;
            }
            return false;
        }
        for ($parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (self::matches($parent, $parts, $index - 1))
                return true;
            if ($combinator === '>')
                break;
        }
        return false;
    }

    private static function compound(DOMElement $element, string $compound): bool
    {
        $tokens = self::tokens($compound);
        if (!$tokens)
            return false;
        foreach ($tokens as [$type, $name, $argument]) {
            $matches = match ($type) {
                '' => $name === '*' || strtolower($name) === strtolower($element->tagName),
                '#' => $element->getAttribute('id') === $name,
                '.' => in_array($name, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true),
                'attribute' => self::attribute($element, $name, $argument),
                ':' => self::pseudo($element, strtolower($name), $argument),
                default => false,
            };
            if (!$matches)
                return false;
        }
        return true;
    }

    private static function attribute(DOMElement $element, string $name, array $argument): bool
    {
        if (!$element->hasAttribute($name))
            return false;
        [$operator, $expected, $flag] = $argument;
        $actual = $element->getAttribute($name);
        if ($flag === 'i') {
            $actual = strtolower($actual);
            $expected = strtolower($expected);
        }
        return match ($operator) {
            '' => true,
            '=' => $actual === $expected,
            '~=' => $expected !== '' && in_array($expected, preg_split('/\s+/', $actual) ?: [], true),
            '|=' => $actual === $expected || str_starts_with($actual, $expected . '-'),
            '^=' => $expected !== '' && str_starts_with($actual, $expected),
            '$=' => $expected !== '' && str_ends_with($actual, $expected),
            '*=' => $expected !== '' && str_contains($actual, $expected),
            default => false,
        };
    }

    private static function pseudo(DOMElement $element, string $name, string $argument): bool
    {
        if (in_array($name, ['is', 'where', 'not'], true)) {
            foreach (self::split($argument) as $selector) {
                if (self::matches($element, self::parse($selector)))
                    return $name !== 'not';
            }
            return $name === 'not';
        }
        if ($name === 'empty') {
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement || ($child instanceof DOMText && $child->nodeValue !== ''))
                    return false;
            }
            return true;
        }
        if ($name === 'lang') {
            for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
                if ($node->hasAttribute('lang')) {
                    $language = strtolower($node->getAttribute('lang'));
                    $expected = strtolower(trim($argument, " \t\"'"));
                    return $language === $expected || str_starts_with($language, $expected . '-');
                }
            }
            return false;
        }
        $sameType = str_ends_with($name, 'of-type');
        return match ($name) {
            'root' => $element === $element->ownerDocument->documentElement,
            'first-child', 'first-of-type' => self::index($element, $sameType) === 1,
            'last-child', 'last-of-type' => self::index($element, $sameType, true) === 1,
            'only-child', 'only-of-type' => self::index($element, $sameType) === 1 && self::index($element, $sameType, true) === 1,
            'nth-child', 'nth-of-type' => self::nth(self::index($element, $sameType), $argument),
            'nth-last-child', 'nth-last-of-type' => self::nth(self::index($element, $sameType, true), $argument),
            'link', 'any-link' => in_array(strtolower($element->tagName), ['a', 'area'], true) && $element->hasAttribute('href'),
            'checked' => $element->hasAttribute('checked') || $element->hasAttribute('selected'),
            'disabled' => $element->hasAttribute('disabled'),
            default => false,
        };
    }

    private static function index(DOMElement $element, bool $sameType, bool $reverse = false): int
    {
        $index = 1;
        for ($node = $reverse ? $element->nextSibling : $element->previousSibling; $node !== null; $node = $reverse ? $node->nextSibling : $node->previousSibling) {
            if ($node instanceof DOMElement && (!$sameType || strtolower($node->tagName) === strtolower($element->tagName)))
                $index++;
        }
        return $index;
    }

    private static function nth(int $index, string $expression): bool
    {
        $expression = preg_replace('/\s+/', '', strtolower($expression));
        $expression = match ($expression) { 'odd' => '2n+1', 'even' => '2n', default => $expression };
        if (preg_match('/^[+-]?\d+$/', $expression))
            return $index === (int) $expression;
        if (!preg_match('/^([+-]?\d*)n([+-]\d+)?$/', $expression, $parts))
            return false;
        $step = match ($parts[1]) { '', '+' => 1, '-' => -1, default => (int) $parts[1] };
        $offset = (int) ($parts[2] ?? 0);
        return $step === 0 ? $index === $offset : ($index - $offset) * $step >= 0 && ($index - $offset) % $step === 0;
    }
}
