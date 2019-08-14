<?php

class Klevu_Search_Adminhtml_Klevu_SearchController extends Mage_Adminhtml_Controller_Action {

    public function sync_allAction() {
        $store = $this->getRequest()->getParam("store");

        if ($store !== null) {
            try {
                $store = Mage::app()->getStore($store);
            } catch (Mage_Core_Model_Store_Exception $e) {
                Mage::getSingleton("adminhtml/session")->addError($this->__("Selected store could not be found!"));
                return $this->_redirectReferer("adminhtml/dashboard");
            }
        }

        if (Mage::helper('klevu_search/config')->isProductSyncEnabled()) {
            Mage::getModel('klevu_search/product_sync')
                ->markAllProductsForUpdate($store)
                ->schedule();

            if ($store) {
                Mage::helper("klevu_search")->log(Zend_Log::INFO, sprintf("Product Sync scheduled to re-sync ALL products in %s (%s).",
                    $store->getWebsite()->getName(),
                    $store->getName()
                ));

                Mage::getSingleton("adminhtml/session")->addSuccess($this->__("Klevu Search Product Sync scheduled to be run on the next cron run for ALL products in %s (%s).",
                    $store->getWebsite()->getName(),
                    $store->getName()
                ));
            } else {
                Mage::helper("klevu_search")->log(Zend_Log::INFO, "Product Sync scheduled to re-sync ALL products.");

                Mage::getSingleton('adminhtml/session')->addSuccess($this->__("Klevu Search Product Sync scheduled to be run on the next cron run for ALL products."));
            }
        } else {
            Mage::getSingleton('adminhtml/session')->addError($this->__("Klevu Search Product Sync is disabled."));
        }

        return $this->_redirectReferer("adminhtml/dashboard");
    }

    public function manual_syncAction() {
        Mage::getModel("klevu_search/product_sync")->runManually();

        return $this->_redirectReferer("adminhtml/dashboard");
    }
    /**
     * Get the product ids for thumbnails and schedual the cron. 
     */
    public function generate_thumbnailAction() {
        try {
                Mage::getModel('klevu_search/product_sync')->getEntityIds();
                Mage::getModel('klevu_search/product_sync')->scheduleImageCron();
                Mage::getSingleton('adminhtml/session')->addSuccess($this->__("Klevu thumbnails generation task scheduled to be run on the next cron run."));
        }
        catch (Exception $e) {
                Mage::logException($e);
                Mage::getSingleton('adminhtml/session')->addError($this->__("Unable to generate product images. Please try later."));
        }
        return $this->_redirectReferer("adminhtml/dashboard");
    }
}
