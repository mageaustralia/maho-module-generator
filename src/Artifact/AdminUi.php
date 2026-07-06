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
 * Admin grid container + grid + edit form container + form, and the
 * adminhtml layout XML. Grid columns and form fields derive from the
 * entity's schema columns.
 */
final class AdminUi implements ArtifactGenerator
{
    #[\Override]
    public function generate(Spec $spec, Strings $strings): array
    {
        $out = [];
        $codeDir = $spec->codeDir();
        $prefix = $spec->classPrefix();
        $alias = $spec->alias();
        $entityName = $spec->admin['grid_entity'];
        $entity = $spec->entities[$entityName];
        $entityClass = $spec->entityClassSuffix($entityName);
        $pk = $spec->primaryKey($entityName);
        $entityLabelPlural = ucfirst(str_replace('_', ' ', $entityName)) . 's';
        $adminRouteName = strtolower($spec->module['vendor']) . '_' . $alias;
        $strings->add($entityLabelPlural, 'Edit', 'Save', 'Delete', 'Back', 'ID', 'Are you sure?');

        // Grid container
        $out["$codeDir/Block/Adminhtml/$entityClass.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

class {{Prefix}}_Block_Adminhtml_{{EntityClass}} extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_controller = 'adminhtml_{{Entity}}';
        $this->_blockGroup = '{{Alias}}';
        $this->_headerText = Mage::helper('{{Alias}}')->__('{{EntityLabelPlural}}');
        parent::__construct();
    }
}

TPL, [
            'Prefix' => $prefix,
            'EntityClass' => $entityClass,
            'Entity' => $entityName,
            'Alias' => $alias,
            'EntityLabelPlural' => $entityLabelPlural,
        ]);

        // Grid columns: pk + up to 6 non-text columns, datetime typed.
        $columns = '';
        $count = 0;
        foreach ($entity['columns'] as $colName => $col) {
            if ($col['type'] === 'text' && empty($col['primary'])) {
                continue;
            }
            if ($count >= 7) {
                break;
            }
            $header = ucwords(str_replace('_', ' ', $colName));
            $strings->add($header);
            $type = match ($col['type']) {
                'datetime', 'date' => "\n            'type' => 'datetime',",
                'integer', 'smallint', 'bigint' => !empty($col['primary']) ? "\n            'type' => 'number',\n            'width' => 60," : '',
                'decimal' => "\n            'type' => 'number',",
                default => '',
            };
            $columns .= Tpl::render(<<<'TPL'
        $this->addColumn('{{Col}}', [
            'header' => Mage::helper('{{Alias}}')->__('{{Header}}'),
            'index'  => '{{Col}}',{{Type}}
        ]);

TPL, ['Col' => $colName, 'Alias' => $alias, 'Header' => $header, 'Type' => $type]);
            $count++;
        }

        $out["$codeDir/Block/Adminhtml/$entityClass/Grid.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

class {{Prefix}}_Block_Adminhtml_{{EntityClass}}_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('{{Alias}}{{EntityClass}}Grid');
        $this->setDefaultSort('{{Pk}}');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $collection = Mage::getModel('{{Alias}}/{{Entity}}')->getCollection();
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
{{Columns}}        return parent::_prepareColumns();
    }

    #[\Override]
    public function getRowUrl($row): string
    {
        return $this->getUrl('*/*/edit', ['id' => $row->getId()]);
    }

    #[\Override]
    public function getGridUrl(): string
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }
}

TPL, [
            'Prefix' => $prefix,
            'EntityClass' => $entityClass,
            'Alias' => $alias,
            'Entity' => $entityName,
            'Pk' => $pk,
            'Columns' => $columns,
        ]);

        // Edit form container
        $out["$codeDir/Block/Adminhtml/$entityClass/Edit.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

class {{Prefix}}_Block_Adminhtml_{{EntityClass}}_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        $this->_objectId = 'id';
        $this->_controller = 'adminhtml_{{Entity}}';
        $this->_blockGroup = '{{Alias}}';
        parent::__construct();
    }

    #[\Override]
    public function getHeaderText()
    {
        $model = Mage::registry('current_{{Entity}}');
        if ($model && $model->getId()) {
            return Mage::helper('{{Alias}}')->__('Edit {{EntityLabel}} #%d', (int) $model->getId());
        }
        return Mage::helper('{{Alias}}')->__('New {{EntityLabel}}');
    }
}

TPL, [
            'Prefix' => $prefix,
            'EntityClass' => $entityClass,
            'Entity' => $entityName,
            'Alias' => $alias,
            'EntityLabel' => ucfirst(str_replace('_', ' ', $entityName)),
        ]);
        $strings->add('Edit ' . ucfirst(str_replace('_', ' ', $entityName)) . ' #%d', 'New ' . ucfirst(str_replace('_', ' ', $entityName)), 'General');

        // Edit form: field per non-pk, non-timestamp column
        $fields = '';
        foreach ($entity['columns'] as $colName => $col) {
            if (!empty($col['primary']) || in_array($colName, ['created_at', 'updated_at'], true)) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', $colName));
            $strings->add($label);
            $input = match ($col['type']) {
                'text' => 'textarea',
                'boolean' => 'select',
                default => 'text',
            };
            $required = !empty($col['notnull']) && !array_key_exists('default', $col) ? 'true' : 'false';
            $extra = $input === 'select'
                ? "\n            'values' => Mage::getModel('adminhtml/system_config_source_yesno')->toOptionArray(),"
                : '';
            $fields .= Tpl::render(<<<'TPL'
        $fieldset->addField('{{Col}}', '{{Input}}', [
            'name'     => '{{Entity}}[{{Col}}]',
            'label'    => Mage::helper('{{Alias}}')->__('{{Label}}'),
            'required' => {{Required}},{{Extra}}
        ]);

TPL, [
                'Col' => $colName, 'Input' => $input, 'Entity' => $entityName,
                'Alias' => $alias, 'Label' => $label, 'Required' => $required, 'Extra' => $extra,
            ]);
        }

        $out["$codeDir/Block/Adminhtml/$entityClass/Edit/Form.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

class {{Prefix}}_Block_Adminhtml_{{EntityClass}}_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    #[\Override]
    protected function _prepareForm()
    {
        $model = Mage::registry('current_{{Entity}}');
        $form = new Maho\Data\Form([
            'id'     => 'edit_form',
            'action' => $this->getUrl('*/*/save', ['id' => $model ? (int) $model->getId() : null]),
            'method' => 'post',
        ]);
        $form->setUseContainer(true);
        $fieldset = $form->addFieldset('base_fieldset', [
            'legend' => Mage::helper('{{Alias}}')->__('General'),
        ]);
{{Fields}}        if ($model) {
            $form->setValues($model->getData());
        }
        $this->setForm($form);
        return parent::_prepareForm();
    }
}

TPL, [
            'Prefix' => $prefix,
            'EntityClass' => $entityClass,
            'Entity' => $entityName,
            'Alias' => $alias,
            'Fields' => $fields,
        ]);

        // Admin layout handles
        $layoutFile = strtolower($spec->module['vendor']) . '_' . $alias;
        $out["app/design/adminhtml/default/default/layout/$layoutFile.xml"] = Tpl::render(<<<'TPL'
<?xml version="1.0"?>
<layout version="0.1.0">
    <adminhtml_{{AdminRouteName}}_{{Entity}}_index>
        <reference name="content">
            <block type="{{Alias}}/adminhtml_{{Entity}}" name="{{Alias}}.{{Entity}}.grid.container"/>
        </reference>
    </adminhtml_{{AdminRouteName}}_{{Entity}}_index>
    <adminhtml_{{AdminRouteName}}_{{Entity}}_edit>
        <reference name="content">
            <block type="{{Alias}}/adminhtml_{{Entity}}_edit" name="{{Alias}}.{{Entity}}.edit"/>
        </reference>
    </adminhtml_{{AdminRouteName}}_{{Entity}}_edit>
</layout>

TPL, [
            'AdminRouteName' => $adminRouteName,
            'Entity' => $entityName,
            'Alias' => $alias,
        ]);

        return $out;
    }
}
