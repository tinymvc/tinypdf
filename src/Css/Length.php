<?php

declare(strict_types=1);

namespace Spark\Pdf\Css;

use InvalidArgumentException;

final class Length
{
    private int $index = 0;

    private function __construct(private array $tokens, private float $base)
    {
    }

    public static function parse(string $value, float $fontSize, float $rootSize, float $viewportW, float $viewportH): float|string|null
    {
        $value = strtolower(trim($value));
        if (preg_match('/^([+-]?(?:\d*\.)?\d+)(px|pt|mm|cm|in|pc|q|em|rem|vw|vh|vmin|vmax|%)?$/', $value, $match)) {
            $number = (float) $match[1];
            return match ($match[2] ?? 'px') {
                '%' => $match[1] . '%',
                'pt' => $number,
                'mm' => $number * 72 / 25.4,
                'cm' => $number * 72 / 2.54,
                'q' => $number * 72 / 101.6,
                'in' => $number * 72,
                'pc' => $number * 12,
                'em' => $number * $fontSize,
                'rem' => $number * $rootSize,
                'vw' => $number * $viewportW / 100,
                'vh' => $number * $viewportH / 100,
                'vmin' => $number * min($viewportW, $viewportH) / 100,
                'vmax' => $number * max($viewportW, $viewportH) / 100,
                default => $number * .75,
            };
        }
        if (!preg_match('/^(?:calc|min|max|clamp)\(/', $value))
            return null;
        $canonical = preg_replace_callback('/((?:\d*\.)?\d+)(px|pt|mm|cm|in|pc|q|em|rem|vw|vh|vmin|vmax)\b/', static function (array $match) use ($fontSize, $rootSize, $viewportW, $viewportH): string {
            return sprintf('%.8Fpt', self::parse($match[0], $fontSize, $rootSize, $viewportW, $viewportH));
        }, $value);
        try {
            $resolved = self::evaluate($canonical, 100.0);
            return str_contains($canonical, '%') ? $canonical : $resolved;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function resolve(float|string|null $value, float $base): float
    {
        if (!is_string($value))
            return $value ?? 0.0;
        if (preg_match('/^[+-]?(?:\d*\.)?\d+%$/', $value))
            return (float) $value * $base / 100;
        try {
            return self::evaluate($value, $base);
        } catch (InvalidArgumentException) {
            return 0.0;
        }
    }

    private static function evaluate(string $expression, float $base): float
    {
        $expression = preg_replace('/\s+/', '', $expression);
        preg_match_all('/(?:\d*\.)?\d+(?:pt|%)?|calc|min|max|clamp|[()+*,\/-]/', $expression, $matches);
        if (implode('', $matches[0]) !== $expression || count($matches[0]) > 128)
            throw new InvalidArgumentException('Invalid CSS length expression.');
        $parser = new self($matches[0], $base);
        $result = $parser->sum();
        if ($parser->index !== count($parser->tokens) || !is_finite($result))
            throw new InvalidArgumentException('Invalid CSS length expression.');
        return $result;
    }

    private function sum(): float
    {
        $value = $this->product();
        while (in_array($this->tokens[$this->index] ?? '', ['+', '-'], true)) {
            $operator = $this->tokens[$this->index++];
            $next = $this->product();
            $value += $operator === '+' ? $next : -$next;
        }
        return $value;
    }

    private function product(): float
    {
        $value = $this->value();
        while (in_array($this->tokens[$this->index] ?? '', ['*', '/'], true)) {
            $operator = $this->tokens[$this->index++];
            $next = $this->value();
            if ($operator === '/' && $next == 0.0)
                throw new InvalidArgumentException('CSS division by zero.');
            $value = $operator === '*' ? $value * $next : $value / $next;
        }
        return $value;
    }

    private function value(): float
    {
        $token = $this->tokens[$this->index++] ?? '';
        if ($token === '+' || $token === '-')
            return ($token === '-' ? -1 : 1) * $this->value();
        if (preg_match('/^(?:\d*\.)?\d+(?:pt|%)?$/', $token))
            return (float) $token * (str_ends_with($token, '%') ? $this->base / 100 : 1);
        if ($token === '(') {
            $result = $this->sum();
            if (($this->tokens[$this->index++] ?? '') !== ')')
                throw new InvalidArgumentException('Unclosed CSS expression.');
            return $result;
        }
        if (!in_array($token, ['calc', 'min', 'max', 'clamp'], true) || ($this->tokens[$this->index++] ?? '') !== '(')
            throw new InvalidArgumentException('Invalid CSS function.');
        $arguments = [$this->sum()];
        while (($this->tokens[$this->index] ?? '') === ',') {
            $this->index++;
            $arguments[] = $this->sum();
        }
        if (($this->tokens[$this->index++] ?? '') !== ')' || ($token === 'calc' && count($arguments) !== 1) || ($token === 'clamp' && count($arguments) !== 3))
            throw new InvalidArgumentException('Invalid CSS function arguments.');
        return match ($token) {
            'min' => min($arguments),
            'max' => max($arguments),
            'clamp' => max($arguments[0], min($arguments[1], $arguments[2])),
            default => $arguments[0],
        };
    }
}
