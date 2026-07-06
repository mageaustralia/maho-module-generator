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
 * etc/config.xml + etc/adminhtml.xml.
 */
final class ConfigXml implements ArtifactGenerator
{
    #[\Override]
    public function generate(Spec $spec, Strings $strings): array
    {
        $alias = $spec->alias();
        $module = $spec->moduleName();
        $codeDir = $spec->codeDir();

        // <entities> block
        $entitiesXml = '';
        foreach ($spec->entities as $entityName => $entity) {
            $entitiesXml .= Tpl::render(<<<'TPL'
                    <{{Entity}}>
                        <table>{{Table}}</table>
                    </{{Entity}}>

TPL, ['Entity' => $entityName, 'Table' => $entity['table']]);
        }

        // Email template registrations
        $emailsXml = '';
        foreach ($spec->emails as $code => $email) {
            $emailsXml .= Tpl::render(<<<'TPL'
                <{{Alias}}_{{Code}}>
                    <label>{{Label}}</label>
                    <file>{{Alias}}/{{Code}}.html</file>
                    <type>html</type>
                </{{Alias}}_{{Code}}>

TPL, ['Alias' => $alias, 'Code' => $code, 'Label' => $email['label']]);
        }
        $templateBlock = $emailsXml === '' ? '' : Tpl::render(<<<'TPL'
        <template>
            <email>
{{Emails}}            </email>
        </template>

TPL, ['Emails' => $emailsXml]);

        $adminRouteName = strtolower($spec->module['vendor']) . '_' . $alias;

        $configXml = Tpl::render(<<<'TPL'
<?xml version="1.0"?>
<!--
/**
 * Maho
 * @package    {{Module}}
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
-->
<config>
    <modules>
        <{{Module}}>
            <version>{{Version}}</version>
        </{{Module}}>
    </modules>

    <global>
        <helpers>
            <{{Alias}}>
                <class>{{Module}}_Helper</class>
            </{{Alias}}>
        </helpers>
        <models>
            <{{Alias}}>
                <class>{{Module}}_Model</class>
                <resourceModel>{{Alias}}_resource</resourceModel>
            </{{Alias}}>
            <{{Alias}}_resource>
                <class>{{Module}}_Model_Resource</class>
                <entities>
{{Entities}}                </entities>
            </{{Alias}}_resource>
        </models>
        <blocks>
            <{{Alias}}>
                <class>{{Module}}_Block</class>
            </{{Alias}}>
        </blocks>
{{TemplateBlock}}    </global>

    <frontend>
        <routers>
            <{{Alias}}>
                <use>standard</use>
                <args>
                    <module>{{Module}}</module>
                    <frontName>{{FrontName}}</frontName>
                </args>
            </{{Alias}}>
        </routers>
        <layout>
            <updates>
                <{{Alias}}>
                    <file>{{LayoutFile}}.xml</file>
                </{{Alias}}>
            </updates>
        </layout>
        <translate>
            <modules>
                <{{Module}}>
                    <files>
                        <default>{{Module}}.csv</default>
                    </files>
                </{{Module}}>
            </modules>
        </translate>
    </frontend>

    <admin>
        <routers>
            <adminhtml>
                <args>
                    <modules>
                        <{{AdminRouteName}} before="Mage_Adminhtml">{{Module}}_Adminhtml</{{AdminRouteName}}>
                    </modules>
                </args>
            </adminhtml>
        </routers>
    </admin>
    <adminhtml>
        <layout>
            <updates>
                <{{Alias}}>
                    <file>{{LayoutFile}}.xml</file>
                </{{Alias}}>
            </updates>
        </layout>
        <translate>
            <modules>
                <{{Module}}>
                    <files>
                        <default>{{Module}}.csv</default>
                    </files>
                </{{Module}}>
            </modules>
        </translate>
    </adminhtml>
</config>

TPL, [
            'Module' => $module,
            'Version' => $spec->module['version'],
            'Alias' => $alias,
            'Entities' => $entitiesXml,
            'TemplateBlock' => $templateBlock,
            'FrontName' => $spec->frontName(),
            'LayoutFile' => strtolower($spec->module['vendor']) . '_' . $alias,
            'AdminRouteName' => $adminRouteName,
        ]);

        $menu = $spec->admin['menu'];
        $gridEntity = $spec->admin['grid_entity'];
        $strings->add((string) $menu['title'], (string) $spec->admin['acl_title']);

        $adminhtmlXml = Tpl::render(<<<'TPL'
<?xml version="1.0"?>
<config>
    <menu>
        <{{Parent}}>
            <children>
                <{{AdminRouteName}} translate="title" module="{{Alias}}">
                    <title>{{MenuTitle}}</title>
                    <sort_order>{{SortOrder}}</sort_order>
                    <action>adminhtml/{{AdminRouteName}}/{{Entity}}/index</action>
                </{{AdminRouteName}}>
            </children>
        </{{Parent}}>
    </menu>
    <acl>
        <resources>
            <admin>
                <children>
                    <{{Parent}}>
                        <children>
                            <{{AdminRouteName}} translate="title" module="{{Alias}}">
                                <title>{{AclTitle}}</title>
                                <sort_order>{{SortOrder}}</sort_order>
                            </{{AdminRouteName}}>
                        </children>
                    </{{Parent}}>
                </children>
            </admin>
        </resources>
    </acl>
</config>

TPL, [
            'Parent' => $menu['parent'],
            'AdminRouteName' => $adminRouteName,
            'Alias' => $alias,
            'MenuTitle' => $menu['title'],
            'SortOrder' => $menu['sort_order'] ?? 100,
            'Entity' => $gridEntity,
            'AclTitle' => $spec->admin['acl_title'],
        ]);

        return [
            "$codeDir/etc/config.xml" => $configXml,
            "$codeDir/etc/adminhtml.xml" => $adminhtmlXml,
        ];
    }
}
