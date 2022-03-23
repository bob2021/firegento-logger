<?php
/**
 * This file is part of a FireGento e.V. module.
 *
 * This FireGento e.V. module is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 3 as
 * published by the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * PHP version 5
 *
 * @category  FireGento
 * @package   FireGento_Logger
 * @author    FireGento Team <team@firegento.com>
 * @copyright 2013 FireGento Team (http://www.firegento.com)
 * @license   http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 */
/**
 * Log/Report Viewer for the backend
 *
 * @category FireGento
 * @package  FireGento_Logger
 * @author   FireGento Team <team@firegento.com>
 */
class FireGento_Logger_Adminhtml_LoggerController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Test the logger
     */
    public function testAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Show the log viewer
     */
    public function liveViewAction()
    {
        $this->loadLayout();
        $this->_setActiveMenu('system/firegento_logger/live_viewer');
        $this->renderLayout();
    }

    /**
     * Show the report viewer
     */
    public function reportViewAction()
    {
        $this->loadLayout();
        $this->_setActiveMenu('system/firegento_logger/report_viewer');
        $this->renderLayout();
    }

    /**
     * Ajax function for reading a log file
     *
     * @return string|void
     */
    public function liveViewAjaxAction()
    {
        $startPos = $this->getRequest()->getParam('position');
        $filename = Mage::getBaseDir('var') . DS . 'log' . DS . $this->getRequest()->getParam('logFile');
        if ( !file_exists($filename) ) {
            return '';
        }

        $handle = fopen($filename, 'r');
        $filesize = filesize($filename);

        if ($startPos == 0) {
            $lengthBefore = 1000;
            fseek($handle, -$lengthBefore, SEEK_END);
            $text = fread($handle, $filesize);

            $updates = '[...]' . substr($text, strpos($text, "\n"), strlen($text));
        } else {
            fseek($handle, $startPos, SEEK_SET);
            $updates = fread($handle, $filesize);
        }
        $newPos = ftell($handle);

        $response = '';
        if ($updates != null) {
            $response = Zend_Json::encode(array('text' => $updates, 'position' => $newPos));
        }

        $this->getResponse()->setBody($response);
    }

    /**
     * Ajax function for reading a report file
     *
     * @return string|void
     */
    public function reportAjaxAction()
    {
        $reportId = $this->getRequest()->getParam('report_id');
        $filename = Mage::getBaseDir('var') . DS . 'report' . DS . $reportId;

        if (!file_exists($filename)) {
            return '';
        }

        $handle = fopen($filename, 'r');
        $filesize = filesize($filename);

        $text = fread($handle, $filesize);

        $this->getResponse()->setBody(
            Zend_Json::encode(array('text' => $text))
        );
    }

    /**
     * Lists the modules and gives the user the option to dsiable/enable log output for them.
     * @return $this
     */
    public function manageModulesLogAction()
    {
        $this->loadLayout();
        $this->_setActiveMenu('system/firegento_logger/log_manager');
        $listBlock = $this->getLayout()->createBlock('firegento_logger/adminhtml_logger_manager', 'log_manager')->setTemplate('firegento_logger/manager.phtml');
        $this->_addContent($listBlock);
        $this->renderLayout();
    }

    /**
     * Disables provided module key's log output
     * @return $this
     */
    public function enableModulesLogAction()
    {
        $moduleKey = $this->getRequest()->getParam('module');
        Mage::getSingleton('firegento_logger/manager')->enableLogging($moduleKey);
        Mage::app()->getCacheInstance()->cleanType('config');
        $successMsg = Mage::helper('firegento_logger')->__("Logging for the module '%s' was successfully ENABLED.", $moduleKey);
        Mage::getSingleton('core/session')->addSuccess($successMsg);
        $this->_redirect("adminhtml/logger/manageModulesLog");
        return $this;
    }

    /**
     * Disables provided module key's log output
     * @return $this
     */
    public function disableModulesLogAction()
    {
        $moduleKey = $this->getRequest()->getParam('module');
        Mage::getSingleton('firegento_logger/manager')->disableLogging($moduleKey);
        Mage::app()->getCacheInstance()->cleanType('config');
        $successMsg = Mage::helper('firegento_logger')->__("Logging for the module '%s' was successfully DISABLED.", $moduleKey);
        Mage::getSingleton('core/session')->addSuccess($successMsg);
        $this->_redirect("adminhtml/logger/manageModulesLog");
        return $this;
    }

    /**
     * Check if admin user is allowed to view this controller actions
     *
     * @return bool Flag
     */
    protected function _isAllowed()
    {
        $flagEnabled = Mage::getStoreConfigFlag('logger/general/viewer_enabled');
        $flagAcl = Mage::getSingleton('admin/session')->isAllowed('system/logger');

        return $flagEnabled && $flagAcl;
    }
}
