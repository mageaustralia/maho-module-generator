<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator;

/**
 * Translation bag. Artifact generators register every translatable string
 * they emit; the locale-CSV artifact renders the collected set at the end,
 * sorted with strnatcasecmp (matching Maho core's locale-sort CI check).
 */
final class Strings
{
    /** @var array<string, true> */
    private array $strings = [];

    public function add(string ...$strings): void
    {
        foreach ($strings as $s) {
            if ($s !== '') {
                $this->strings[$s] = true;
            }
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        $keys = array_keys($this->strings);
        usort($keys, 'strnatcasecmp');
        return $keys;
    }
}
