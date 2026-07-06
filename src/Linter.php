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
 * Pattern lint for existing Maho modules - generated OR hand-written.
 * Each rule corresponds to a bug class actually hit in production module
 * work; see DESIGN.md for the incident behind each one.
 */
final class Linter
{
    /**
     * @return list<array{rule: string, severity: string, file: string, line: int, message: string, fix: string}>
     */
    public function lint(string $moduleDir): array
    {
        if (!is_dir($moduleDir)) {
            throw new SpecException("not a directory: $moduleDir");
        }
        $findings = [];
        $sources = array_merge($this->filesByExt($moduleDir, 'php'), $this->filesByExt($moduleDir, 'phtml'));
        sort($sources, SORT_NATURAL);
        foreach ($sources as $file) {
            $rel = ltrim(substr($file, strlen($moduleDir)), '/');
            // Legacy sql/{name}_setup / data/{name}_setup scripts are frozen
            // BC artifacts - they intentionally keep the old APIs so old-core
            // installs keep working. Don't nag on every lint.
            if (preg_match('#/(sql|data)/[a-z0-9_]+_setup/#', "/$rel")) {
                continue;
            }
            $src = (string) file_get_contents($file);
            $lines = explode("\n", $src);
            $isPhtml = str_ends_with($rel, '.phtml');
            $isController = str_contains($rel, 'controllers/') && str_ends_with($rel, 'Controller.php');
            $isAdminController = $isController && str_contains($rel, 'Adminhtml');
            $isSchema = str_ends_with($rel, 'sql/schema.php');
            $isBlock = str_contains($rel, '/Block/');

            foreach ($lines as $i => $line) {
                $n = $i + 1;
                // Comment lines never carry executable findings.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                // Inline suppression: `lint:allow <rule-id>` in a trailing
                // comment skips that rule for this line. For deliberate
                // exceptions the rules cannot see - e.g. a hyphenated getUrl
                // segment that matches another module's explicit #[Route]
                // path. Always pair with a WHY in the same comment.
                $allow = [];
                if (preg_match_all('/lint:allow\s+([a-z-]+)/', $line, $am)) {
                    $allow = $am[1];
                }
                // zend-classes (allow the DBAL BC alias Zend_Db_Expr; ban the rest)
                if (preg_match('/\bnew\s+Zend_(?!Db_Expr\b)[A-Za-z_]+|\bZend_(?!Db_Expr\b)[A-Za-z_]+::/', $line)) {
                    $findings[] = $this->finding('zend-classes', 'critical', $rel, $n,
                        'Zend framework class used (removed from Maho)',
                        'use the Maho\\* / Symfony replacement (mail: Mage_Core_Model_Email_Template::send())');
                }
                // varien-classes (constructor/static use, not docblocks)
                if (preg_match('/\bnew\s+Varien_(?!Object\b)[A-Za-z_]+|\bVarien_(?!Object\b)[A-Za-z_]+::/', $line)) {
                    $findings[] = $this->finding('varien-classes', 'warning', $rel, $n,
                        'Varien_* class used where a Maho\\* replacement exists',
                        'Varien_X_Y -> Maho\\X\\Y (Varien_Object -> Maho\\DataObject is allowed as BC alias)');
                }
                // legacy-date
                if (preg_match('/Mage_Core_Model_Date\b|Mage_Core_Model_Locale::now\(\)|getModel\([\'"]core\/date[\'"]\)/', $line)) {
                    $findings[] = $this->finding('legacy-date', 'warning', $rel, $n,
                        'deprecated date API',
                        'use Mage_Core_Model_Locale::nowUtc() / formatDateForDb()');
                }
                // hyphen-action-url: getUrl('front/controller/act-ion') - hyphen or underscore in 3rd+ segment
                if (!in_array('hyphen-action-url', $allow, true)
                    && preg_match('/getUrl\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m)) {
                    $segments = explode('/', trim($m[1], '/'));
                    if (count($segments) >= 3) {
                        $action = $segments[2];
                        if ($action !== '*' && preg_match('/[-_]/', $action)) {
                            $findings[] = $this->finding('hyphen-action-url', 'critical', $rel, $n,
                                "getUrl action segment '$action' contains hyphen/underscore",
                                'Maho matches action segments as camelCase method names: add-to-cart -> addToCart');
                        }
                    }
                }
                // escape-htmlattr
                if (str_contains($line, 'escapeHtmlAttr(')) {
                    $findings[] = $this->finding('escape-htmlattr', 'critical', $rel, $n,
                        'escapeHtmlAttr() does not exist in Maho (silent output loss via __call)',
                        'use escapeHtml() for attributes');
                }
                // unique-constraint-ddl
                if ($isSchema && str_contains($line, 'addUniqueConstraint(')) {
                    $findings[] = $this->finding('unique-constraint-ddl', 'warning', $rel, $n,
                        'addUniqueConstraint drifts against legacy-created tables (differ drops the index; FKs then abort)',
                        'use addUniqueIndex()');
                }
                // raw-json (module code; not schema, and not tests - fixtures
                // gain nothing from the helper indirection)
                if (!$isSchema
                    && !str_starts_with($rel, 'tests/') && !str_contains($rel, '/tests/')
                    && preg_match('/\bjson_(en|de)code\s*\(/', $line)) {
                    $findings[] = $this->finding('raw-json', 'nit', $rel, $n,
                        'raw json_encode/json_decode',
                        "Mage::helper('core')->jsonEncode()/jsonDecode() respects module overrides + throws on bad input");
                }
            }

            // strict-types (file-level; templates don't declare it)
            if (!$isPhtml && !str_contains($src, 'declare(strict_types=1)')) {
                $findings[] = $this->finding('strict-types', 'warning', $rel, 1,
                    'missing declare(strict_types=1)',
                    'add after the file header');
            }

            // file-header: license + copyright docblock near the top.
            // Convention: OSL-3.0 for PHP source, AFL-3.0 acceptable for
            // templates (the Magento-1-era split Maho preserves).
            // Tests aren't shipped artifacts - header nagging there is noise.
            $isTest = str_starts_with($rel, 'tests/') || str_contains($rel, '/tests/');
            $head = substr($src, 0, 600);
            if (!$isTest && (!str_contains($head, '@license') || !str_contains($head, '@copyright'))) {
                $findings[] = $this->finding('file-header', $isPhtml ? 'nit' : 'warning', $rel, 1,
                    'missing @license/@copyright header docblock',
                    $isPhtml
                        ? 'add header (AFL-3.0 for templates, per the Magento-1 convention Maho preserves)'
                        : 'add header (OSL-3.0 for PHP source)');
            }

            // case-mismatch (file-level): every declared class tail must equal the basename
            if (!$isPhtml && preg_match_all('/^(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/m', $src, $mm)) {
                $base = basename($file, '.php');
                foreach ($mm[1] as $class) {
                    $tail = str_contains($class, '_') ? substr($class, strrpos($class, '_') + 1) : $class;
                    if ($tail !== $base && strcasecmp($tail, $base) === 0) {
                        $findings[] = $this->finding('case-mismatch', 'critical', $rel, 1,
                            "class tail '$tail' differs from file basename '$base' only by case",
                            'rename the file to match exactly - works on macOS, breaks on Linux');
                    }
                }
            }

            // block-helper-collision: Block subclass declaring helper() without params
            if ($isBlock && preg_match('/function\s+helper\s*\(\s*\)/', $src)) {
                $findings[] = $this->finding('block-helper-collision', 'critical', $rel, 1,
                    'helper() with no params collides with Mage_Core_Block_Abstract::helper($name) - class fatal-loads silently',
                    'rename the method (e.g. myHelper())');
            }

            // route-attributes: controllers must have #[Route] on actions.
            // Accept every spelling: imported `#[Route(`, fully-qualified
            // `#[\Maho\Config\Route(` / `#[Maho\Config\Route(` - matching on
            // the short form only produced false criticals on modules using
            // the FQ attribute syntax.
            // EXEMPT override controllers: a class extending another CONCRETE
            // controller (not the abstract Action bases) is a module-chain
            // override - requests reach it via the overridden controller's
            // routes + the dispatcher's resolveControllerClass() chain, and
            // declaring a duplicate #[Route] for the same path would conflict.
            $isOverrideController = preg_match(
                '/class\s+[A-Za-z0-9_]+\s+extends\s+(?!Mage_Core_Controller_Front_Action\b|Mage_Adminhtml_Controller_Action\b|Mage_Core_Controller_Varien_Action\b|Mage_Api_Controller_Action\b)[A-Za-z0-9_]*Controller\b/',
                $src,
            ) === 1;
            if ($isController && !$isOverrideController
                && preg_match('/function\s+[a-zA-Z0-9]+Action\s*\(/', $src)
                && !preg_match('/#\[\\\\?(Maho\\\\Config\\\\)?Route\(/', $src)) {
                $findings[] = $this->finding('route-attributes', 'critical', $rel, 1,
                    'controller has actions but no #[Maho\\Config\\Route] attributes (legacy XML routing NOTICE on every request)',
                    'add #[Route(...)] per action + composer dump-autoload');
            }

            // admin-resource + csrf-forced. Maho's base _isAllowed() checks
            // static::ADMIN_RESOURCE, so EITHER the const OR an override is
            // sufficient - only flag when both are absent.
            if ($isAdminController) {
                if (!str_contains($src, 'ADMIN_RESOURCE') && !str_contains($src, '_isAllowed')) {
                    $findings[] = $this->finding('admin-resource', 'critical', $rel, 1,
                        'admin controller has neither ADMIN_RESOURCE const nor _isAllowed() override (falls back to base resource)',
                        'add public const ADMIN_RESOURCE = \'<parent>/<resource>\';');
                }
                $hasStateActions = (bool) preg_match('/function\s+(save|delete|massDelete|accept|decline|approve|reject)Action/', $src);
                if ($hasStateActions && !str_contains($src, '_setForcedFormKeyActions')) {
                    $findings[] = $this->finding('csrf-forced', 'critical', $rel, 1,
                        'state-changing admin actions without _setForcedFormKeyActions()',
                        'call it in preDispatch() with the state-changing action list');
                }
            }
        }

        // acl-resource-mismatch: cross-file check. ADMIN_RESOURCE / isAllowed()
        // literals in admin controllers must correspond to ACL paths actually
        // declared in the module's etc/adminhtml.xml. Only NEAR-MISSES are
        // flagged (same path ignoring underscores/case but not literally equal,
        // e.g. notfound_log vs notfoundlog) - a resource with no similar
        // declared path may legitimately live in another module's ACL, so
        // exact-absence is not evidence of a bug. This bug class is nasty
        // because full admins (unrestricted) never notice: only role-limited
        // admins hit the nonexistent resource and can never be granted access.
        $findings = array_merge($findings, $this->lintAclResources($moduleDir));

        // email-foreach across .html templates
        foreach ($this->filesByExt($moduleDir, 'html') as $file) {
            $rel = ltrim(substr($file, strlen($moduleDir)), '/');
            if (!str_contains($rel, 'template/email')) {
                continue;
            }
            $src = (string) file_get_contents($file);
            if (str_contains($src, '{{foreach')) {
                $findings[] = $this->finding('email-foreach', 'warning', $rel, 1,
                    '{{foreach}} is not supported by Maho\'s email filter (renders literally)',
                    'pre-render list HTML in PHP and pass as a single *_html var');
            }
        }

        return $findings;
    }

    /**
     * Cross-file ACL consistency + menu-location checks.
     *
     * @return list<array{rule: string, severity: string, file: string, line: int, message: string, fix: string}>
     */
    private function lintAclResources(string $moduleDir): array
    {
        $findings = [];
        $xmlFiles = $this->filesByExt($moduleDir, 'xml');

        // menu-location: Maho's admin menu builder reads adminhtml.xml ONLY.
        // A <menu> (or <acl>) block inside config.xml <adminhtml> is silently
        // ignored - the menu simply never renders, with no error anywhere.
        foreach ($xmlFiles as $file) {
            if (basename($file) !== 'config.xml') {
                continue;
            }
            $src = (string) file_get_contents($file);
            $rel = ltrim(substr($file, strlen($moduleDir)), '/');
            if (preg_match('/<adminhtml>.*?<(menu|acl)>/s', $src, $m)) {
                $findings[] = $this->finding('menu-location', 'critical', $rel, 1,
                    "<{$m[1]}> declared inside config.xml <adminhtml> - Maho ignores it (menu never renders / ACL never registers)",
                    'move the <menu> and <acl> blocks into etc/adminhtml.xml');
            }
        }

        // Declared ACL paths from adminhtml.xml
        $declared = [];
        foreach ($xmlFiles as $file) {
            if (basename($file) !== 'adminhtml.xml') {
                continue;
            }
            $xml = @simplexml_load_file($file);
            if ($xml === false || !isset($xml->acl->resources->admin)) {
                continue;
            }
            $walk = function (\SimpleXMLElement $node, string $prefix) use (&$walk, &$declared): void {
                if (!isset($node->children)) {
                    return;
                }
                foreach ($node->children->children() as $name => $child) {
                    $path = ltrim("$prefix/$name", '/');
                    $declared[$path] = true;
                    $walk($child, $path);
                }
            };
            $walk($xml->acl->resources->admin, '');
        }
        if ($declared === []) {
            return $findings;
        }
        $normalise = static fn(string $p): string => strtolower(str_replace(['_', '-'], '', $p));
        $declaredNorm = [];
        foreach (array_keys($declared) as $p) {
            $declaredNorm[$normalise($p)] = $p;
        }

        // Referenced resources from admin controller PHP
        foreach ($this->filesByExt($moduleDir, 'php') as $file) {
            $rel = ltrim(substr($file, strlen($moduleDir)), '/');
            if (!str_contains($rel, 'controllers/') || !str_contains($rel, 'Adminhtml')) {
                continue;
            }
            $src = (string) file_get_contents($file);
            preg_match_all('/ADMIN_RESOURCE\s*=\s*[\'"]([^\'"]+)[\'"]|isAllowed\(\s*[\'"]([^\'"]+)[\'"]/', $src, $mm, PREG_SET_ORDER);
            foreach ($mm as $m) {
                $resource = ltrim(($m[1] !== '' ? $m[1] : ($m[2] ?? '')), '/');
                $resource = preg_replace('/^admin\//', '', $resource) ?? $resource;
                if ($resource === '' || isset($declared[$resource])) {
                    continue; // exact match - fine
                }
                // Near-miss: same path ignoring underscores/hyphens/case.
                // (A resource with NO similar declared path may live in
                // another module's ACL - not flaggable from here.)
                $near = $declaredNorm[$normalise($resource)] ?? null;
                if ($near !== null && $near !== $resource) {
                    $findings[] = $this->finding('acl-resource-mismatch', 'critical', $rel, 1,
                        "controller references ACL resource '$resource' but adminhtml.xml declares '$near' - role-restricted admins can never be granted access",
                        'align the ADMIN_RESOURCE / isAllowed() literal with the declared ACL node exactly');
                }
            }
        }
        return $findings;
    }

    /** @return array{rule: string, severity: string, file: string, line: int, message: string, fix: string} */
    private function finding(string $rule, string $severity, string $file, int $line, string $message, string $fix): array
    {
        return ['rule' => $rule, 'severity' => $severity, 'file' => $file, 'line' => $line, 'message' => $message, 'fix' => $fix];
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        return $this->filesByExt($dir, 'php');
    }

    /** @return list<string> */
    private function filesByExt(string $dir, string $ext): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $f): bool => $f->getFilename() !== 'vendor' && $f->getFilename() !== '.git',
            ),
        );
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === $ext) {
                $out[] = $file->getPathname();
            }
        }
        sort($out, SORT_NATURAL);
        return $out;
    }
}
