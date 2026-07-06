<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator;

use MahoModuleGenerator\Artifact\AdminUi;
use MahoModuleGenerator\Artifact\ConfigXml;
use MahoModuleGenerator\Artifact\ControllersPhp;
use MahoModuleGenerator\Artifact\Emails;
use MahoModuleGenerator\Artifact\FrontendUi;
use MahoModuleGenerator\Artifact\LocaleCsv;
use MahoModuleGenerator\Artifact\MetaFiles;
use MahoModuleGenerator\Artifact\ModelsPhp;
use MahoModuleGenerator\Artifact\SchemaPhp;

/**
 * PURE: spec in, file map out. No filesystem writes, no framework boot.
 * The CLI, a web service, and an M1-converter are all wrappers over this.
 */
final class Generator
{
    /** @return array<string, string> relative path => contents */
    public function generate(Spec $spec): array
    {
        $strings = new Strings();
        $files = [];
        $artifacts = [
            new MetaFiles(),
            new ConfigXml(),
            new SchemaPhp(),
            new ModelsPhp(),
            new ControllersPhp(),
            new AdminUi(),
            new FrontendUi(),
            new Emails(),
            new LocaleCsv(), // MUST run last: consumes the collected strings
        ];
        foreach ($artifacts as $artifact) {
            foreach ($artifact->generate($spec, $strings) as $path => $contents) {
                if (isset($files[$path])) {
                    throw new SpecException("artifact collision: two generators produced '$path'");
                }
                $files[$path] = $contents;
            }
        }
        ksort($files, SORT_NATURAL);
        return $files;
    }
}
