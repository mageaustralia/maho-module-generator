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
use MahoModuleGenerator\Tpl;

/**
 * Helper/Data.php + per-entity Model / Resource / Collection.
 */
final class ModelsPhp implements ArtifactGenerator
{
    #[\Override]
    public function generate(Spec $spec, Strings $strings): array
    {
        $out = [];
        $codeDir = $spec->codeDir();
        $prefix = $spec->classPrefix();

        $out["$codeDir/Helper/Data.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

class {{Prefix}}_Helper_Data extends Mage_Core_Helper_Abstract
{
}

TPL, ['Prefix' => $prefix]);

        foreach ($spec->entities as $entityName => $entity) {
            $classSuffix = $spec->entityClassSuffix($entityName);
            $classPath = str_replace('_', '/', $classSuffix);
            $alias = $spec->alias();

            // @method docblock lines from columns
            $methods = '';
            foreach ($entity['columns'] as $colName => $col) {
                $phpType = match ($col['type']) {
                    'integer', 'smallint', 'bigint', 'boolean' => 'int',
                    'decimal' => 'float',
                    default => 'string',
                };
                $getter = 'get' . Spec::pascal($colName);
                $setter = 'set' . Spec::pascal($colName);
                $nullable = empty($col['notnull']) && empty($col['primary']) ? '|null' : '';
                $methods .= " * @method {$phpType}{$nullable} {$getter}()\n";
                if (empty($col['primary']) && !in_array($colName, ['created_at', 'updated_at'], true)) {
                    $methods .= " * @method \$this {$setter}({$phpType} \$value)\n";
                }
            }

            $timestampHook = $entity['timestamps'] ? Tpl::render(<<<'TPL'

    #[\Override]
    protected function _beforeSave()
    {
        $now = Mage_Core_Model_Locale::nowUtc();
        if (!$this->getId()) {
            $this->setData('created_at', $now);
        }
        $this->setData('updated_at', $now);
        return parent::_beforeSave();
    }
TPL, []) : '';

            $out["$codeDir/Model/$classPath.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

/**
{{Methods}} */
class {{Prefix}}_Model_{{ClassSuffix}} extends Mage_Core_Model_Abstract
{
    protected $_eventPrefix = '{{EventPrefix}}';
    protected $_eventObject = 'object';

    #[\Override]
    protected function _construct(): void
    {
        $this->_init('{{Alias}}/{{Entity}}');
    }{{TimestampHook}}
}

TPL, [
                'Methods' => rtrim($methods),
                'Prefix' => $prefix,
                'ClassSuffix' => $classSuffix,
                'EventPrefix' => $entity['event_prefix'],
                'Alias' => $alias,
                'Entity' => $entityName,
                'TimestampHook' => $timestampHook,
            ]);

            $out["$codeDir/Model/Resource/$classPath.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

class {{Prefix}}_Model_Resource_{{ClassSuffix}} extends Mage_Core_Model_Resource_Db_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('{{Alias}}/{{Entity}}', '{{Pk}}');
    }
}

TPL, [
                'Prefix' => $prefix,
                'ClassSuffix' => $classSuffix,
                'Alias' => $alias,
                'Entity' => $entityName,
                'Pk' => $spec->primaryKey($entityName),
            ]);

            $out["$codeDir/Model/Resource/$classPath/Collection.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

class {{Prefix}}_Model_Resource_{{ClassSuffix}}_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('{{Alias}}/{{Entity}}');
    }
}

TPL, [
                'Prefix' => $prefix,
                'ClassSuffix' => $classSuffix,
                'Alias' => $alias,
                'Entity' => $entityName,
            ]);
        }

        return $out;
    }
}
