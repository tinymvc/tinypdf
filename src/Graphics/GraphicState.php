<?php

declare(strict_types=1);

namespace Spark\Pdf\Graphics;

use function sprintf;

/**
 * Manages PDF ExtGState resources for alpha transparency and graphics modes.
 */
final class GraphicState
{
    /** @var array<string, string> key (e.g. "ca_0.50_CA_0.50") => resource name (e.g. "GS1") */
    private array $states = [];

    /** @var array<string, array{ca: float, CA: float}> resource name => values */
    private array $definitions = [];

    /**
     * Gets or registers an ExtGState resource name for given fill/stroke alpha.
     */
    public function getAlphaState(float $fillAlpha, ?float $strokeAlpha = null): string
    {
        $strokeAlpha ??= $fillAlpha;
        $fa = max(0.0, min(1.0, round($fillAlpha, 3)));
        $sa = max(0.0, min(1.0, round($strokeAlpha, 3)));
        $key = sprintf('ca_%.3F_CA_%.3F', $fa, $sa);

        if (!isset($this->states[$key])) {
            $name = 'GS' . (count($this->states) + 1);
            $this->states[$key] = $name;
            $this->definitions[$name] = ['ca' => $fa, 'CA' => $sa];
        }

        return $this->states[$key];
    }

    /**
     * Returns the PDF resource dictionary string for /ExtGState.
     */
    public function getResourceDict(array &$objects, int &$nextId): string
    {
        if (empty($this->definitions)) {
            return '';
        }

        $items = '';
        foreach ($this->definitions as $name => $def) {
            $id = ++$nextId;
            $objects[$id] = sprintf('<< /Type /ExtGState /ca %.3F /CA %.3F >>', $def['ca'], $def['CA']);
            $items .= "/$name $id 0 R ";
        }

        return "/ExtGState << $items>> ";
    }
}
