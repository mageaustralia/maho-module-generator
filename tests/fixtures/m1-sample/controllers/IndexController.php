<?php
/* Synthetic fixture controller. */
class Acme_Gadgets_IndexController extends Mage_Core_Controller_Front_Action
{
    public function indexAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    public function viewAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    public function submitAction()
    {
        $this->_redirect('*/*/');
    }
}
