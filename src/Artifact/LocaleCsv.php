<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator\Artifact;

use MahoModuleGenerator\Spec;
use MahoModuleGenerator\Strings;

/**
 * Locale CSV from the collected translation bag - must run LAST so every
 * other artifact has registered its strings. Sorted strnatcasecmp to match
 * Maho core's locale-sort convention.
 */
final class LocaleCsv implements ArtifactGenerator
{
    #[\Override]
    public function generate(Spec $spec, Strings $strings): array
    {
        $lines = '';
        foreach ($strings->all() as $s) {
            $escaped = str_replace('"', '""', $s);
            $lines .= "\"$escaped\",\"$escaped\"\n";
        }
        return [
            'app/locale/en_US/' . $spec->moduleName() . '.csv' => $lines,
        ];
    }
}
