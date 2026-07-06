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

interface ArtifactGenerator
{
    /** @return array<string, string> relative path => file contents */
    public function generate(Spec $spec, Strings $strings): array;
}
