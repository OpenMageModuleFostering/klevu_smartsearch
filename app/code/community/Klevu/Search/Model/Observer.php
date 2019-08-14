<?php

/**
 * Class Klevu_Search_Model_Observer
 *
 * @method setIsProductSyncScheduled($flag)
 * @method bool getIsProductSyncScheduled()
 */
class Klevu_Search_Model_Observer extends Varien_Object {

    /**
     * Schedule a Product Sync to run immediately.
     *
     * @param Varien_Event_Observer $observer
     */
    public function scheduleProductSync(Varien_Event_Observer $observer) {
        if (!$this->getIsProductSyncScheduled()) {
            Mage::getModel("klevu_search/product_sync")->schedule();
            $this->setIsProductSyncScheduled(true);
        }
    }

    /**
     * Schedule an Order Sync to run immediately. If the observed event
     * contains an order, add it to the sync queue before scheduling.
     *
     * @param Varien_Event_Observer $observer
     */
    public function scheduleOrderSync(Varien_Event_Observer $observer) {
        $model = Mage::getModel("klevu_search/order_sync");

        $order = $observer->getEvent()->getOrder();
        if ($order) {
            $model->addOrderToQueue($order);
        }

        $model->schedule();
    }

    /**
     * When products are updated in bulk, update products so that they will be synced.
     * @param Varien_Event_Observer $observer
     */
    public function setProductsToSync(Varien_Event_Observer $observer) {
        $product_ids = $observer->getData('product_ids');

        if(empty($product_ids)) {
            return;
        }

        $product_ids = implode(',', $product_ids);
        $where = sprintf("product_id IN(%s) OR parent_id IN(%s)", $product_ids, $product_ids);
        $resource = Mage::getSingleton('core/resource');
        $resource->getConnection('core_write')
            ->update(
                $resource->getTableName('klevu_search/product_sync'),
                array('last_synced_at' => '0'),
                $where
            );
    }

    /**
     * Mark all of the products for update and then schedule a sync
     * to run immediately.
     *
     * @param Varien_Event_Observer $observer
     */
    public function syncAllProducts(Varien_Event_Observer $observer) {
        $store = null;
        $sync = Mage::getModel("klevu_search/product_sync");

        $attribute = $observer->getEvent()->getAttribute();
        if ($attribute instanceof Mage_Catalog_Model_Resource_Eav_Attribute) {
            // On attribute change, sync only if the attribute was added
            // or removed from layered navigation
            if ($attribute->getOrigData("is_filterable_in_search") == $attribute->getData("is_filterable_in_search")) {
                return;
            }
        }

        if ($observer->getEvent()->getStore()) {
            // Only sync products for a specific store if the event was fired in that store
            $store = Mage::app()->getStore($observer->getEvent()->getStore());
        }

        $sync->markAllProductsForUpdate($store);

        if (!$this->getIsProductSyncScheduled()) {
            $sync->schedule();
            $this->setIsProductSyncScheduled(true);
        }
    }
    /**
     * When product image updated from admin this will generate the image thumb.
     * @param Varien_Event_Observer $observer
     */
    public function createThumb(Varien_Event_Observer $observer) {
        $image = $observer->getEvent()->getProduct()->getImage();
        if(($image != "no_selection") && (!empty($image))) {
          Mage::getModel("klevu_search/product_sync")->thumbImage($image);
        }
    }
  
    /**
     * Apply model rewrites for the search landing page, if it is enabled.
     *
     * @param Varien_Event_Observer $observer
     */
    public function applyLandingPageModelRewrites(Varien_Event_Observer $observer) {
        if (Mage::helper("klevu_search/config")->isLandingEnabled()) {
            $rewrites = array(
                "global/models/catalogsearch_resource/rewrite/fulltext_collection"         => "Klevu_Search_Model_CatalogSearch_Resource_Fulltext_Collection",
                "global/models/catalogsearch_mysql4/rewrite/fulltext_collection"           => "Klevu_Search_Model_CatalogSearch_Resource_Fulltext_Collection",
                "global/models/catalogsearch/rewrite/layer_filter_attribute"               => "Klevu_Search_Model_CatalogSearch_Layer_Filter_Attribute",
                "global/models/catalog/rewrite/config"                                     => "Klevu_Search_Model_Catalog_Model_Config",
                "global/models/catalog/rewrite/layer_filter_price"                         => "Klevu_Search_Model_CatalogSearch_Layer_Filter_Price",
                "global/models/catalog/rewrite/layer_filter_category"                      => "Klevu_Search_Model_CatalogSearch_Layer_Filter_Category",
                "global/models/catalog_resource/rewrite/layer_filter_attribute"            => "Klevu_Search_Model_CatalogSearch_Resource_Layer_Filter_Attribute",
                "global/models/catalog_resource_eav_mysql4/rewrite/layer_filter_attribute" => "Klevu_Search_Model_CatalogSearch_Resource_Layer_Filter_Attribute"
            );

            $config = Mage::app()->getConfig();
            foreach ($rewrites as $key => $value) {
                $config->setNode($key, $value);
            }
        }
    }
    
    public function removeTest()
    {
        Mage::getModel("klevu_search/product_sync")->removeTestMode();    
        
    }
    
    
 
}