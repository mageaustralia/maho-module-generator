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
use MahoModuleGenerator\SpecException;
use MahoModuleGenerator\Strings;
use MahoModuleGenerator\Tpl;

/**
 * Declarative sql/schema.php.
 *
 * Emits addUniqueIndex (never addUniqueConstraint) - DBAL's diff engine
 * treats UniqueConstraint objects and unique indexes as distinct metadata;
 * MySQL stores legacy UNIQUE KEYs as unique indexes, so only addUniqueIndex
 * round-trips cleanly against pre-existing tables.
 */
final class SchemaPhp implements ArtifactGenerator
{
    private const TYPE_MAP = [
        'integer'  => 'Types::INTEGER',
        'smallint' => 'Types::SMALLINT',
        'bigint'   => 'Types::BIGINT',
        'decimal'  => 'Types::DECIMAL',
        'string'   => 'Types::STRING',
        'text'     => 'Types::TEXT',
        'datetime' => 'Types::DATETIME_MUTABLE',
        'date'     => 'Types::DATE_MUTABLE',
        'boolean'  => 'Types::SMALLINT', // Maho convention: smallint 0/1, portable across MySQL/PG/SQLite
    ];

    #[\Override]
    public function generate(Spec $spec, Strings $strings): array
    {
        $body = '';
        foreach ($spec->entities as $entityName => $entity) {
            $var = '$' . Spec::camel($entityName);
            $body .= "    $var = \$schema->createTable('{$entity['table']}');\n";
            $pk = null;
            foreach ($entity['columns'] as $colName => $col) {
                $opts = $this->columnOptions($col);
                $type = self::TYPE_MAP[$col['type']];
                $body .= "    {$var}->addColumn('$colName', $type, [$opts]);\n";
                if (!empty($col['primary'])) {
                    $pk = $colName;
                }
            }
            if ($pk === null) {
                throw new SpecException("entity $entityName lost its primary key during generation");
            }
            $body .= "    {$var}->addPrimaryKeyConstraint(\n";
            $body .= "        PrimaryKeyConstraint::editor()->setUnquotedColumnNames('$pk')->create(),\n";
            $body .= "    );\n";
            foreach ((array) $entity['unique'] as $unique) {
                $cols = is_array($unique) ? $unique : [$unique];
                $idxName = 'UNQ_' . strtoupper($entity['table']) . '_' . strtoupper(implode('_', $cols));
                $colList = "'" . implode("', '", $cols) . "'";
                $body .= "    {$var}->addUniqueIndex([$colList], '$idxName');\n";
            }
            foreach ((array) $entity['indexes'] as $index) {
                $cols = is_array($index) ? $index : [$index];
                $idxName = 'IDX_' . strtoupper($entity['table']) . '_' . strtoupper(implode('_', $cols));
                $colList = "'" . implode("', '", $cols) . "'";
                $body .= "    {$var}->addIndex([$colList], '$idxName');\n";
            }
            foreach ((array) $entity['foreign_keys'] as $fk) {
                $fk = (array) $fk;
                $onDelete = $fk['on_delete'] ?? 'CASCADE';
                $fkName = 'FK_' . strtoupper($entity['table']) . '_' . strtoupper((string) $fk['column']);
                $body .= "    {$var}->addForeignKeyConstraint(\n";
                $body .= "        '{$fk['references']}',\n";
                $body .= "        ['{$fk['column']}'],\n";
                $body .= "        ['{$fk['referenced_column']}'],\n";
                $body .= "        ['onDelete' => '$onDelete'],\n";
                $body .= "        '$fkName',\n";
                $body .= "    );\n";
            }
            $body .= "    {$var}->setComment('" . addslashes((string) $entity['comment']) . "');\n\n";
        }
        $body = rtrim($body) . "\n";

        $header = Tpl::phpHeader($spec, $spec->moduleName());
        $file = $header . Tpl::render(<<<'TPL'

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return function (Schema $schema): void {
{{Body}}};

TPL, ['Body' => $body]);

        return [$spec->codeDir() . '/sql/schema.php' => $file];
    }

    /** @param array<string, mixed> $col */
    private function columnOptions(array $col): string
    {
        $parts = [];
        if (!empty($col['unsigned'])) {
            $parts[] = "'unsigned' => true";
        }
        if (!empty($col['autoincrement'])) {
            $parts[] = "'autoincrement' => true";
        }
        if ($col['type'] === 'string' && isset($col['length'])) {
            $parts[] = "'length' => " . (int) $col['length'];
        }
        if ($col['type'] === 'decimal') {
            $parts[] = "'precision' => " . (int) $col['precision'];
            $parts[] = "'scale' => " . (int) $col['scale'];
        }
        if (array_key_exists('notnull', $col)) {
            $parts[] = "'notnull' => " . ($col['notnull'] ? 'true' : 'false');
        } elseif (empty($col['primary'])) {
            $parts[] = "'notnull' => false";
        }
        if (array_key_exists('default', $col) && $col['default'] !== null) {
            $default = is_numeric($col['default']) && !is_string($col['default'])
                ? (string) $col['default']
                : "'" . addslashes((string) $col['default']) . "'";
            $parts[] = "'default' => $default";
        }
        if (!empty($col['comment'])) {
            $parts[] = "'comment' => '" . addslashes((string) $col['comment']) . "'";
        }
        return implode(', ', $parts);
    }
}
