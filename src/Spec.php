<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator;

use Symfony\Component\Yaml\Yaml;

/**
 * Parsed + validated + normalised module spec.
 *
 * Design rule: unknown keys are FATAL. A typo like `uniqe:` must fail at
 * parse time, not silently generate a module without the constraint.
 */
final class Spec
{
    /** @var array<string, mixed> */
    public array $module;
    /** @var array<string, array<string, mixed>> */
    public array $entities;
    /** @var array<string, mixed> */
    public array $frontend;
    /** @var array<string, mixed> */
    public array $admin;
    /** @var array<string, array<string, mixed>> */
    public array $emails;

    private const MODULE_KEYS   = ['vendor', 'name', 'version', 'license', 'copyright', 'depends', 'alias', 'front_name', 'code_pool'];
    private const ENTITY_KEYS   = ['table', 'columns', 'unique', 'indexes', 'foreign_keys', 'comment', 'timestamps', 'event_prefix'];
    private const COLUMN_KEYS   = ['type', 'length', 'precision', 'scale', 'unsigned', 'notnull', 'default', 'autoincrement', 'primary', 'comment'];
    private const FRONTEND_KEYS = ['controllers'];
    private const ADMIN_KEYS    = ['menu', 'acl_title', 'grid_entity'];
    private const EMAIL_KEYS    = ['subject', 'vars', 'label'];
    private const COLUMN_TYPES  = ['integer', 'smallint', 'bigint', 'decimal', 'string', 'text', 'datetime', 'date', 'boolean'];

    /** @param array<string, mixed> $raw */
    private function __construct(array $raw)
    {
        $this->assertKeys($raw, ['module', 'entities', 'frontend', 'admin', 'emails'], 'top level');

        $module = $raw['module'] ?? [];
        $this->assertKeys($module, self::MODULE_KEYS, 'module');
        foreach (['vendor', 'name'] as $required) {
            if (empty($module[$required]) || !is_string($module[$required])) {
                throw new SpecException("module.$required is required and must be a string");
            }
            if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $module[$required])) {
                throw new SpecException("module.$required must be PascalCase alphanumeric, got '{$module[$required]}'");
            }
        }
        $module['version']   ??= '0.1.0';
        $module['license']   ??= 'OSL-3.0';
        $module['copyright'] ??= $module['vendor'];
        $module['depends']   ??= [];
        $module['alias']     ??= strtolower($module['name']);
        $module['front_name'] ??= self::kebab($module['name']);
        $module['code_pool'] ??= 'community';
        if (!preg_match('/^[a-z][a-z0-9_]*$/', (string) $module['alias'])) {
            throw new SpecException("module.alias must be lowercase snake, got '{$module['alias']}'");
        }
        $this->module = $module;

        $this->entities = [];
        foreach ((array) ($raw['entities'] ?? []) as $entityName => $entity) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', (string) $entityName)) {
                throw new SpecException("entity key '$entityName' must be lowercase snake");
            }
            $this->assertKeys((array) $entity, self::ENTITY_KEYS, "entities.$entityName");
            $entity['table'] ??= strtolower($module['vendor']) . '_' . $entityName;
            $entity['timestamps'] ??= true;
            $entity['event_prefix'] ??= $module['alias'] . '_' . $entityName;
            $entity['unique'] ??= [];
            $entity['indexes'] ??= [];
            $entity['foreign_keys'] ??= [];
            $entity['comment'] ??= ucfirst(str_replace('_', ' ', $entityName));
            if (empty($entity['columns']) || !is_array($entity['columns'])) {
                throw new SpecException("entities.$entityName.columns is required");
            }
            $normalisedCols = [];
            $pkCount = 0;
            foreach ($entity['columns'] as $colName => $col) {
                if (!preg_match('/^[a-z][a-z0-9_]*$/', (string) $colName)) {
                    throw new SpecException("column '$colName' in $entityName must be lowercase snake");
                }
                $col = (array) $col;
                $this->assertKeys($col, self::COLUMN_KEYS, "entities.$entityName.columns.$colName");
                $col['type'] ??= 'string';
                if (!in_array($col['type'], self::COLUMN_TYPES, true)) {
                    throw new SpecException(
                        "column $entityName.$colName type '{$col['type']}' unknown; one of: " . implode(', ', self::COLUMN_TYPES),
                    );
                }
                if (!empty($col['primary'])) {
                    $pkCount++;
                    $col['unsigned'] ??= true;
                    $col['autoincrement'] ??= true;
                }
                if ($col['type'] === 'string') {
                    $col['length'] ??= 255;
                }
                if ($col['type'] === 'decimal') {
                    $col['precision'] ??= 12;
                    $col['scale'] ??= 4;
                }
                $normalisedCols[$colName] = $col;
            }
            if ($pkCount === 0) {
                // Auto-inject a conventional PK as the FIRST column.
                $pk = $entityName . '_id';
                $normalisedCols = [$pk => [
                    'type' => 'integer', 'unsigned' => true, 'autoincrement' => true, 'primary' => true,
                ]] + $normalisedCols;
            } elseif ($pkCount > 1) {
                throw new SpecException("entities.$entityName declares more than one primary column");
            }
            if ($entity['timestamps']) {
                $normalisedCols['created_at'] ??= ['type' => 'datetime', 'notnull' => true];
                $normalisedCols['updated_at'] ??= ['type' => 'datetime', 'notnull' => true];
            }
            $entity['columns'] = $normalisedCols;
            $this->entities[$entityName] = $entity;
        }
        if ($this->entities === []) {
            throw new SpecException('at least one entity is required');
        }

        $frontend = (array) ($raw['frontend'] ?? []);
        $this->assertKeys($frontend, self::FRONTEND_KEYS, 'frontend');
        $frontend['controllers'] ??= ['index' => ['actions' => ['index' => ['methods' => ['GET']]]]];
        foreach ($frontend['controllers'] as $ctrl => $def) {
            foreach ((array) ($def['actions'] ?? []) as $action => $a) {
                if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', (string) $action)) {
                    throw new SpecException("frontend action '$action' must be camelCase (URL segment == method name)");
                }
            }
        }
        $this->frontend = $frontend;

        $admin = (array) ($raw['admin'] ?? []);
        $this->assertKeys($admin, self::ADMIN_KEYS, 'admin');
        $admin['menu'] ??= ['parent' => 'catalog', 'title' => $module['name'], 'sort_order' => 100];
        $admin['acl_title'] ??= $module['name'];
        $admin['grid_entity'] ??= array_key_first($this->entities);
        if (!isset($this->entities[$admin['grid_entity']])) {
            throw new SpecException("admin.grid_entity '{$admin['grid_entity']}' is not a declared entity");
        }
        $this->admin = $admin;

        $this->emails = [];
        foreach ((array) ($raw['emails'] ?? []) as $code => $email) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', (string) $code)) {
                throw new SpecException("email code '$code' must be lowercase snake");
            }
            $email = (array) $email;
            $this->assertKeys($email, self::EMAIL_KEYS, "emails.$code");
            if (empty($email['subject'])) {
                throw new SpecException("emails.$code.subject is required");
            }
            $email['vars'] ??= [];
            $email['label'] ??= $module['name'] . ' - ' . str_replace('_', ' ', $code);
            $this->emails[$code] = $email;
        }
    }

    public static function fromYamlFile(string $path): self
    {
        if (!is_file($path)) {
            throw new SpecException("spec file not found: $path");
        }
        $raw = Yaml::parseFile($path);
        if (!is_array($raw)) {
            throw new SpecException('spec must be a YAML mapping');
        }
        return new self($raw);
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    // ── Derived names ────────────────────────────────────────────

    public function moduleName(): string
    {
        return $this->module['vendor'] . '_' . $this->module['name'];
    }

    public function classPrefix(): string
    {
        return $this->moduleName();
    }

    public function alias(): string
    {
        return $this->module['alias'];
    }

    public function frontName(): string
    {
        return $this->module['front_name'];
    }

    /** app/code/{pool}/{Vendor}/{Name} relative to module root */
    public function codeDir(): string
    {
        return 'app/code/' . $this->module['code_pool'] . '/' . $this->module['vendor'] . '/' . $this->module['name'];
    }

    public function entityClassSuffix(string $entityName): string
    {
        return implode('_', array_map('ucfirst', explode('_', $entityName)));
    }

    /** PK column name for an entity */
    public function primaryKey(string $entityName): string
    {
        foreach ($this->entities[$entityName]['columns'] as $name => $col) {
            if (!empty($col['primary'])) {
                return $name;
            }
        }
        throw new SpecException("entity $entityName has no primary column"); // unreachable post-normalise
    }

    public static function kebab(string $pascal): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $pascal));
    }

    public static function pascal(string $snake): string
    {
        return implode('', array_map('ucfirst', explode('_', $snake)));
    }

    public static function camel(string $snake): string
    {
        return lcfirst(self::pascal($snake));
    }

    /**
     * @param array<mixed> $arr
     * @param list<string> $allowed
     */
    private function assertKeys(array $arr, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($arr), $allowed);
        if ($unknown !== []) {
            throw new SpecException(
                "unknown key(s) in $context: " . implode(', ', array_map('strval', $unknown))
                . ' (allowed: ' . implode(', ', $allowed) . ')',
            );
        }
    }
}
