<?php

declare(strict_types=1);

namespace Spark\Pdf\Text;

/** Immutable positioned glyphs; layout and PDF output share this exact result. */
final readonly class ShapedRun
{
    public function __construct(public array $data)
    {
    }

    public function width(): float
    {
        return (float) $this->data['width'];
    }
}
