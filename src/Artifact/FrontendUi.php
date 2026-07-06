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
 * Frontend layout XML + list block + list template for the primary entity.
 *
 * Layout handles are declared in BOTH spellings: Maho builds the handle
 * from URL segments verbatim, so a hyphenated frontName produces a
 * hyphenated handle ({front-name}_index_index) while the router alias
 * produces the underscore form. Registering both is cheap insurance.
 */
final class FrontendUi implements ArtifactGenerator
{
    #[\Override]
    public function generate(Spec $spec, Strings $strings): array
    {
        $out = [];
        $codeDir = $spec->codeDir();
        $prefix = $spec->classPrefix();
        $alias = $spec->alias();
        $frontName = $spec->frontName();
        $entityName = $spec->admin['grid_entity'];
        $entityClass = $spec->entityClassSuffix($entityName);
        $entityLabelPlural = ucfirst(str_replace('_', ' ', $entityName)) . 's';
        $strings->add($entityLabelPlural, 'No entries yet.');

        // List block
        $out["$codeDir/Block/{$entityClass}List.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

class {{Prefix}}_Block_{{EntityClass}}List extends Mage_Core_Block_Template
{
    /** @return Mage_Core_Model_Resource_Db_Collection_Abstract */
    public function getCollection()
    {
        return Mage::getModel('{{Alias}}/{{Entity}}')->getCollection()
            ->setOrder('{{Pk}}', 'DESC')
            ->setPageSize(20);
    }
}

TPL, [
            'Prefix' => $prefix,
            'EntityClass' => $entityClass,
            'Alias' => $alias,
            'Entity' => $entityName,
            'Pk' => $spec->primaryKey($entityName),
        ]);

        // List template - first two non-pk string columns rendered escaped.
        $displayCols = [];
        foreach ($spec->entities[$entityName]['columns'] as $colName => $col) {
            if (!empty($col['primary']) || in_array($colName, ['created_at', 'updated_at'], true)) {
                continue;
            }
            $displayCols[] = $colName;
            if (count($displayCols) >= 2) {
                break;
            }
        }
        $primaryCol = $displayCols[0] ?? $spec->primaryKey($entityName);
        $secondaryCol = $displayCols[1] ?? null;
        $secondaryHtml = $secondaryCol === null ? '' : Tpl::render(<<<'TPL'
            <p><?= $this->escapeHtml((string) $item->getData('{{Col}}')) ?></p>

TPL, ['Col' => $secondaryCol]);

        $templateDir = strtolower($spec->module['vendor']) . '/' . $alias;
        $out["app/design/frontend/base/default/template/$templateDir/list.phtml"] = Tpl::render(<<<'TPL'
<?php
/**
 * @var {{Prefix}}_Block_{{EntityClass}}List $this
 */
$collection = $this->getCollection();
?>
<div class="{{FrontName}}-list">
    <div class="page-title">
        <h1><?= $this->escapeHtml($this->__('{{EntityLabelPlural}}')) ?></h1>
    </div>
    <?php if (!$collection->getSize()): ?>
        <p><?= $this->escapeHtml($this->__('No entries yet.')) ?></p>
    <?php else: ?>
        <?php foreach ($collection as $item): ?>
        <div class="{{FrontName}}-list__item">
            <h2><?= $this->escapeHtml((string) $item->getData('{{PrimaryCol}}')) ?></h2>
{{SecondaryHtml}}        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

TPL, [
            'Prefix' => $prefix,
            'EntityClass' => $entityClass,
            'FrontName' => $frontName,
            'EntityLabelPlural' => $entityLabelPlural,
            'PrimaryCol' => $primaryCol,
            'SecondaryHtml' => $secondaryHtml,
        ]);

        // Frontend layout - both handle spellings
        $layoutFile = strtolower($spec->module['vendor']) . '_' . $alias;
        $underscoreHandle = $alias . '_index_index';
        $hyphenHandle = $frontName . '_index_index';
        $handles = Tpl::render(<<<'TPL'
    <{{UnderscoreHandle}}>
        <update handle="{{HyphenHandle}}"/>
    </{{UnderscoreHandle}}>
    <{{HyphenHandle}}>
        <reference name="root">
            <action method="setTemplate"><template>page/1column.phtml</template></action>
        </reference>
        <reference name="head">
            <action method="setTitle"><title>{{EntityLabelPlural}}</title></action>
        </reference>
        <reference name="content">
            <block type="{{Alias}}/{{Entity}}List" name="{{Alias}}.list" template="{{TemplateDir}}/list.phtml"/>
        </reference>
    </{{HyphenHandle}}>
TPL, [
            'UnderscoreHandle' => $underscoreHandle,
            'HyphenHandle' => $hyphenHandle,
            'EntityLabelPlural' => $entityLabelPlural,
            'Alias' => $alias,
            'Entity' => Spec::camel($entityName),
            'TemplateDir' => $templateDir,
        ]);
        // Identical handles collapse when frontName has no hyphen.
        if ($underscoreHandle === $hyphenHandle) {
            $handles = preg_replace('/^    <' . preg_quote($underscoreHandle, '/') . ">\n        <update.*\n    <\/" . preg_quote($underscoreHandle, '/') . ">\n/m", '', $handles) ?? $handles;
        }

        $out["app/design/frontend/base/default/layout/$layoutFile.xml"] = Tpl::render(<<<'TPL'
<?xml version="1.0"?>
<layout version="0.1.0">
{{Handles}}
</layout>

TPL, ['Handles' => rtrim($handles)]);

        return $out;
    }
}
