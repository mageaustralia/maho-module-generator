<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator\Extractor;

use MahoModuleGenerator\Spec;
use MahoModuleGenerator\SpecException;
use Symfony\Component\Yaml\Yaml;

/**
 * Clean-room spec extraction from a Magento 1 module directory.
 *
 * Derives STRUCTURE ONLY - names, tables, columns, routes, email template
 * registrations. No source expression from the M1 module ever enters the
 * emitted spec, which is what makes regeneration a clean-room
 * reimplementation rather than a port.
 *
 * The output is a STARTING POINT for human review, never a finished
 * migration: observers, cron jobs, templates and all business logic are
 * listed in a TODO header for manual attention.
 */
final class M1SpecExtractor
{
    /** Varien_Db_Ddl_Table::TYPE_* -> spec column type (size-sensitive cases handled in code) */
    private const DDL_TYPE_MAP = [
        'TYPE_INTEGER'   => 'integer',
        'TYPE_BIGINT'    => 'bigint',
        'TYPE_SMALLINT'  => 'smallint',
        'TYPE_TINYINT'   => 'smallint',
        'TYPE_BOOLEAN'   => 'boolean',
        'TYPE_DECIMAL'   => 'decimal',
        'TYPE_NUMERIC'   => 'decimal',
        'TYPE_FLOAT'     => 'decimal',
        'TYPE_TIMESTAMP' => 'datetime',
        'TYPE_DATETIME'  => 'datetime',
        'TYPE_DATE'      => 'date',
        'TYPE_VARCHAR'   => 'string',
        'TYPE_CHAR'      => 'string',
        // TYPE_TEXT is size-sensitive: <=255 -> string, else text
    ];

    /** @var list<string> */
    private array $todos = [];

    /** Extract and return the annotated YAML spec. */
    public function extractToYaml(string $m1Dir): string
    {
        $spec = $this->extract($m1Dir);
        // Validate before emitting - the promise is "parses and generates".
        Spec::fromArray($spec);

        $header = "# Clean-room spec extracted from a Magento 1 module by maho-module-generator.\n"
            . "# STRUCTURE ONLY was derived (names, tables, columns, routes, emails) -\n"
            . "# no code crossed the boundary. This is a STARTING POINT: review every\n"
            . "# line, then generate with 'maho-module-gen generate <this-file>'.\n";
        if ($this->todos !== []) {
            $header .= "#\n# TODO(extract): needs human attention, not extractable as structure:\n";
            foreach ($this->todos as $todo) {
                $header .= "#   - $todo\n";
            }
        }
        return $header . "\n" . Yaml::dump($spec, 6, 2);
    }

    /**
     * @return array<string, mixed> a Spec-compatible array
     */
    public function extract(string $m1Dir): array
    {
        $this->todos = [];
        $configPath = $this->findConfigXml($m1Dir);
        if ($configPath === null) {
            throw new SpecException('no etc/config.xml found under the given directory - is this a Magento 1 module?');
        }
        $xml = @simplexml_load_file($configPath);
        if ($xml === false) {
            throw new SpecException("etc/config.xml is not well-formed XML: $configPath");
        }
        $moduleRoot = dirname($configPath, 2); // .../Vendor/Name

        // ── module identity ──────────────────────────────────────────
        $moduleNode = $xml->modules?->children();
        $moduleName = $moduleNode ? (string) $moduleNode->getName() : basename(dirname($moduleRoot)) . '_' . basename($moduleRoot);
        [$vendor, $name] = array_pad(explode('_', $moduleName, 2), 2, 'Module');
        $version = (string) ($xml->modules->{$moduleName}->version ?? '0.1.0');

        $spec = [
            'module' => [
                'vendor' => $this->pascalise($vendor),
                'name' => $this->pascalise($name),
                'version' => $version ?: '0.1.0',
            ],
        ];

        // ── frontend router / frontName ──────────────────────────────
        $frontName = null;
        foreach ($xml->xpath('//frontend/routers/*/args/frontName') ?: [] as $node) {
            $frontName = (string) $node;
            break;
        }
        if ($frontName !== null && $frontName !== '') {
            $spec['module']['front_name'] = $frontName;
        }

        // ── model alias (helps table naming context) ─────────────────
        $entityTables = [];
        foreach ($xml->xpath('//global/models/*/entities/*') ?: [] as $entityNode) {
            $entityName = $entityNode->getName();
            $table = (string) ($entityNode->table ?? '');
            if ($table !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $entityName)) {
                $entityTables[$entityName] = $table;
            }
        }

        // ── tables + columns from setup scripts ──────────────────────
        $entities = $this->extractEntities($moduleRoot, $entityTables);
        if ($entities === []) {
            // Controller-only / observer-only modules still deserve a spec.
            $fallback = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $this->pascalise($name)));
            $entities[$fallback] = [
                'comment' => 'PLACEHOLDER - no tables could be extracted; replace or remove',
                'columns' => ['name' => ['type' => 'string', 'notnull' => true]],
            ];
            $this->todos[] = 'no CREATE TABLE / Ddl_Table definitions found - a placeholder entity was emitted; replace it with the real schema or delete it';
        }
        $spec['entities'] = $entities;

        // ── frontend controllers/actions ─────────────────────────────
        $controllers = $this->extractControllers($moduleRoot);
        if ($controllers !== []) {
            $spec['frontend'] = ['controllers' => $controllers];
        }

        // ── admin presence ───────────────────────────────────────────
        $hasAdminRouter = ($xml->xpath('//admin/routers') ?: []) !== [];
        $spec['admin'] = [
            'menu' => ['parent' => 'catalog', 'title' => $this->pascalise($name), 'sort_order' => 100],
        ];
        if ($hasAdminRouter) {
            $this->todos[] = 'admin router present in M1 config - the generated admin CRUD covers the primary entity only; port any additional admin controllers by hand';
        }

        // ── email templates ──────────────────────────────────────────
        $emails = [];
        foreach ($xml->xpath('//global/template/email/*') ?: [] as $emailNode) {
            $code = $emailNode->getName();
            // M1 codes are often alias_code; keep the tail as the spec code.
            $shortCode = (string) preg_replace('/^[a-z0-9]+_/', '', $code);
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $shortCode)) {
                continue;
            }
            $emails[$shortCode] = [
                'subject' => (string) ($emailNode->label ?? $shortCode),
                'vars' => [],
            ];
            $this->todos[] = "email '$code': subject + vars copied from label only - rewrite the subject and declare the vars actually used";
        }
        if ($emails !== []) {
            $spec['emails'] = $emails;
        }

        // ── un-extractables -> TODO list ─────────────────────────────
        if (($xml->xpath('//events') ?: []) !== []) {
            $this->todos[] = 'observers (<events>) present - port as #[Maho\\Config\\Observer] by hand';
        }
        if (($xml->xpath('//crontab') ?: []) !== []) {
            $this->todos[] = 'cron jobs (<crontab>) present - port as #[Maho\\Config\\CronJob] by hand';
        }
        $phtml = glob("$moduleRoot/../../../design/*/*/*/template/**/*.phtml") ?: [];
        $modelFiles = $this->countPhp("$moduleRoot/Model") + $this->countPhp("$moduleRoot/Helper");
        if ($modelFiles > 0) {
            $this->todos[] = "$modelFiles Model/Helper PHP file(s) contain business logic that is NOT extracted - reimplement against the generated skeleton";
        }
        if ($phtml !== []) {
            $this->todos[] = count($phtml) . ' template(s) found - redesign against the generated templates';
        }

        return $spec;
    }

    // ─────────────────────────────────────────────────────────────────

    private function findConfigXml(string $dir): ?string
    {
        // Accept either the module root itself or a zip-extracted tree that
        // nests it (app/code/<pool>/Vendor/Name/etc/config.xml).
        if (is_file("$dir/etc/config.xml")) {
            return "$dir/etc/config.xml";
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->getFilename() === 'config.xml'
                && basename($f->getPath()) === 'etc') {
                return $f->getPathname();
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $entityTables entity alias => table name (from config.xml)
     * @return array<string, array<string, mixed>>
     */
    private function extractEntities(string $moduleRoot, array $entityTables): array
    {
        $entities = [];
        $tableToEntity = array_flip($entityTables);
        foreach (glob("$moduleRoot/sql/*/*.php") ?: [] as $script) {
            $src = (string) file_get_contents($script);
            foreach ($this->parseDdlTables($src) + $this->parseRawSqlTables($src) as $table => $columns) {
                if ($columns === []) {
                    continue;
                }
                // A Ddl-style getTable('alias/entity') ref was flattened to
                // alias_entity - resolve to the REAL table via config.xml's
                // entities map when the tail matches a declared entity.
                if (!isset($tableToEntity[$table])) {
                    foreach ($entityTables as $ent => $realTable) {
                        if ($table === $ent || str_ends_with($table, '_' . $ent)) {
                            $table = $realTable;
                            break;
                        }
                    }
                }
                $entityName = $tableToEntity[$table]
                    ?? (string) preg_replace('/^[a-z0-9]+_/', '', $table); // strip vendor prefix
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $entityName)) {
                    continue;
                }
                // created_at/updated_at come back via timestamps:true - drop
                // them so the spec stays minimal.
                unset($columns['created_at'], $columns['updated_at']);
                $entities[$entityName] = [
                    'table' => $table,
                    'columns' => $columns,
                ];
            }
        }
        return $entities;
    }

    /**
     * Varien_Db_Ddl_Table fluent style:
     *   $installer->getConnection()->newTable($installer->getTable('alias/entity'))
     *       ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, [...])
     *
     * @return array<string, array<string, array<string, mixed>>> table => columns
     */
    private function parseDdlTables(string $src): array
    {
        $tables = [];
        // Split on newTable(...) boundaries; each chunk's addColumn calls belong to it.
        $chunks = preg_split('/->newTable\(/', $src) ?: [];
        for ($i = 1; $i < count($chunks); $i++) {
            $chunk = $chunks[$i];
            // Table name: getTable('alias/entity') or a literal string
            $table = null;
            if (preg_match('/getTable\(\s*[\'"]([^\'"]+)[\'"]/', $chunk, $m)) {
                $ref = $m[1];
                $table = str_contains($ref, '/') ? str_replace('/', '_', $ref) : $ref;
            } elseif (preg_match('/^\s*[\'"]([a-z0-9_]+)[\'"]/', $chunk, $m)) {
                $table = $m[1];
            }
            if ($table === null) {
                continue;
            }
            $columns = [];
            if (preg_match_all(
                '/->addColumn\(\s*[\'"](\w+)[\'"]\s*,\s*Varien_Db_Ddl_Table::(TYPE_\w+)\s*(?:,\s*([^,\)]+))?/',
                $chunk,
                $cols,
                PREG_SET_ORDER,
            )) {
                foreach ($cols as $c) {
                    $colName = $c[1];
                    $ddlType = $c[2];
                    $sizeRaw = trim($c[3] ?? '');
                    $col = $this->mapDdlColumn($ddlType, $sizeRaw);
                    // primary/identity heuristics from the options blob nearby
                    if (preg_match('/[\'"]' . preg_quote($colName, '/') . '[\'"].{0,400}?[\'"](?:identity|primary)[\'"]\s*=>\s*true/s', $chunk)) {
                        $col['primary'] = true;
                    }
                    $columns[$colName] = $col;
                }
            }
            $tables[$table] = $columns;
        }
        return $tables;
    }

    /**
     * Raw SQL style: $installer->run("CREATE TABLE ... ( ... )").
     *
     * @return array<string, array<string, array<string, mixed>>> table => columns
     */
    private function parseRawSqlTables(string $src): array
    {
        $tables = [];
        if (!preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+[`\'"{]*([a-zA-Z0-9_\/]+)[`\'"}]*\s*\((.*?)\)\s*(?:ENGINE|;)/is', $src, $mm, PREG_SET_ORDER)) {
            return $tables;
        }
        foreach ($mm as $m) {
            $table = str_replace('/', '_', trim($m[1], '`\'"{} '));
            // installer->getTable placeholders like {$this->getTable('x/y')} won't match; fine, best-effort.
            $body = $m[2];
            $columns = [];
            foreach (preg_split('/,\s*\n/', $body) ?: [] as $lineRaw) {
                $line = trim($lineRaw);
                if ($line === '' || preg_match('/^(PRIMARY|UNIQUE|KEY|CONSTRAINT|INDEX|FOREIGN)/i', $line)) {
                    continue;
                }
                if (!preg_match('/^[`\'"]?(\w+)[`\'"]?\s+(\w+)(?:\((\d+)(?:\s*,\s*(\d+))?\))?/', $line, $c)) {
                    continue;
                }
                [$_, $colName, $sqlType] = $c;
                $len = isset($c[3]) ? (int) $c[3] : null;
                $scale = isset($c[4]) ? (int) $c[4] : null;
                $col = match (strtolower($sqlType)) {
                    'int', 'integer', 'mediumint' => ['type' => 'integer'],
                    'bigint' => ['type' => 'bigint'],
                    'smallint', 'tinyint' => ['type' => 'smallint'],
                    'decimal', 'numeric', 'float', 'double' => ['type' => 'decimal'] + ($len ? ['precision' => $len, 'scale' => $scale ?? 0] : []),
                    'varchar', 'char' => ['type' => 'string'] + ($len ? ['length' => $len] : []),
                    'text', 'mediumtext', 'longtext', 'blob' => ['type' => 'text'],
                    'datetime', 'timestamp' => ['type' => 'datetime'],
                    'date' => ['type' => 'date'],
                    default => ['type' => 'string'],
                };
                if (stripos($line, 'auto_increment') !== false) {
                    $col['primary'] = true;
                }
                if (stripos($line, 'not null') !== false && empty($col['primary'])) {
                    $col['notnull'] = true;
                }
                $columns[$colName] = $col;
            }
            $tables[$table] = $columns;
        }
        return $tables;
    }

    /** @return array<string, mixed> */
    private function mapDdlColumn(string $ddlType, string $sizeRaw): array
    {
        if ($ddlType === 'TYPE_TEXT') {
            $size = (int) $sizeRaw;
            return ($size > 0 && $size <= 255)
                ? ['type' => 'string', 'length' => $size]
                : ['type' => 'text'];
        }
        $type = self::DDL_TYPE_MAP[$ddlType] ?? 'string';
        $col = ['type' => $type];
        if ($type === 'decimal' && preg_match('/(\d+)\s*,\s*(\d+)/', $sizeRaw, $m)) {
            $col['precision'] = (int) $m[1];
            $col['scale'] = (int) $m[2];
        }
        return $col;
    }

    /**
     * @return array<string, array{actions: array<string, array{methods: list<string>}>}>
     */
    private function extractControllers(string $moduleRoot): array
    {
        $controllers = [];
        foreach (glob("$moduleRoot/controllers/*Controller.php") ?: [] as $file) {
            $ctrl = strtolower((string) preg_replace('/Controller\.php$/', '', basename($file)));
            $src = (string) file_get_contents($file);
            $actions = [];
            if (preg_match_all('/function\s+(\w+)Action\s*\(/', $src, $mm)) {
                foreach ($mm[1] as $action) {
                    // POST-vs-GET is not statically knowable; default GET, POST
                    // only for conventionally state-changing names.
                    $isPost = (bool) preg_match('/^(save|delete|mass|post|submit|create|update)/i', $action);
                    $actions[$action] = ['methods' => [$isPost ? 'POST' : 'GET']];
                }
            }
            if ($actions !== []) {
                $controllers[$ctrl] = ['actions' => $actions];
            }
        }
        if (glob("$moduleRoot/controllers/Adminhtml/*Controller.php") ?: []) {
            $this->todos[] = 'Adminhtml controllers present - only the generated CRUD controller is emitted; port extra admin actions by hand';
        }
        return $controllers;
    }

    private function countPhp(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->getExtension() === 'php') {
                $n++;
            }
        }
        return $n;
    }

    private function pascalise(string $s): string
    {
        return implode('', array_map('ucfirst', preg_split('/[_\-\s]+/', $s) ?: []));
    }
}
