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
 * Tiny placeholder renderer. Templates are nowdocs with {{Key}} markers -
 * no template engine, no escaping hell, and the generated-PHP-inside-PHP
 * problem disappears because the template body is never interpolated.
 */
final class Tpl
{
    /** @param array<string, string|int> $vars */
    public static function render(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{{' . $k . '}}'] = (string) $v;
        }
        $out = strtr($template, $map);
        if (preg_match('/\{\{[A-Za-z0-9_]+\}\}/', $out, $m)) {
            throw new SpecException("template placeholder left unrendered: {$m[0]}");
        }
        return $out;
    }

    /** Standard PHP file header for generated module code. */
    public static function phpHeader(Spec $spec, string $package): string
    {
        $year = (int) date('Y');
        return self::render(<<<'TPL'
<?php

/**
 * Maho
 *
 * @package    {{Package}}
 * @copyright  Copyright (c) {{Year}} {{Copyright}}
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

TPL, [
            'Package'   => $package,
            'Year'      => $year,
            'Copyright' => $spec->module['copyright'],
        ]);
    }
}
