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
 * Frontend controllers (spec-driven actions, #[Route] on everything,
 * _validateFormKey on POST) + one admin CRUD controller for the grid entity
 * (index/grid/edit/save/delete, ADMIN_RESOURCE, _isAllowed,
 * _setForcedFormKeyActions, #[Route] on everything).
 */
final class ControllersPhp implements ArtifactGenerator
{
    #[\Override]
    public function generate(Spec $spec, Strings $strings): array
    {
        $out = [];
        $codeDir = $spec->codeDir();
        $prefix = $spec->classPrefix();
        $frontName = $spec->frontName();

        foreach ($spec->frontend['controllers'] as $ctrlName => $def) {
            $ctrlClass = Spec::pascal((string) $ctrlName);
            $actions = '';
            foreach ((array) ($def['actions'] ?? []) as $actionName => $a) {
                $a = (array) $a;
                $methods = array_map('strtoupper', (array) ($a['methods'] ?? ['GET']));
                $isPost = in_array('POST', $methods, true);
                $routes = '';
                $defaultRoute = $a['route'] ?? null;
                if ($defaultRoute === null) {
                    if ($ctrlName === 'index' && $actionName === 'index') {
                        $routes .= "    #[Route('/$frontName', methods: ['GET'])]\n";
                        $routes .= "    #[Route('/$frontName/index/index', methods: ['GET'])]\n";
                    } else {
                        $methodList = "'" . implode("', '", $methods) . "'";
                        $routes .= "    #[Route('/$frontName/$ctrlName/$actionName', methods: [$methodList])]\n";
                    }
                } else {
                    $methodList = "'" . implode("', '", $methods) . "'";
                    $routes .= "    #[Route('$defaultRoute', methods: [$methodList])]\n";
                }
                $bodyLines = $isPost
                    ? "        \$this->_validateFormKey();\n        // TODO: implement\n        \$this->_redirect('*/*/');"
                    : "        \$this->loadLayout();\n        \$this->renderLayout();";
                $actions .= "\n$routes    public function {$actionName}Action(): void\n    {\n$bodyLines\n    }\n";
            }

            $out["$codeDir/controllers/{$ctrlClass}Controller.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

use Maho\Config\Route;

class {{Prefix}}_{{Ctrl}}Controller extends Mage_Core_Controller_Front_Action
{{{Actions}}}

TPL, [
                'Prefix' => $prefix,
                'Ctrl' => $ctrlClass,
                'Actions' => $actions,
            ]);
        }

        // Admin CRUD controller for the grid entity
        $entityName = $spec->admin['grid_entity'];
        $entityClass = $spec->entityClassSuffix($entityName);
        $adminRouteName = strtolower($spec->module['vendor']) . '_' . $spec->alias();
        $aclResource = $spec->admin['menu']['parent'] . '/' . $adminRouteName;
        $alias = $spec->alias();
        $pk = $spec->primaryKey($entityName);
        $entityLabel = ucfirst(str_replace('_', ' ', $entityName));
        $strings->add(
            "$entityLabel saved.",
            "$entityLabel deleted.",
            "That $entityLabel no longer exists.",
        );

        $out["$codeDir/controllers/Adminhtml/{$entityClass}Controller.php"] = Tpl::phpHeader($spec, $prefix) . Tpl::render(<<<'TPL'

use Maho\Config\Route;

class {{Prefix}}_Adminhtml_{{EntityClass}}Controller extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = '{{AclResource}}';

    #[\Override]
    public function preDispatch()
    {
        $this->_setForcedFormKeyActions(['save', 'delete']);
        return parent::preDispatch();
    }

    #[\Override]
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('admin/{{AclResource}}');
    }

    #[Route('/admin/{{AdminRouteName}}/{{Entity}}', methods: ['GET'])]
    #[Route('/admin/{{AdminRouteName}}/{{Entity}}/index', methods: ['GET'])]
    public function indexAction(): void
    {
        $this->loadLayout()
            ->_setActiveMenu('{{AclResource}}')
            ->renderLayout();
    }

    #[Route('/admin/{{AdminRouteName}}/{{Entity}}/grid', methods: ['GET'])]
    public function gridAction(): void
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('{{Alias}}/adminhtml_{{Entity}}_grid')->toHtml(),
        );
    }

    #[Route('/admin/{{AdminRouteName}}/{{Entity}}/new', methods: ['GET'])]
    public function newAction(): void
    {
        $this->_forward('edit');
    }

    #[Route('/admin/{{AdminRouteName}}/{{Entity}}/edit', methods: ['GET'])]
    public function editAction(): void
    {
        $id = (int) $this->getRequest()->getParam('id');
        $model = Mage::getModel('{{Alias}}/{{Entity}}');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                Mage::getSingleton('adminhtml/session')->addError(
                    Mage::helper('{{Alias}}')->__('That {{EntityLabel}} no longer exists.'),
                );
                $this->_redirect('*/*/');
                return;
            }
        }
        Mage::register('current_{{Entity}}', $model);
        $this->loadLayout()
            ->_setActiveMenu('{{AclResource}}')
            ->renderLayout();
    }

    #[Route('/admin/{{AdminRouteName}}/{{Entity}}/save', methods: ['POST'])]
    public function saveAction(): void
    {
        $id = (int) $this->getRequest()->getParam('id');
        $data = (array) $this->getRequest()->getPost('{{Entity}}', []);
        $model = Mage::getModel('{{Alias}}/{{Entity}}');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                $this->_redirect('*/*/');
                return;
            }
        }
        try {
            $model->addData($data)->save();
            Mage::getSingleton('adminhtml/session')->addSuccess(
                Mage::helper('{{Alias}}')->__('{{EntityLabel}} saved.'),
            );
            $this->_redirect('*/*/');
        } catch (Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            $this->_redirect('*/*/edit', ['id' => $id ?: null]);
        }
    }

    #[Route('/admin/{{AdminRouteName}}/{{Entity}}/delete', methods: ['POST'])]
    public function deleteAction(): void
    {
        $id = (int) $this->getRequest()->getParam('id');
        $model = Mage::getModel('{{Alias}}/{{Entity}}')->load($id);
        if ($model->getId()) {
            try {
                $model->delete();
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    Mage::helper('{{Alias}}')->__('{{EntityLabel}} deleted.'),
                );
            } catch (Throwable $e) {
                Mage::logException($e);
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        }
        $this->_redirect('*/*/');
    }
}

TPL, [
            'Prefix' => $prefix,
            'EntityClass' => $entityClass,
            'AclResource' => $aclResource,
            'AdminRouteName' => $adminRouteName,
            'Entity' => $entityName,
            'EntityLabel' => $entityLabel,
            'Alias' => $alias,
            'Pk' => $pk,
        ]);

        return $out;
    }
}
