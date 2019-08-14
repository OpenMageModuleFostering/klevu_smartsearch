<?php

class Klevu_Search_Test_Model_Observer extends EcomDev_PHPUnit_Test_Case {

    public function setUp() {
        parent::setUp();

        $collection = $this->getProductSyncCronScheduleCollection();
        foreach ($collection as $item) {
            $item->delete();
        }

        $collection = $this->getOrderSyncCronScheduleCollection();
        foreach ($collection as $item) {
            $item->delete();
        }
    }

    public function tearDown() {
        $collection = $this->getProductSyncCronScheduleCollection();
        foreach ($collection as $item) {
            $item->delete();
        }

        $resource = Mage::getModel("core/resource");
        $resource->getConnection("core_write")->delete($resource->getTableName("klevu_search/order_sync"));

        parent::tearDown();
    }

    public function testScheduleProductSync() {
        $observer = Mage::getModel("klevu_search/observer");

        $observer->scheduleProductSync(new Varien_Event_Observer());

        $this->assertEquals(1, $this->getProductSyncCronScheduleCollection()->getSize(),
        "Failed to assert that scheduleProductSync() schedules the Product Sync cron when called.");
    }

    /**
     * @test
     * @loadFixture
     */
    public function testScheduleOrderSync() {
        $model = Mage::getModel("klevu_search/observer");

        $order = Mage::getModel("sales/order")->load(1);
        $event = new Varien_Event();
        $event->addData(array(
            "event_name" => "sales_order_place_after",
            "order" => $order
        ));
        $observer = new Varien_Event_Observer();
        $observer->addData(array("event" => $event));

        $model->scheduleOrderSync($observer);

        $this->assertEquals(array(array("order_item_id" => "2")), $this->getOrderSyncQueue());

        $this->assertEquals(1, $this->getOrderSyncCronScheduleCollection()->getSize(),
            "Failed to assert that scheduleOrderSync() schedules the Order Sync cron when called."
        );
    }

    /**
     * Return a cron/schedule collection filtered for Product Sync jobs only.
     *
     * @return Mage_Cron_Model_Mysql4_Schedule_Collection
     */
    protected function getProductSyncCronScheduleCollection() {
        return Mage::getResourceModel("cron/schedule_collection")
                ->addFieldToFilter("job_code", Mage::getModel("klevu_search/product_sync")->getJobCode());
    }

    /**
     * Return a cron/schedule collection filtered for Order Sync jobs only.
     *
     * @return Mage_Cron_Model_Mysql4_Schedule_Collection
     */
    protected function getOrderSyncCronScheduleCollection() {
        return Mage::getResourceModel("cron/schedule_collection")
            ->addFieldToFilter("job_code", Mage::getModel("klevu_search/order_sync")->getJobCode());
    }

    /**
     * Return all items in the Order Sync queue.
     *
     * @return array
     */
    protected function getOrderSyncQueue() {
        $resource = Mage::getModel("core/resource");
        $connection = $resource->getConnection("core_write");
        return $connection->fetchAll($connection
            ->select()
            ->from($resource->getTableName("klevu_search/order_sync"))
        );
    }
}
