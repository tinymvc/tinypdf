<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

/**
 * Common interface for PDF fonts (Standard Type 1 core fonts and embedded TrueType CID fonts).
 */
interface FontInterface
{
    /** Font family name or postscript name. */
    public function getName(): string;

    /** Ascender in points for given font size. */
    public function getAscender(float $size): float;

    /** Descender in points (usually negative) for given font size. */
    public function getDescender(float $size): float;

    /** Total line height (ascent - descent + gap) in points. */
    public function getLineHeight(float $size): float;

    /** Width of a string of text in points for given font size. */
    public function getTextWidth(string $text, float $size): float;

    /**
     * Encodes text for PDF content stream.
     * For Type 1: single-byte string.
     * For Type 0 / CIDFont: hex-encoded 2-byte glyph IDs enclosed in <...>.
     */
    public function encode(string $text): string;

    /**
     * Emits PDF font objects (Font, Descendant, Descriptor, ToUnicode, FontFile2).
     * @param int $nextId Pointer to the object ID sequence
     * @param string $resName Resource alias (e.g. "F1")
     * @return array<int, string> id => object body string
     */
    public function getPdfFontObjects(int &$nextId, string $resName): array;
}
