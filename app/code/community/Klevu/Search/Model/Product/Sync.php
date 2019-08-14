<?php

/**
 * Class Klevu_Search_Model_Product_Sync
 * @method Varien_Db_Adapter_Interface getConnection()
 * @method Mage_Core_Model_Store getStore()
 * @method string getSessionId()
 */
class Klevu_Search_Model_Product_Sync extends Klevu_Search_Model_Sync {

    /**
     * It has been determined during development that Product Sync uses around
     * 120kB of memory for each product it syncs, or around 10MB of memory for
     * each 100 product page.
     */
    const RECORDS_PER_PAGE = 100;

    const NOTIFICATION_GLOBAL_TYPE = "product_sync";
    const NOTIFICATION_STORE_TYPE_PREFIX = "product_sync_store_";

    public function _construct() {
        parent::_construct();

        $this->addData(array(
            'connection' => Mage::getModel('core/resource')->getConnection("core_write")
        ));
    }

    public function getJobCode() {
        return "klevu_search_product_sync";
    }

    /**
     * Perform Product Sync on any configured stores, adding new products, updating modified and
     * deleting removed products since last sync.
     */
    public function run() {
    
        try {
           
            /* mark for update special price product */
            $this->markProductForupdate();
            
            if ($this->isRunning(2)) {
                // Stop if another copy is already running
                $this->log(Zend_Log::INFO, "Stopping because another copy is already running.");
                return;
            }

            $stores = Mage::app()->getStores();

            foreach ($stores as $store) {
                /** @var Mage_Core_Model_Store $store */
                $this->reset();

                if ($this->rescheduleIfOutOfMemory()) {
                    return;
                }

                if (!$this->setupSession($store)) {
                    continue;
                }

                $this->log(Zend_Log::INFO, sprintf("Starting sync for %s (%s).", $store->getWebsite()->getName(), $store->getName()));

                $actions = array(
                    'delete' => $this->getConnection()
                        ->select()
                        /*
                         * Select synced products in the current store/mode that are no longer enabled
                         * (don't exist in the products table, or have status disabled for the current
                         * store, or have status disabled for the default store) or are not visible
                         * (in the case of configurable products, check the parent visibility instead).
                         */
                        ->from(
                            array('k' => $this->getTableName("klevu_search/product_sync")),
                            array('product_id' => "k.product_id", 'parent_id' => "k.parent_id")
                        )
                        ->joinLeft(
                            array('v' => $this->getTableName("catalog/category_product_index")),
                            "v.product_id = k.product_id AND v.store_id = :store_id",
                            ""
                        )
                        ->joinLeft(
                            array('p' => $this->getTableName("catalog/product")),
                            "p.entity_id = k.product_id",
                            ""
                        )
                        ->joinLeft(
                            array('ss' => $this->getProductStatusAttribute()->getBackendTable()),
                            "ss.attribute_id = :status_attribute_id AND ss.entity_id = k.product_id AND ss.store_id = :store_id",
                            ""
                        )
                        ->joinLeft(
                            array('sd' => $this->getProductStatusAttribute()->getBackendTable()),
                            "sd.attribute_id = :status_attribute_id AND sd.entity_id = k.product_id AND sd.store_id = :default_store_id",
                            ""
                        )
                        ->where("(k.store_id = :store_id) AND (k.test_mode = :test_mode) AND ((p.entity_id IS NULL) OR (CASE WHEN ss.value_id > 0 THEN ss.value ELSE sd.value END != :status_enabled) OR (CASE WHEN k.parent_id = 0 THEN k.product_id ELSE k.parent_id END NOT IN (?)))",
                            $this->getConnection()
                                ->select()
                                ->from(
                                    array('i' => $this->getTableName("catalog/category_product_index")),
                                    array('id' => "i.product_id")
                                )
                                ->where("(i.store_id = :store_id) AND (i.visibility IN (:visible_both, :visible_search))")
                        )
                        ->group(array('k.product_id', 'k.parent_id'))
                        ->bind(array(
                            'store_id'       => $store->getId(),
                            'default_store_id' => Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID,
                            'test_mode'      => $this->isTestModeEnabled(),
                            'status_attribute_id' => $this->getProductStatusAttribute()->getId(),
                            'status_enabled' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED,
                            'visible_both'   => Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
                            'visible_search' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH
                        )),

                    'update' => $this->getConnection()
                        ->select()
                        ->union(array(
                            // Select products without parents that need to be updated
                            $this->getConnection()
                                ->select()
                                /*
                                 * Select synced non-configurable products for the current store/mode
                                 * that are visible (using the category product index) and have been
                                 * updated since last sync.
                                 */
                                ->from(
                                    array('k' => $this->getTableName("klevu_search/product_sync")),
                                    array('product_id' => "k.product_id", 'parent_id' => "k.parent_id")
                                )
                                ->join(
                                    array('p' => $this->getTableName("catalog/product")),
                                    "p.entity_id = k.product_id",
                                    ""
                                )
                                ->join(
                                    array('i' => $this->getTableName("catalog/category_product_index")),
                                    "i.product_id = k.product_id AND k.store_id = i.store_id AND i.visibility IN (:visible_both, :visible_search)",
                                    ""
                                )
                                ->where("(k.store_id = :store_id) AND (k.test_mode = :test_mode) AND (p.type_id != :configurable) AND (p.updated_at > k.last_synced_at)"),
                            // Select products with parents (configurable) that need to be updated
                            $this->getConnection()
                                ->select()
                                /*
                                 * Select synced products for the current store/mode that are configurable
                                 * children (have entries in the super link table), are enabled for the current
                                 * store (or the default store), have visible parents (using the category product
                                 * index) and, either the product or the parent, have been updated since last sync.
                                 */
                                ->from(
                                    array('k' => $this->getTableName("klevu_search/product_sync")),
                                    array('product_id' => "k.product_id", 'parent_id' => "k.parent_id")
                                )
                                ->join(
                                    array('s' => $this->getTableName("catalog/product_super_link")),
                                    "k.parent_id = s.parent_id AND k.product_id = s.product_id",
                                    ""
                                )
                                ->join(
                                    array('i' => $this->getTableName("catalog/category_product_index")),
                                    "k.parent_id = i.product_id AND k.store_id = i.store_id AND i.visibility IN (:visible_both, :visible_search)",
                                    ""
                                )
                                ->join(
                                    array('p1' => $this->getTableName("catalog/product")),
                                    "k.product_id = p1.entity_id",
                                    ""
                                )
                                ->join(
                                    array('p2' => $this->getTableName("catalog/product")),
                                    "k.parent_id = p2.entity_id",
                                    ""
                                )
                                ->joinLeft(
                                    array('ss' => $this->getProductStatusAttribute()->getBackendTable()),
                                    "ss.attribute_id = :status_attribute_id AND ss.entity_id = k.product_id AND ss.store_id = :store_id",
                                    ""
                                )
                                ->joinLeft(
                                    array('sd' => $this->getProductStatusAttribute()->getBackendTable()),
                                    "sd.attribute_id = :status_attribute_id AND sd.entity_id = k.product_id AND sd.store_id = :default_store_id",
                                    ""
                                )
                                ->where("(k.store_id = :store_id) AND (k.test_mode = :test_mode) AND (CASE WHEN ss.value_id > 0 THEN ss.value ELSE sd.value END = :status_enabled) AND ((p1.updated_at > k.last_synced_at) OR (p2.updated_at > k.last_synced_at))")
                        ))
                        ->group(array('k.product_id', 'k.parent_id'))
                        ->bind(array(
                            'store_id' => $store->getId(),
                            'default_store_id' => Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID,
                            'test_mode' => $this->isTestModeEnabled(),
                            'configurable' => Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE,
                            'visible_both' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
                            'visible_search' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
                            'status_attribute_id' => $this->getProductStatusAttribute()->getId(),
                            'status_enabled' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED,
                        )),

                    'add' => $this->getConnection()
                        ->select()
                        ->union(array(
                            // Select non-configurable products that need to be added
                            $this->getConnection()
                                ->select()
                                /*
                                 * Select non-configurable products that are visible in the current
                                 * store (using the category product index), but have not been synced
                                 * for this store yet.
                                 */
                                ->from(
                                    array('p' => $this->getTableName("catalog/product")),
                                    array('product_id' => "p.entity_id", 'parent_id' => new Zend_Db_Expr("0"))
                                )
                                ->join(
                                    array('i' => $this->getTableName("catalog/category_product_index")),
                                    "p.entity_id = i.product_id AND i.store_id = :store_id AND i.visibility IN (:visible_both, :visible_search)",
                                    ""
                                )
                                ->joinLeft(
                                    array('k' => $this->getTableName("klevu_search/product_sync")),
                                    "p.entity_id = k.product_id AND k.parent_id = 0 AND i.store_id = k.store_id AND k.test_mode = :test_mode",
                                    ""
                                )
                                ->where("(p.type_id != :configurable) AND (k.product_id IS NULL)"),
                            // Select configurable parent & product pairs that need to be added
                            $this->getConnection()
                                ->select()
                                /*
                                 * Select configurable product children that are enabled (for the current
                                 * store or for the default store), have visible parents (using the category
                                 * product index) and have not been synced yet for the current store with
                                 * the current parent.
                                 */
                                ->from(
                                    array('s' => $this->getTableName("catalog/product_super_link")),
                                    array('product_id' => "s.product_id", 'parent_id' => "s.parent_id")
                                )
                                ->join(
                                    array('i' => $this->getTableName("catalog/category_product_index")),
                                    "s.parent_id = i.product_id AND i.store_id = :store_id AND i.visibility IN (:visible_both, :visible_search)",
                                    ""
                                )
                                ->joinLeft(
                                    array('ss' => $this->getProductStatusAttribute()->getBackendTable()),
                                    "ss.attribute_id = :status_attribute_id AND ss.entity_id = s.product_id AND ss.store_id = :store_id",
                                    ""
                                )
                                ->joinLeft(
                                    array('sd' => $this->getProductStatusAttribute()->getBackendTable()),
                                    "sd.attribute_id = :status_attribute_id AND sd.entity_id = s.product_id AND sd.store_id = :default_store_id",
                                    ""
                                )
                                ->joinLeft(
                                    array('k' => $this->getTableName("klevu_search/product_sync")),
                                    "s.parent_id = k.parent_id AND s.product_id = k.product_id AND k.store_id = :store_id AND k.test_mode = :test_mode",
                                    ""
                                )
                                ->where("(CASE WHEN ss.value_id > 0 THEN ss.value ELSE sd.value END = :status_enabled) AND (k.product_id IS NULL)")
                        ))
                        ->group(array('k.product_id', 'k.parent_id'))
                        ->bind(array(
                            'store_id' => $store->getId(),
                            'default_store_id' => Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID,
                            'test_mode' => $this->isTestModeEnabled(),
                            'configurable' => Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE,
                            'visible_both' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
                            'visible_search' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
                            'status_attribute_id' => $this->getProductStatusAttribute()->getId(),
                            'status_enabled' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED
                        ))
                );

                $errors = 0;

                foreach ($actions as $action => $statement) {
                    if ($this->rescheduleIfOutOfMemory()) {
                        return;
                    }
                    $method = $action . "Products";

                    $products = $this->getConnection()->fetchAll($statement, $statement->getBind());

                    $total = count($products);
                    $this->log(Zend_Log::INFO, sprintf("Found %d products to %s.", $total, $action));
                    $pages = ceil($total / static::RECORDS_PER_PAGE);
                    for ($page = 1; $page <= $pages; $page++) {
                        if ($this->rescheduleIfOutOfMemory()) {
                            return;
                        }

                        $offset = ($page - 1) * static::RECORDS_PER_PAGE;
                        $result = $this->$method(array_slice($products, $offset, static::RECORDS_PER_PAGE));

                        if ($result !== true) {
                            $errors++;
                            $this->log(Zend_Log::ERR, sprintf("Errors occurred while attempting to %s products %d - %d: %s",
                                $action,
                                $offset + 1,
                                ($offset + static::RECORDS_PER_PAGE <= $total) ? $offset + static::RECORDS_PER_PAGE : $total,
                                $result
                            ));
                            /*$this->notify(
                                Mage::helper('klevu_search')->__("Product Sync for %s (%s) failed to %s some products. Please consult the logs for more details.",
                                    $store->getWebsite()->getName(),
                                    $store->getName(),
                                    $action
                                ),
                                $store
                            );*/
                        }
                    }
                }

                $this->log(Zend_Log::INFO, sprintf("Finished sync for %s (%s).", $store->getWebsite()->getName(), $store->getName()));

                $config = Mage::helper('klevu_search/config');
                if (!$config->isExtensionEnabled($store) && !$config->hasProductSyncRun($store)) {
                    // Enable Klevu Search after the first sync
                    $config->setExtensionEnabledFlag(true, $store);
                    $this->log(Zend_Log::INFO, sprintf("Automatically enabled Klevu Search on Frontend for %s (%s).",
                        $store->getWebsite()->getName(),
                        $store->getName()
                    ));
                }
                $config->setLastProductSyncRun("now", $store);

                if ($errors == 0) {
                    // If Product Sync finished without any errors, notifications are not relevant anymore
                    $this->deleteNotifications($store);
                }
            }
        } catch (Exception $e) {
            // Catch the exception that was thrown, log it, then throw a new exception to be caught the Magento cron.
            Mage::helper('klevu_search')->log(Zend_Log::CRIT, sprintf("Exception thrown in %s::%s - %s", __CLASS__, __METHOD__, $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Run the product sync manually, creating a cron schedule entry
     * to prevent other syncs from running.
     */
    public function runManually() {
        $time = date_create("now")->format("Y-m-d H:i:s");
        $schedule = Mage::getModel("cron/schedule");
        $schedule
            ->setJobCode($this->getJobCode())
            ->setCreatedAt($time)
            ->setScheduledAt($time)
            ->setExecutedAt($time)
            ->setStatus(Mage_Cron_Model_Schedule::STATUS_RUNNING)
            ->save();

        try {
            $this->run();
        } catch (Exception $e) {
            Mage::logException($e);

            $schedule
                ->setMessages($e->getMessage())
                ->setStatus(Mage_Cron_Model_Schedule::STATUS_ERROR)
                ->save();

            return;
        }

        $time = date_create("now")->format("Y-m-d H:i:s");
        $schedule
            ->setFinishedAt($time)
            ->setStatus(Mage_Cron_Model_Schedule::STATUS_SUCCESS)
            ->save();

        return;
    }

    /**
     * Mark all products to be updated the next time Product Sync runs.
     *
     * @param Mage_Core_Model_Store|int $store If passed, will only update products for the given store.
     *
     * @return $this
     */
    public function markAllProductsForUpdate($store = null) {
        $where = "";
        if ($store !== null) {
            $store = Mage::app()->getStore($store);

            $where = $this->getConnection()->quoteInto("store_id =  ?", $store->getId());
        }

        $this->getConnection()->update(
            $this->getTableName('klevu_search/product_sync'),
            array('last_synced_at' => '0'),
            $where
        );

        return $this;
    }

    /**
     * Forget the sync status of all the products for the given Store and test mode.
     * If no store or test mode status is given, clear products for all stores and modes respectively.
     *
     * @param Mage_Core_Model_Store|int|null $store
     * @param bool|null $test_mode
     *
     * @return int
     */
    public function clearAllProducts($store = null, $test_mode = null) {
        $select = $this->getConnection()
            ->select()
            ->from(
                array("k" => $this->getTableName("klevu_search/product_sync"))
            );

        if ($store) {
            $store = Mage::app()->getStore($store);

            $select->where("k.store_id = ?", $store->getId());
        }

        if ($test_mode !== null) {
            $test_mode = ($test_mode) ? 1 : 0;

            $select->where("k.test_mode = ?", $test_mode);
        }

        $result = $this->getConnection()->query($select->deleteFromSelect("k"));
        return $result->rowCount();
    }

    /**
     * Return the product status attribute model.
     *
     * @return Mage_Catalog_Model_Resource_Eav_Attribute
     */
    protected function getProductStatusAttribute() {
        if (!$this->hasData("status_attribute")) {
            $this->setData("status_attribute", Mage::getSingleton('eav/config')->getAttribute(Mage_Catalog_Model_Product::ENTITY, 'status'));
        }

        return $this->getData("status_attribute");
    }

    /**
     * Return the product visibility attribute model.
     *
     * @return Mage_Catalog_Model_Resource_Eav_Attribute
     */
    protected function getProductVisibilityAttribute() {
        if (!$this->hasData("visibility_attribute")) {
            $this->setData("visibility_attribute", Mage::getSingleton('eav/config')->getAttribute(Mage_Catalog_Model_Product::ENTITY, 'visibility'));
        }

        return $this->getData("visibility_attribute");
    }

    /**
     * Setup an API session for the given store. Sets the store and session ID on self. Returns
     * true on success or false if Product Sync is disabled, store is not configured or the
     * session API call fails.
     *
     * @param Mage_Core_Model_Store $store
     *
     * @return bool
     */
    protected function setupSession(Mage_Core_Model_Store $store) {
        $config = Mage::helper('klevu_search/config');

        if (!$config->isProductSyncEnabled($store->getId())) {
            $this->log(Zend_Log::INFO, sprintf("Disabled for %s (%s).", $store->getWebsite()->getName(), $store->getName()));
            return null;
        }

        $api_key = $config->getRestApiKey($store->getId());
        if (!$api_key) {
            $this->log(Zend_Log::INFO, sprintf("No API key found for %s (%s).", $store->getWebsite()->getName(), $store->getName()));
            return null;
        }

        $response = Mage::getModel('klevu_search/api_action_startsession')->execute(array(
            'api_key' => $api_key,
            'store' => $store,
        ));

        if ($response->isSuccessful()) {
            $this->addData(array(
                'store'      => $store,
                'session_id' => $response->getSessionId()
            ));
            return true;
        } else {
            $this->log(Zend_Log::ERR, sprintf("Failed to start a session for %s (%s): %s",
                $store->getWebsite()->getName(),
                $store->getName(),
                $response->getMessage()
            ));

            if ($response instanceof Klevu_Search_Model_Api_Response_Empty) {
                /*$this->notify(
                    Mage::helper('klevu_search')->__(
                        "Product Sync failed for %s (%s): Could not contact Klevu.",
                        $store->getWebsite()->getName(),
                        $store->getName()
                    )
                );*/
            } else {
                $this->notify(
                    Mage::helper('klevu_search')->__(
                        "Product Sync failed for %s (%s): %s",
                        $store->getWebsite()->getName(),
                        $store->getName(),
                        $response->getMessage()
                    )
                );
            }

            return false;
        }
    }

    /**
     * Delete the given products from Klevu Search. Returns true if the operation was
     * successful, or the error message if the operation failed.
     *
     * @param array $data List of products to delete. Each element should be an array
     *                    containing an element with "product_id" as the key and product id as
     *                    the value and an optional "parent_id" element with the parent id.
     *
     * @return bool|string
     */
    protected function deleteProducts(array $data) {
        $total = count($data);

        $response = Mage::getModel('klevu_search/api_action_deleterecords')
            ->setStore($this->getStore())
            ->execute(array(
            'sessionId' => $this->getSessionId(),
            'records'   => array_map(function ($v) {
                return array('id' => Mage::helper('klevu_search')->getKlevuProductId($v['product_id'], $v['parent_id']));
            }, $data)
        ));

        if ($response->isSuccessful()) {
            $connection = $this->getConnection();

            $select = $connection
                ->select()
                ->from(array('k' => $this->getTableName("klevu_search/product_sync")))
                ->where("k.store_id = ?", $this->getStore()->getId())
                ->where("k.test_mode = ?", $this->isTestModeEnabled());

            $skipped_record_ids = array();
            if ($skipped_records = $response->getSkippedRecords()) {
                $skipped_record_ids = array_flip($skipped_records["index"]);
            }

            $or_where = array();
            for ($i = 0; $i < count($data); $i++) {
                if (isset($skipped_record_ids[$i])) {
                    continue;
                }
                $or_where[] = sprintf("(%s AND %s)",
                    $connection->quoteInto("k.product_id = ?", $data[$i]['product_id']),
                    $connection->quoteInto("k.parent_id = ?", $data[$i]['parent_id'])
                );
            }
            $select->where(implode(" OR ", $or_where));

            $connection->query($select->deleteFromSelect("k"));

            $skipped_count = count($skipped_record_ids);
            if ($skipped_count > 0) {
                return sprintf("%d product%s failed (%s)",
                    $skipped_count,
                    ($skipped_count > 1) ? "s" : "",
                    implode(", ", $skipped_records["messages"])
                );
            } else {
                return true;
            }
        } else {
            return sprintf("%d product%s failed (%s)",
                $total,
                ($total > 1) ? "s" : "",
                $response->getMessage()
            );
        }
    }

    /**
     * Update the given products on Klevu Search. Returns true if the operation was successful,
     * or the error message if it failed.
     *
     * @param array $data List of products to update. Each element should be an array
     *                    containing an element with "product_id" as the key and product id as
     *                    the value and an optional "parent_id" element with the parent id.
     *
     * @return bool|string
     */
    protected function updateProducts(array $data) {
        $total = count($data);

        $this->addProductSyncData($data);

        $response = Mage::getModel('klevu_search/api_action_updaterecords')
            ->setStore($this->getStore())
            ->execute(array(
            'sessionId' => $this->getSessionId(),
            'records'   => $data
        ));

        if ($response->isSuccessful()) {
            $helper = Mage::helper('klevu_search');
            $connection = $this->getConnection();

            $skipped_record_ids = array();
            if ($skipped_records = $response->getSkippedRecords()) {
                $skipped_record_ids = array_flip($skipped_records["index"]);
            }

            $where = array();
            for ($i = 0; $i < count($data); $i++) {
                if (isset($skipped_record_ids[$i])) {
                    continue;
                }

                $ids = $helper->getMagentoProductId($data[$i]['id']);

                $where[] = sprintf("(%s AND %s)",
                    $connection->quoteInto("product_id = ?", $ids['product_id']),
                    $connection->quoteInto("parent_id = ?", $ids['parent_id'])
                );
            }

            $where = sprintf("(%s) AND (%s) AND (%s)",
                $connection->quoteInto("store_id = ?", $this->getStore()->getId()),
                $connection->quoteInto("test_mode = ?", $this->isTestModeEnabled()),
                implode(" OR ", $where)
            );

            $this->getConnection()->update(
                $this->getTableName('klevu_search/product_sync'),
                array('last_synced_at' => Mage::helper("klevu_search/compat")->now()),
                $where
            );

            $skipped_count = count($skipped_record_ids);
            if ($skipped_count > 0) {
                return sprintf("%d product%s failed (%s)",
                    $skipped_count,
                    ($skipped_count > 1) ? "s" : "",
                    implode(", ", $skipped_records["messages"])
                );
            } else {
                return true;
            }
        } else {
            return sprintf("%d product%s failed (%s)",
                $total,
                ($total > 1) ? "s" : "",
                $response->getMessage()
            );
        }
    }

    /**
     * Add the given products to Klevu Search. Returns true if the operation was successful,
     * or the error message if it failed.
     *
     * @param array $data List of products to add. Each element should be an array
     *                    containing an element with "product_id" as the key and product id as
     *                    the value and an optional "parent_id" element with the parent id.
     *
     * @return bool|string
     */
    protected function addProducts(array $data) {
        $total = count($data);

        $this->addProductSyncData($data);

        $response = Mage::getModel('klevu_search/api_action_addrecords')
            ->setStore($this->getStore())
            ->execute(array(
            'sessionId' => $this->getSessionId(),
            'records'   => $data
        ));

        if ($response->isSuccessful()) {

            $skipped_record_ids = array();
            if ($skipped_records = $response->getSkippedRecords()) {
                $skipped_record_ids = array_flip($skipped_records["index"]);
            }

            $sync_time = Mage::helper("klevu_search/compat")->now();

            foreach($data as $i => &$record) {
                if (isset($skipped_record_ids[$i])) {
                    unset($data[$i]);
                    continue;
                }

                $ids = Mage::helper("klevu_search")->getMagentoProductId($data[$i]['id']);

                $record = array(
                    $ids["product_id"],
                    $ids["parent_id"],
                    $this->getStore()->getId(),
                    $this->isTestModeEnabled(),
                    $sync_time
                );
            }

            $this->getConnection()->insertArray(
                $this->getTableName('klevu_search/product_sync'),
                array("product_id", "parent_id", "store_id", "test_mode", "last_synced_at"),
                $data
            );

            $skipped_count = count($skipped_record_ids);
            if ($skipped_count > 0) {
                return sprintf("%d product%s failed (%s)",
                    $skipped_count,
                    ($skipped_count > 1) ? "s" : "",
                    implode(", ", $skipped_records["messages"])
                );
            } else {
                return true;
            }
        } else {
            return sprintf("%d product%s failed (%s)",
                $total,
                ($total > 1) ? "s" : "",
                $response->getMessage()
            );
        }
    }

    /**
     * Add the Product Sync data to each product in the given list. Updates the given
     * list directly to save memory.
     *
     * @param array $products An array of products. Each element should be an array with
     *                        containing an element with "id" as the key and the product
     *                        ID as the value.
     *
     * @return $this
     */
    protected function addProductSyncData(&$products) {
        $product_ids = array();
        $parent_ids = array();
        foreach ($products as $product) {
            $product_ids[] = $product['product_id'];
            if ($product['parent_id'] != 0) {
                $product_ids[] = $product['parent_id'];
                $parent_ids[] = $product['parent_id'];
            }
        }
        $product_ids = array_unique($product_ids);
        $parent_ids = array_unique($parent_ids);

        $data = Mage::getModel('catalog/product')->getCollection()
            ->addIdFilter($product_ids)
            ->setStore($this->getStore())
            ->addStoreFilter()
            ->addAttributeToSelect($this->getUsedMagentoAttributes());

        $data->load()
            ->addCategoryIds();

        $url_rewrite_data = $this->getUrlRewriteData($product_ids);
        $visibility_data = $this->getVisibilityData($product_ids);
        $price_data = $this->getPriceData($product_ids);
        $configurable_price_data = $this->getConfigurablePriceData($parent_ids);

        $stock_data = $this->getStockData($product_ids);

        $attribute_map = $this->getAttributeMap();
        if(Mage::app()->getStore()->isFrontUrlSecure()) {
            $base_url = $this->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK,true);
            $media_url = $this->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA,true);
        }else {
            $base_url = $this->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
            $media_url = $this->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA);
        }
        $currency = $this->getStore()->getDefaultCurrencyCode();
        $media_url .= Mage::getModel('catalog/product_media_config')->getBaseMediaUrlAddition();

        foreach ($products as $index => &$product) {
            $item = $data->getItemById($product['product_id']);
            $parent = ($product['parent_id'] != 0) ? $data->getItemById($product['parent_id']) : null;

            if (!$item) {
                // Product data query did not return any data for this product
                // Remove it from the list to skip syncing it
                $this->log(Zend_Log::WARN, sprintf("Failed to retrieve data for product ID %d", $product['product_id']));
                unset($products[$index]);
                continue;
            }

            // Add data from mapped attributes
            foreach ($attribute_map as $key => $attributes) {
                $product[$key] = null;

                switch ($key) {
                    case "boostingAttribute":
                        foreach ($attributes as $attribute) {
                            if ($parent && $parent->getData($attribute)) {
                                $product[$key] = $parent->getData($attribute);
                                break;
                            } else {
                                $product[$key] = $item->getData($attribute);
                                break;
                            }
                        }
                        break;  
                    case "otherAttributeToIndex":
                    case "other":
                        $product[$key] = array();
                        foreach ($attributes as $attribute) {
                            if ($item->getData($attribute)) {
                                $product[$key][$attribute] = $this->getAttributeData($attribute, $item->getData($attribute));
                            } else if ($parent && $parent->getData($attribute)) {
                                $product[$key][$attribute] = $this->getAttributeData($attribute, $parent->getData($attribute));
                            }
                        }
                        break;
                     case "sku":
                        foreach ($attributes as $attribute) {
                            if ($parent && $parent->getData($attribute)) {
                                $product[$key] = Mage::helper('klevu_search')->getKlevuProductSku($item->getData($attribute), $parent->getData($attribute));
                                break;
                            } else {
                                $product[$key] = $item->getData($attribute);
                                break;
                            }
                        }
                        break;
                    case "name":
                        foreach ($attributes as $attribute) {
                            if ($parent && $parent->getData($attribute)) {
                                $product[$key] = $parent->getData($attribute);
                                break;
                            }else if ($item->getData($attribute)) {
                                $product[$key] = $item->getData($attribute);
                                break;
                            }
                        }
                        break;
                    case "image":
                        foreach ($attributes as $attribute) {
                            if ($item->getData($attribute) && $item->getData($attribute) != "no_selection") {
                                $product[$key] = $item->getData($attribute);
                                break;
                            } else if ($parent && $parent->getData($attribute) && $parent->getData($attribute) != "no_selection") {
                                $product[$key] = $parent->getData($attribute);
                               
                                break;
                            }
                        }
                        if ($product[$key] != "" && strpos($product[$key], "http") !== 0) {
                            // Prepend media base url for relative image locations
                            //generate thumbnail image for each products
                            Mage::getModel('klevu_search/product_sync')->thumbImage($product[$key]);
                            $imageResized = Mage::getBaseDir('media').DS."klevu_images".$product[$key];
                                if (file_exists($imageResized)) {
                                    if(Mage::app()->getStore()->isFrontUrlSecure()) {
                                        $product[$key] = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA,true)."klevu_images".$product[$key];
                                    } else {
                                        $product[$key] = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA)."klevu_images".$product[$key];
                                    }
                                }else{
                                    $product[$key] = $media_url . $product[$key];
                                }
                        }
                        break;
                    case "salePrice":
                        // Default to 0 if price can't be determined
                        $product['salePrice'] = 0;
                        $tax_class_id = "";
                        if ($item->getData("tax_class_id") !== null) {
                            $tax_class_id = $item->getData("tax_class_id");
                        } else if ($parent) {
                            $tax_class_id = $parent->getData("tax_class_id");
                        }else {
                            $tax_class_id = "";
                        }

                        if ($parent && $parent->getData("type_id") == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE) {
                            // Calculate configurable product price based on option values
                            $price = (isset($price_data[$product['parent_id']])) ? $price_data[$product['parent_id']]['min_price'] : $parent->getData("price");
                            $markup = 0;

                            if (isset($configurable_price_data[$product['parent_id']])) {
                                foreach ($configurable_price_data[$product['parent_id']] as $attribute => $pricing_data) {
                                    $value = $item->getData($attribute);
                                    if ($value && isset($pricing_data[$value])) {
                                        if ($pricing_data[$value]["is_percent"]) {
                                            $markup += $price * ($pricing_data[$value]["value"] / 100);
                                        } else {
                                            $markup += $pricing_data[$value]["value"];
                                        }
                                    }
                                }
                            }
                            // show low price for config products
                            $product['startPrice'] = $this->processPrice($price , $tax_class_id, $parent);
                            
                            // also send sale price for sorting and filters for klevu 
                            $product['salePrice'] = $this->processPrice($price , $tax_class_id, $parent);
                        } else {
                            // Use price index prices to set the product price and start/end prices if available
                            // Falling back to product price attribute if not
                            if (isset($price_data[$product['product_id']])) {
                                // Always use minimum price as the sale price as it's the most accurate
                                $product['salePrice'] = $this->processPrice($price_data[$product['product_id']]['min_price'], $tax_class_id, $item);
                                if ($price_data[$product['product_id']]['min_price'] != $price_data[$product['product_id']]['max_price']) {
                                    $product['startPrice'] = $this->processPrice($price_data[$product['product_id']]['min_price'], $tax_class_id, $item);

                                    // Maximum price on a grouped product is meaningless as it depends on quantity and items bought
                                    if ($item->getData('type_id') != Mage_Catalog_Model_Product_Type::TYPE_GROUPED) {
                                        $product['toPrice'] = $this->processPrice($price_data[$product['product_id']]['max_price'], $tax_class_id, $item);
                                    }
                                }
                            } else {
                                if ($item->getData("price") !== null) {
                                    $product["salePrice"] = $this->processPrice($item->getData("price"), $tax_class_id, $item);
                                } else if ($parent) {
                                    $product["salePrice"] = $this->processPrice($parent->getData("price"), $tax_class_id, $item);
                                }
                            }
                        }

                        break;
                    default:
                        foreach ($attributes as $attribute) {
                            if ($item->getData($attribute)) {
                                $product[$key] = $this->getAttributeData($attribute, $item->getData($attribute));
                                break;
                            } else if ($parent && $parent->getData($attribute)) {
                                $product[$key] = $this->getAttributeData($attribute, $parent->getData($attribute));
                                break;
                            }
                        }
                }
            }

            // Add non-attribute data
            $product['currency'] = $currency;

            if ($item->getCategoryIds()) {
                $product['category'] = $this->getLongestPathCategoryName($item->getCategoryIds());
                $product['listCategory'] = $this->getCategoryNames($item->getCategoryIds());
            } else if ($parent) {
                $product['category'] = $this->getLongestPathCategoryName($parent->getCategoryIds());
                $product['listCategory'] = $this->getCategoryNames($parent->getCategoryIds());
            } else {
                $product['category'] = "";
                $product['listCategory'] = "";
            }


            // Use the parent URL if the product is invisible (and has a parent) and
            // use a URL rewrite if one exists, falling back to catalog/product/view
            if (isset($visibility_data[$product['product_id']]) && !$visibility_data[$product['product_id']] && $parent) {
                $product['url'] = $base_url . (
                    (isset($url_rewrite_data[$product['parent_id']])) ?
                        $url_rewrite_data[$product['parent_id']] :
                        "catalog/product/view/id/" . $product['parent_id']
                    );
            } else {
                if($parent) {
                  $product['url'] = $base_url . (
                      (isset($url_rewrite_data[$product['parent_id']])) ?
                          $url_rewrite_data[$product['parent_id']] :
                          "catalog/product/view/id/" . $product['parent_id']
                      );                
                } else {
                  $product['url'] = $base_url . (
                    (isset($url_rewrite_data[$product['product_id']])) ?
                        $url_rewrite_data[$product['product_id']] :
                        "catalog/product/view/id/" . $product['product_id']
                    );
                }
            }

            // Add stock data
            $product['inStock'] = ($stock_data[$product['product_id']]) ? "yes" : "no";

            // Configurable product relation
            if ($product['parent_id'] != 0) {
                $product['itemGroupId'] = $product['parent_id'];
            }

            // Set ID data
            $product['id'] = Mage::helper('klevu_search')->getKlevuProductId($product['product_id'], $product['parent_id']);
            unset($product['product_id']);
            unset($product['parent_id']);
        }

        return $this;
    }

    /**
     * Return the URL rewrite data for the given products for the current store.
     *
     * @param array $product_ids A list of product IDs.
     *
     * @return array A list with product IDs as keys and request paths as values.
     */
    protected function getUrlRewriteData($product_ids) {
        $stmt = $this->getConnection()->query(
            Mage::helper('klevu_search/compat')->getProductUrlRewriteSelect($product_ids, 0, $this->getStore()->getId())
        );

        $url_suffix = Mage::helper('catalog/product')->getProductUrlSuffix($this->getStore()->getId());
        if ($url_suffix && substr($url_suffix, 0, 1) !== ".") {
            $url_suffix = "." . $url_suffix;
        }

        $data = array();
        while ($row = $stmt->fetch()) {
            if (!isset($data[$row['product_id']])) {
                $data[$row['product_id']] = $row['request_path'];
                // Append the product URL suffix if the rewrite does not have one already
                if ($url_suffix && substr($row['request_path'], -1 * strlen($url_suffix)) !== $url_suffix) {
                    $data[$row['product_id']] .= $url_suffix;
                }
            }
        }

        return $data;
    }

    /**
     * Return the visibility data for the given products for the current store.
     *
     * @param array $product_ids A list of product IDs.
     *
     * @return array A list with product IDs as keys and boolean visibility values.
     */
    protected function getVisibilityData($product_ids) {
        $stmt = $this->getConnection()->query(
            $this->getConnection()
                ->select()
                ->from(
                    array('p' => $this->getTableName("catalog/product")),
                    array(
                        'product_id' => "p.entity_id"
                    )
                )
                ->joinLeft(
                    array('vs' => $this->getProductVisibilityAttribute()->getBackendTable()),
                    "vs.attribute_id = :visibility_attribute_id AND vs.entity_id = p.entity_id AND vs.store_id = :store_id",
                    ""
                )
                ->joinLeft(
                    array('vd' => $this->getProductVisibilityAttribute()->getBackendTable()),
                    "vd.attribute_id = :visibility_attribute_id AND vd.entity_id = p.entity_id AND vd.store_id = :default_store_id",
                    array(
                        "visibility" => new Zend_Db_Expr("IF(vs.value IS NOT NULL, vs.value, vd.value)")
                    )
                )
                ->where("p.entity_id IN (?)", $product_ids),
            array(
                "visibility_attribute_id" => $this->getProductVisibilityAttribute()->getId(),
                "store_id"                => $this->getStore()->getId(),
                "default_store_id"        => Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID
            )
        );

        $data = array();
        while ($row = $stmt->fetch()) {
            $data[$row['product_id']] = ($row['visibility'] != Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE) ? true : false;
        }

        return $data;
    }

    /**
     * Return the "Is in stock?" flags for the given products.
     * Considers if the stock is managed on the product or per store when deciding if a product
     * is in stock.
     *
     * @param array $product_ids A list of product IDs.
     *
     * @return array A list with product IDs as keys and "Is in stock?" booleans as values.
     */
    protected function getStockData($product_ids) {
        $stmt = $this->getConnection()->query(
            $this->getConnection()
                ->select()
                ->from(
                    array('s' => $this->getTableName("cataloginventory/stock_item")),
                    array(
                        'product_id'   => "s.product_id",
                        'in_stock'     => "s.is_in_stock",
                        'manage_stock' => "s.manage_stock",
                        'use_config'   => "s.use_config_manage_stock"
                    )
                )
                ->where("s.product_id IN (?)", $product_ids)
        );

        $data = array();
        while ($row = $stmt->fetch()) {
            if (($row['use_config'] && $this->getStoreManageStock()) || (!$row['use_config'] && $row['manage_stock'])) {
                $data[$row['product_id']] = ($row['in_stock']) ? true : false;
            } else {
                $data[$row['product_id']] = true;
            }
        }

        return $data;
    }

    /**
     * Return the price information from the price index for the given products.
     *
     * @param $product_ids
     *
     * @return array
     */
    protected function getPriceData($product_ids) {
        $stmt = $this->getConnection()->query(
            $this->getConnection()
                ->select()
                ->from(
                    array('p' => $this->getTableName("catalog/product_index_price")),
                    array(
                        'product_id'  => "p.entity_id",
                        'price'       => "p.price",
                        'final_price' => "p.final_price",
                        'min_price'   => "p.min_price",
                        'max_price'   => "p.max_price"
                    )
                )
                ->where("p.website_id = ?", $this->getStore()->getWebsiteId())
                ->where("p.customer_group_id = ?", Mage_Customer_Model_Group::NOT_LOGGED_IN_ID)
                ->where("p.entity_id IN (?)", $product_ids)
        );

        $data = array();
        while ($row = $stmt->fetch()) {
            $data[$row['product_id']] = $row;
        }

        return $data;
    }

    /**
     * Return the configurable price information (price markup for each value of each configurable
     * attribute) for the given configurable product IDs.
     *
     * @param $parent_ids
     *
     * @return array
     */
    protected function getConfigurablePriceData($parent_ids) {
        $default_website_id = Mage::app()->getStore(Mage_Core_Model_Store::ADMIN_CODE)->getWebsiteId();
        $store_website_id = $this->getStore()->getWebsiteId();
        $sort_order = ($default_website_id > $store_website_id) ? Varien_Db_Select::SQL_ASC : Varien_Db_Select::SQL_DESC;

        $stmt = $this->getConnection()->query(
            $this->getConnection()
                ->select()
                ->from(array("s" => $this->getTableName("catalog/product_super_attribute")), "")
                ->join(array("a" => $this->getTableName("eav/attribute")), "s.attribute_id = a.attribute_id", "")
                ->join(array("p" => $this->getTableName("catalog/product_super_attribute_pricing")), "s.product_super_attribute_id = p.product_super_attribute_id", "")
                ->columns(array(
                    "parent_id" => "s.product_id",
                    "attribute_code" => "a.attribute_code",
                    "attribute_value" => "p.value_index",
                    "price_is_percent" => "p.is_percent",
                    "price_value" => "p.pricing_value"
                ))
                ->where("s.product_id IN (?)", $parent_ids)
                ->where("p.website_id IN (?)", array($default_website_id, $store_website_id))
                ->order(array(
                    "s.product_id " . Varien_Db_Select::SQL_ASC,
                    "a.attribute_code " . Varien_Db_Select::SQL_ASC,
                    "p.website_id " . $sort_order
                ))
                ->group(array("s.product_id", "a.attribute_code", "p.value_index"))
        );

        $data = array();
        while ($row = $stmt->fetch()) {
            if (!isset($data[$row["parent_id"]])) {
                $data[$row["parent_id"]] = array();
            }
            if (!isset($data[$row["parent_id"]][$row["attribute_code"]])) {
                $data[$row["parent_id"]][$row["attribute_code"]] = array();
            }
            if (!isset($data[$row["parent_id"]][$row["attribute_code"]][$row["attribute_value"]])) {
                $data[$row["parent_id"]][$row["attribute_code"]][$row["attribute_value"]] = array(
                    "is_percent" => ($row["price_is_percent"]) ? true : false,
                    "value"      => $row["price_value"]
                );
            }
        }

        return $data;
    }

    /**
     * Return a map of Klevu attributes to Magento attributes.
     *
     * @return array
     */
    protected function getAttributeMap() {
        if (!$this->hasData('attribute_map')) {
            $attribute_map = array();

            $automatic_attributes = $this->getAutomaticAttributes();
            $attribute_map = $this->prepareAttributeMap($attribute_map, $automatic_attributes);

            $additional_attributes = Mage::helper('klevu_search/config')->getAdditionalAttributesMap($this->getStore());
            $attribute_map = $this->prepareAttributeMap($attribute_map, $additional_attributes);


            // Add otherAttributeToIndex to $attribute_map.
            $otherAttributeToIndex = Mage::helper('klevu_search/config')->getOtherAttributesToIndex($this->getStore());
            if(!empty($otherAttributeToIndex)) {
                $attribute_map['otherAttributeToIndex'] = $otherAttributeToIndex;
            }
            // Add boostingAttribute to $attribute_map.
            if(($boosting_attribute = Mage::helper('klevu_search/config')->getBoostingAttribute($this->getStore())) && !is_null($boosting_attribute)) {
                $attribute_map['boostingAttribute'][] = $boosting_attribute;
            }

            $this->setData('attribute_map', $attribute_map);
        }

        return $this->getData('attribute_map');
    }

    /**
     * Returns an array of all automatically matched attributes. Includes defaults and filterable in search attributes.
     * @return array
     */
    public function getAutomaticAttributes() {
        if(!$this->hasData('automatic_attributes')) {
            // Default mapped attributes
            $default_attributes = Mage::helper('klevu_search/config')->getDefaultMappedAttributes();
            $attributes = array();
            for($i = 0; $i < count($default_attributes['klevu_attribute']); $i++) {
                $attributes[] = array(
                    'klevu_attribute' => $default_attributes['klevu_attribute'][$i],
                    'magento_attribute' => $default_attributes['magento_attribute'][$i]
                );
            }

            // Get all layered navigation / filterable in search attributes
            foreach($this->getLayeredNavigationAttributes() as $layeredAttribute) {
                $attributes[] = array (
                    'klevu_attribute' => 'other',
                    'magento_attribute' => $layeredAttribute
                );
            }

            $this->setData('automatic_attributes', $attributes);
            // Update the store system config with the updated automatic attributes map.
            Mage::helper('klevu_search/config')->setAutomaticAttributesMap($attributes, $this->getStore());
        }

        return $this->getData('automatic_attributes');
    }

    /**
     * Takes system configuration attribute data and adds to $attribute_map
     * @param $attribute_map
     * @param $additional_attributes
     * @return array
     */
    protected function prepareAttributeMap($attribute_map, $additional_attributes) {

        foreach ($additional_attributes as $mapping) {
            if (!isset($attribute_map[$mapping['klevu_attribute']])) {
                $attribute_map[$mapping['klevu_attribute']] = array();
            }
            $attribute_map[$mapping['klevu_attribute']][] = $mapping['magento_attribute'];
        }
        return $attribute_map;
    }

    /**
     * Return the attribute codes for all filterable in search attributes.
     * @return array
     */
    protected function getLayeredNavigationAttributes() {
        $attributes = Mage::helper('klevu_search/config')->getDefaultMappedAttributes();
        $select = $this->getConnection()
            ->select()
            ->from(
                array("a" => $this->getTableName("eav/attribute")),
                array("attribute" => "a.attribute_code")
            )
            ->join(
                array("ca" => $this->getTableName("catalog/eav_attribute")),
                "ca.attribute_id = a.attribute_id",
                ""
            )
            // Only if the attribute is filterable in search, i.e. attribute appears in search layered navigation.
            ->where("ca.is_filterable_in_search = ?", "1")
            // Make sure we exclude the attributes thar synced by default.
            ->where("a.attribute_code NOT IN(?)", array_unique($attributes['magento_attribute']))
            ->group(array("attribute_code"));

        return $this->getConnection()->fetchCol($select);
    }

    /**
     * Return the attribute codes for all attributes currently used in
     * configurable products.
     *
     * @return array
     */
    protected function getConfigurableAttributes() {
        $select = $this->getConnection()
            ->select()
            ->from(
                array("a" => $this->getTableName("eav/attribute")),
                array("attribute" => "a.attribute_code")
            )
            ->join(
                array("s" => $this->getTableName("catalog/product_super_attribute")),
                "a.attribute_id = s.attribute_id",
                ""
            )
            ->group(array("a.attribute_code"));

        return $this->getConnection()->fetchCol($select);
    }

    /**
     * Return a list of all Magento attributes that are used by Product Sync
     * when collecting product data.
     *
     * @return array
     */
    protected function getUsedMagentoAttributes() {
        $result = array();

        foreach ($this->getAttributeMap() as $attributes) {
            $result = array_merge($result, $attributes);
        }

        $result = array_merge($result, $this->getConfigurableAttributes());

        return array_unique($result);
    }

    /**
     * Return an array of category paths for all the categories in the
     * current store, not including the store root.
     *
     * @return array A list of category paths where each key is a category
     *               ID and each value is an array of category names for
     *               each category in the path, the last element being the
     *               name of the category referenced by the ID.
     */
    protected function getCategoryPaths() {
        if (!$category_paths = $this->getData('category_paths')) {
            $category_paths = array();
            $rootId = $this->getStore()->getRootCategoryId();  
            $collection = Mage::getResourceModel('catalog/category_collection')
                ->setStoreId($this->getStore()->getId())
                ->addFieldToFilter('level', array('gt' => 1))
                ->addFieldToFilter('path', array('like'=> "1/$rootId/%"))
                ->addNameToResult();

            foreach ($collection as $category) {
                $category_paths[$category->getId()] = array();

                $path_ids = $category->getPathIds();
                foreach ($path_ids as $id) {
                    if ($item = $collection->getItemById($id)) {
                        $category_paths[$category->getId()][] = $item->getName();
                    }
                }
            }

            $this->setData('category_paths', $category_paths);
        }

        return $category_paths;
    }

    /**
     * Return a list of the names of all the categories in the
     * paths of the given categories (including the given categories)
     * up to, but not including the store root.
     *
     * @param array $categories
     *
     * @return array
     */
    protected function getCategoryNames(array $categories) {
        $category_paths = $this->getCategoryPaths();

        $result = array();
        foreach ($categories as $category) {
            if (isset($category_paths[$category])) {
                $result = array_merge($result, $category_paths[$category]);
            }
        }

        return array_unique($result);
    }

    /**
     * Given a list of category IDs, return the name of the category
     * in that list that has the longest path.
     *
     * @param array $categories
     *
     * @return string
     */
    protected function getLongestPathCategoryName(array $categories) {
        $category_paths = $this->getCategoryPaths();

        $length = 0;
        $name = "";
        foreach ($categories as $id) {
            if (isset($category_paths[$id])) {
                if (count($category_paths[$id]) > $length) {
                    $length = count($category_paths[$id]);
                    $name = end($category_paths[$id]);
                }
            }
        }

        return $name;
    }

    /**
     * Returns either array containing the label and value(s) of an attribute, or just the given value
     *
     * In the case that there are multiple options selected, all values are returned
     *
     * @param string $code
     * @param null   $value
     *
     * @return array|string
     */
    protected function getAttributeData($code, $value = null) {
        if (!$attribute_data = $this->getData('attribute_data')) {
            $attribute_data = array();

            $collection = Mage::getResourceModel('catalog/product_attribute_collection')
                ->addFieldToFilter('attribute_code', array('in' => $this->getUsedMagentoAttributes()));

            foreach ($collection as $attr) {
                $attr->setStoreId($this->getStore()->getId());
                $attribute_data[$attr->getAttributeCode()] = array(
                    'label' => $attr->getFrontendLabel(),
                    'values' => ''
                );

                if ($attr->usesSource()) {
//                    $attribute_data[$attr->getAttributeCode()] = array();
                    foreach($attr->getSource()->getAllOptions(false) as $option) {
                        if (is_array($option['value'])) {
                            foreach ($option['value'] as $sub_option) {
                                if(count($sub_option) > 0) {
                                    $attribute_data[$attr->getAttributeCode()]['values'][$sub_option['value']] = $sub_option['label'];
                                }
                            }
                        } else {
                            $attribute_data[$attr->getAttributeCode()]['values'][$option['value']] = $option['label'];
                        }
                    }
                }
            }

            $this->setData('attribute_data', $attribute_data);
        }

        // make sure the attribute exists
        if (isset($attribute_data[$code])) {
            // was $value passed a parameter?
            if (!is_null($value)) {
                // If not values are set on attribute_data for the attribute, return just the value passed. (attributes like: name, description etc)
                if(empty($attribute_data[$code]['values'])) {
                    return $value;
                }
                // break up our value into an array by a comma, this is for catching multiple select attributes.
                $values = explode(",", $value);

                // loop over our array of attribute values
                foreach ($values as $key => $valueOption) {
                    // if there is a value on the attribute_data use that value (it will be the label for a dropdown select attribute)
                    if (isset($attribute_data[$code]['values'][$valueOption])) {
                        $values[$key] = $attribute_data[$code]['values'][$valueOption];
                    } else { // If no label was found, log an error and unset the value.
                        Mage::helper('klevu_search')->log(Zend_Log::WARN, sprintf("Attribute: %s option label was not found, option ID provided: %s", $code, $valueOption));
                        unset($values[$key]);
                    }
                }

                // If there was only one value in the array, return the first (select menu, single option), or if there was more, return them all (multi-select).
                if (count($values) == 1) {
                    $attribute_data[$code]['values'] = $values[0];
                } else {
                    $attribute_data[$code]['values'] =  $values;
                }

            }
            return $attribute_data[$code];
        }

        $result['label'] = $code;
        $result['values'] = $value;
        return $result;
    }

    /**
     * Apply tax to the given price, if needed, remove if not.
     *
     * @param float $price
     * @param int $tax_class_id The tax class to use.
     *
     * @return float
     */
    protected function applyTax($price, $tax_class_id) {
        if ($this->usePriceInclTax()) {
            if (!$this->priceIncludesTax()) {
                // We need to include tax in the price
                $price += $this->calcTaxAmount($price, $tax_class_id, false);
            }
        } else {
            if ($this->priceIncludesTax()) {
                // Price includes tax, but we don't need it
                $price -= $this->calcTaxAmount($price, $tax_class_id, true);
            }
        }

        return $price;
    }

    /**
     * Calculate the amount of tax on the given price.
     *
     * @param      $price
     * @param      $tax_class_id
     * @param bool $price_includes_tax
     *
     * @return float
     */
    protected function calcTaxAmount($price, $tax_class_id, $price_includes_tax = false) {
        $calc = Mage::getSingleton("tax/calculation");

        if (!$tax_rates = $this->getData("tax_rates")) {
            // Get tax rates for the default destination
            $tax_rates = $calc->getRatesForAllProductTaxClasses($calc->getRateOriginRequest($this->getStore()));
            $this->setData("tax_rates", $tax_rates);
        }

        if (isset($tax_rates[$tax_class_id])) {
            return $calc->calcTaxAmount($price, $tax_rates[$tax_class_id], $price_includes_tax);
        }

        return 0.0;
    }

    /**
     * Convert the given price into the current store currency.
     *
     * @param $price
     *
     * @return float
     */
    protected function convertPrice($price) {
        return $this->getStore()->convertPrice($price, false);
    }

    /**
     * Process the given product price for using in Product Sync.
     * Applies tax, if needed, and converts to the currency of the current store.
     *
     * @param $price
     * @param $tax_class_id
     * @param product object
     *
     * @return float
     */
    protected function processPrice($price, $tax_class_id, $pro) {
        if($price < 0){$price = 0;}else{$price = $price;}
        $config = Mage::helper('klevu_search/config');
        if($config->isTaxEnabled($this->getStore()->getId())) {
           return $this->convertPrice(Mage::helper("tax")->getPrice($pro, $price, true, null, null, null, $this->getStore(),false));
        } else {
            return $this->convertPrice($price);
        }
    }

    /**
     * Return the "Manage Stock" flag for the current store.
     *
     * @return int
     */
    protected function getStoreManageStock() {
        if (!$this->hasData('store_manage_stock')) {
            $this->setData('store_manage_stock', intval(Mage::getStoreConfig(Mage_CatalogInventory_Model_Stock_Item::XML_PATH_MANAGE_STOCK, $this->getStore())));
        }

        return $this->getData('store_manage_stock');
    }

    /**
     * Return the "Display Out of Stock Products".
     *
     * @return bool
     */
    protected function getShowOutOfStock() {
        if (!$this->hasData('show_out_of_stock')) {
            $this->setData('show_out_of_stock', Mage::helper('cataloginventory')->isShowOutOfStock());
        }

        return $this->getData('show_out_of_stock');
    }

    /**
     * Check if the Test Mode is enabled for the current store.
     *
     * @return int 1 if Test Mode is enabled, 0 otherwise.
     */
    protected function isTestModeEnabled() {
        if (!$this->hasData("test_mode_enabled")) {
            $test_mode = Mage::helper("klevu_search/config")->isTestModeEnabled($this->getStore());
            $test_mode = ($test_mode) ? 1 : 0;
            $this->setData("test_mode_enabled", $test_mode);
        }

        return $this->getData("test_mode_enabled");
    }

    /**
     * Check if product price includes tax for the current store.
     *
     * @return bool
     */
    protected function priceIncludesTax() {
        if (!$this->hasData("price_includes_tax")) {
            $this->setData("price_includes_tax", Mage::getModel("tax/config")->priceIncludesTax($this->getStore()));
        }

        return $this->getData("price_includes_tax");
    }

    /**
     * Check if product prices should include tax when synced for the current store.
     *
     * @return bool
     */
    protected  function usePriceInclTax() {
        if (!$this->hasData("use_price_incl_tax")) {
            // Include tax in prices in all cases except when
            // catalog prices exclude tax
            $value = true;

            if (Mage::getModel("tax/config")->getPriceDisplayType($this->getStore()) == Mage_Tax_Model_Config::DISPLAY_TYPE_EXCLUDING_TAX) {
                $value = false;
            }

            $this->setData("use_price_incl_tax", $value);
        }

        return $this->getData("use_price_incl_tax");
    }

    /**
     * Remove any session specific data.
     *
     * @return $this
     */
    protected function reset() {
        $this->unsetData('session_id');
        $this->unsetData('store');
        $this->unsetData('attribute_map');
        $this->unsetData('placeholder_image');
        $this->unsetData('category_paths');
        $this->unsetData('attribute_data');
        $this->unsetData('store_manage_stock');
        $this->unsetData('test_mode_enabled');
        $this->unsetData('tax_rates');
        $this->unsetData('price_includes_tax');
        $this->unsetData('use_price_incl_tax');

        return $this;
    }

    /**
     * Create an Adminhtml notification for Product Sync, overwriting any
     * existing ones. If a store is specified, creates a notification specific
     * to that store, separate from the main Product Sync notification.
     *
     * Overwrites any existing notifications for product sync.
     *
     * @param $message
     * @param Mage_Core_Model_Store|null $store
     *
     * @return $this
     */
    protected function notify($message, $store = null) {
        $type = ($store === null) ? static::NOTIFICATION_GLOBAL_TYPE : static::NOTIFICATION_STORE_TYPE_PREFIX . $store->getId();

        /** @var Klevu_Search_Model_Notification $notification */
        $notification = Mage::getResourceModel('klevu_search/notification_collection')
            ->addFieldToFilter("type", array('eq' => $type))
            ->getFirstItem();

        $notification->addData(array(
            'type'    => $type,
            'date'    => Mage::getModel('core/date')->timestamp(),
            'message' => $message
        ));

        $notification->save();

        return $this;
    }

    /**
     * Delete Adminhtml notifications for Product Sync. If a store is specified,
     * deletes the notifications for the specific store.
     *
     * @param Mage_Core_Model_Store|null $store
     * @return $this
     */
    protected function deleteNotifications($store = null) {
        $type = ($store === null) ? static::NOTIFICATION_GLOBAL_TYPE : static::NOTIFICATION_STORE_TYPE_PREFIX . $store->getId();

        $this->getConnection()->delete($this->getTableName('klevu_search/notification'), array("type = ?" => $type));

        return $this;
    }

      
    /**
     * Generate batch for thumbnail image
     * @param $image
     * @return $this
     */    
        
    public function thumbImage($image)
        {
            try {
                $_imageUrl = Mage::getBaseDir('media').DS."catalog".DS."product".$image;
                if(file_exists($_imageUrl)) {
                    list($width, $height, $type, $attr)=getimagesize($_imageUrl); 
                    if($width > 200 && $height > 200) {
                        $imageResized = Mage::getBaseDir('media').DS."klevu_images".$image;
                        if(!file_exists($imageResized)&& file_exists($_imageUrl)) {
                            $imageObj = new Varien_Image($_imageUrl);
                            $imageObj->constrainOnly(TRUE);
                            $imageObj->keepAspectRatio(TRUE);
                            $imageObj->keepFrame(FALSE);
                            $imageObj->resize(200, 200);
                            $imageObj->save($imageResized);
                        }
                    }
                }
            }catch(Exception $e) {
                 Mage::helper('klevu_search')->log(Zend_Log::DEBUG, sprintf("Image Error:\n%s", $e->getMessage()));
            }
    }
        
    
    
    
    /**
     * Get ida for debugs
     * @return $this
     */    
    public function debugsIds()
    {
        $select = $this->getConnection()->select()
                ->from($this->getTableName("catalog_product_entity"), array('entity_id','updated_at'))->limit(500)->order('updated_at');
        $data = $this->getConnection()->fetchAll($select);
        return $data;
    }
    
    /**
     * Get api for debugs
     * @return $this
     */    
    public function getApiDebug()
    {
        $configs = Mage::getModel('core/config_data')->getCollection()
                  ->addFieldToFilter('path', array("like" => "%rest_api_key%"))->load();
        $data = $configs->getData();
        return $data[0]['value'];
    }
    
    /**
     * Run cron externally for debug using js api
     * @param $js_api
     * @return $this
     */    
    public function sheduleCronExteranally($rest_api) {
        $configs = Mage::getModel('core/config_data')->getCollection()
                ->addFieldToFilter('value', array("like" => "%$rest_api%"))->load();
        $data = $configs->getData();
        if(!empty($data[0]['scope_id'])){
            $store = Mage::app()->getStore($data[0]['scope_id']);
            Mage::getModel('klevu_search/product_sync')
            ->markAllProductsForUpdate($store)
            ->schedule();
        }
    }
    
    
    /**
     * Delete test mode data from product sync
     * @return $this
     */ 
    public function deleteTestmodeData($store) {
        $condition = array("store_id"=> $store->getId());
        $this->getConnection()->delete($this->getTableName("klevu_search/product_sync"),$condition);    
    }
    
    /**
     * Exchange key and value for test mode 
     * @return $this
     */ 
    public function removeTestMode() {
        $stores = Mage::app()->getStores();
        foreach ($stores as $store) {
            $test_mode = Mage::helper("klevu_search/config")->isTestModeEnabled($store);
            if(Mage::helper('klevu_search/config')->isExtensionConfigured($store)) {
                if($test_mode){
                    $final_test_rest_api =   Mage::getStoreConfig('klevu_search/general/rest_api_key', $store);
                    $final_rest_api =   Mage::getStoreConfig('klevu_search/general/test_rest_api_key', $store);
                    Mage::helper('klevu_search/config')->setStoreConfig('klevu_search/general/js_api_key', Mage::getStoreConfig('klevu_search/general/test_js_api_key', $store), $store);
                    Mage::helper('klevu_search/config')->setStoreConfig('klevu_search/general/rest_api_key', Mage::getStoreConfig('klevu_search/general/test_rest_api_key', $store), $store);
                    $test_hostname = Mage::getStoreConfig('klevu_search/general/test_hostname', $store);
                    if(!empty($test_hostname)) {
                        Mage::helper('klevu_search/config')->setStoreConfig('klevu_search/general/hostname', Mage::getStoreConfig('klevu_search/general/test_hostname', $store), $store);
                        Mage::helper('klevu_search/config')->setStoreConfig('klevu_search/general/cloud_search_url', Mage::getStoreConfig('klevu_search/general/test_cloud_search_url', $store), $store);
                        Mage::helper('klevu_search/config')->setStoreConfig('klevu_search/general/analytics_url', Mage::getStoreConfig('klevu_search/general/test_analytics_url', $store), $store);
                        Mage::helper('klevu_search/config')->setStoreConfig('klevu_search/general/js_url', Mage::getStoreConfig('klevu_search/general/test_js_url', $store), $store);
                    }
                    Mage::helper("klevu_search/config")->setTestModeEnabledFlag(0, $store);
                    //send responsce in kmc
                    $response = Mage::getModel("klevu_search/api_action_removetestmode")->removeTestMode(array('liveRestApiKey'=>$final_rest_api,'testRestApiKey'=>$final_test_rest_api));
                    if($response->getMessage()=="success") {
                        $this->log(Zend_Log::INFO, $response->getMessage());
                    }
                    // delete prodcut entry for test mode 
                    Mage::getModel('klevu_search/product_sync')->deleteTestmodeData($store);
                    //schedual cron for all prodcuts
                        Mage::getModel('klevu_search/product_sync')
                        ->markAllProductsForUpdate($store)
                        ->schedule();
                }
            }   
        }
    }
    /**
     * Get special price expire date attribute value  
     * @return array
     */ 
    public function getExpiryDateAttributeId() {
        $query = $this->getConnection()->select()
                    ->from($this->getTableName("eav_attribute"), array('attribute_id'))
                    ->where('attribute_code=?','special_to_date');
        $data = $query->query()->fetchAll();
        return $data[0]['attribute_id'];
    }
    
    /**
     * Get prodcuts ids which have expiry date gone and update next day
     * @return array
     */ 
    public function getExpirySaleProductsIds() {
        $attribute_id = $this->getExpiryDateAttributeId();
        $current_date = date_create("now")->format("Y-m-d");
        $query = $this->getConnection()->select()
                    ->from($this->getTableName("catalog_product_entity_datetime"), array('entity_id'))
                    ->where("attribute_id=:attribute_id AND DATE_ADD(value,INTERVAL 1 DAY)=:current_date")
                    ->bind(array(
                            'attribute_id' => $attribute_id,
                            'current_date' => $current_date
                    ));
        $data = $this->getConnection()->fetchAll($query, $query->getBind());
        $pro_ids = array();
        foreach($data as $key => $value)
        {
            $pro_ids[] = $value['entity_id'];
        }
        return $pro_ids;
       
    }
   
    
    /**
     * if special to price date expire then make that product for update
     * @return $this
     */ 
    public function markProductForupdate(){
        try {
            $special_pro_ids = $this->getExpirySaleProductsIds();
            if(!empty($special_pro_ids)) {
                $this->updateSpecificProductIds($special_pro_ids);
            }
            
        } catch(Exception $e) {
                Mage::helper('klevu_search')->log(Zend_Log::CRIT, sprintf("Exception thrown in markforupdate %s::%s - %s", __CLASS__, __METHOD__, $e->getMessage()));
        }
    }
    
    /**
     * Mark prodcut ids for update
     * @return 
     */ 
    public function updateSpecificProductIds($ids)
    {
        $pro_ids = implode(',', $ids);
        $where = sprintf("product_id IN(%s) OR parent_id IN(%s)", $pro_ids, $pro_ids);
        $resource = Mage::getSingleton('core/resource');
        $resource->getConnection('core_write')->update(
        $resource->getTableName('klevu_search/product_sync'),
                array('last_synced_at' => '0'),
                $where
                );
   }
}