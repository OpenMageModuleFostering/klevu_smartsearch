<?php

class Klevu_Search_Test_Helper_Config extends EcomDev_PHPUnit_Test_Case {

    /** @var Klevu_Search_Helper_Config $helper */
    protected $helper;

    protected function setUp() {
        parent::setUp();

        $this->helper = Mage::helper("klevu_search/config");
    }

    protected function tearDown() {
        $this->getConfig()->deleteConfig("klevu_search/general/enabled");
        $this->getConfig()->deleteConfig("klevu_search/general/test_mode");
        $this->getConfig()->deleteConfig("klevu_search/general/js_api_key");
        $this->getConfig()->deleteConfig("klevu_search/general/rest_api_key");
        $this->getConfig()->deleteConfig("klevu_search/general/test_js_api_key");
        $this->getConfig()->deleteConfig("klevu_search/general/test_rest_api_key");
        $this->getConfig()->deleteConfig("klevu_search/product_sync/enabled");
        $this->getConfig()->deleteConfig("klevu_search/product_sync/frequency");
        $this->getConfig()->deleteConfig("klevu_search/attributes/additional");
        $this->getConfig()->deleteConfig("klevu_search/order_sync/enabled");
        $this->getConfig()->deleteConfig("klevu_search/order_sync/frequency");
        $this->getConfig()->deleteConfig("klevu_search/developer/force_log");
        $this->getConfig()->deleteConfig("klevu_search/developer/log_level");

        parent::tearDown();
    }

    /**
     * @test
     * @loadFixture
     */
    public function testIsExtensionEnabledEnabled() {
        $this->assertEquals(true, $this->helper->isExtensionEnabled());
    }

    /**
     * @test
     * @loadFixture
     */
    public function testIsExtensionEnabledDisabled() {
        $this->assertEquals(false, $this->helper->isExtensionEnabled());
    }

    /**
     * @test
     */
    public function testGetTestModeEnabledFlag() {
        $this->assertEquals(false, $this->helper->getTestModeEnabledFlag(),
            "Failed asserting that Test Mode flag is disabled by default."
        );

        $this->getConfig()
            ->saveConfig("klevu_search/general/test_mode", 1)
            ->cleanCache();

        $this->clearConfigCache();

        $this->assertEquals(true, $this->helper->getTestModeEnabledFlag(),
            "Failed asserting that Test Mode flag returns true when Test Mode is enabled in the config."
        );
    }

    /**
     * @test
     * @dataProvider dataProvider
     */
    public function testIsTestModeEnabled($test_mode, $is_production_domain, $result) {
        $this->getConfig()
            ->saveConfig("klevu_search/general/test_mode", $test_mode)
            ->cleanCache();

        $this->clearConfigCache();

        $this->mockIsProductionDomain($is_production_domain);

        $this->assertEquals($result, $this->helper->isTestModeEnabled());
    }

    /**
     * @test
     */
    public function testGetJsApiKeyProduction() {
        $api_key = "test-js-api-key";

        $this->mockIsProductionDomain(true);

        $this->assertEquals(null, $this->helper->getJsApiKey());

        $this->getConfig()
            ->saveConfig("klevu_search/general/js_api_key", $api_key)
            ->cleanCache();

        $this->clearConfigCache();

        $this->assertEquals($api_key, $this->helper->getJsApiKey());
    }

    /**
     * @test
     */
    public function testGetJsApiKeyStaging() {
        $api_key = "test-js-api-key";

        $this->mockIsProductionDomain(false);

        $this->assertEquals(null, $this->helper->getJsApiKey());

        $this->getConfig()
            ->saveConfig("klevu_search/general/test_js_api_key", $api_key)
            ->cleanCache();

        $this->clearConfigCache();

        $this->assertEquals($api_key, $this->helper->getJsApiKey());
    }

    /**
     * @test
     */
    public function testGetRestApiKeyProduction() {
        $api_key = "test-rest-api-key";

        $this->mockIsProductionDomain(true);

        $this->assertEquals(null, $this->helper->getRestApiKey());

        $this->getConfig()
            ->saveConfig("klevu_search/general/rest_api_key", $api_key)
            ->cleanCache();

        $this->clearConfigCache();

        $this->assertEquals($api_key, $this->helper->getRestApiKey());
    }

    /**
     * @test
     */
    public function testGetRestApiKeyStaging() {
        $api_key = "test-rest-api-key";

        $this->mockIsProductionDomain(false);

        $this->assertEquals(null, $this->helper->getRestApiKey());

        $this->getConfig()
            ->saveConfig("klevu_search/general/test_rest_api_key", $api_key)
            ->cleanCache();

        $this->clearConfigCache();

        $this->assertEquals($api_key, $this->helper->getRestApiKey());
    }

    /**
     * @test
     */
    public function testGetProductSyncEnabledFlagDefault() {
        $this->assertEquals(2, $this->helper->getProductSyncEnabledFlag());
    }

    /**
     * @test
     * @loadFixture
     */
    public function testGetProductSyncEnabledFlag() {
        $this->assertEquals(2, $this->helper->getProductSyncEnabledFlag());
    }

    /**
     * @test
     * @dataProvider dataProvider
     */
    public function testIsProductSyncEnabled($is_production_domain, $config_flag, $result) {
        $data_helper = $this->getHelperMock('klevu_search', array("isProductionDomain"));
        $data_helper
            ->expects($this->once())
            ->method("isProductionDomain")
            ->will($this->returnValue($is_production_domain));
        $this->replaceByMock("helper", "klevu_search", $data_helper);

        $this->clearConfigCache();

        $this->getConfig()
            ->saveConfig("klevu_search/product_sync/enabled", $config_flag)
            ->cleanCache();

        $this->assertEquals($result, $this->helper->isProductSyncEnabled());
    }

    /**
     * @test
     */
    public function testGetProductSyncFrequencyDefault() {
        $this->assertEquals("0 * * * *", $this->helper->getProductSyncFrequency());
    }

    /**
     * @test
     * @loadFixture
     */
    public function testGetProductSyncFrequency() {
        $this->assertEquals("0 */5 * * *", $this->helper->getProductSyncFrequency());
    }

    /**
     * @test
     */
    public function testGetAdditionalAttributesMap() {
        $map = array(
            "_1" => array("klevu_attribute" => "k_test", "magento_attribute" => "m_test"),
            "_2" => array("klevu_attribute" => "k_other", "magento_attribute" => "m_something")
        );

        // Test the default value
        $this->assertEquals(array(), $this->helper->getAdditionalAttributesMap(), "getAdditionalAttributesMap() did not default to an empty array.");

        $this->getConfig()
            ->saveConfig("klevu_search/attributes/additional", serialize($map))
            ->reinit();

        $this->assertEquals($map, $this->helper->getAdditionalAttributesMap(), "getAdditionalAttributesMap() failed to return the map set.");
    }

    /**
     * @test
     */
    public function testGetOrderSyncEnabledFlagDefault() {
        $this->assertEquals(1, $this->helper->getOrderSyncEnabledFlag());
    }

    /**
     * @test
     * @loadFixture
     */
    public function testGetOrderSyncEnabledFlag() {
        $this->assertEquals(2, $this->helper->getOrderSyncEnabledFlag());
    }

    /**
     * @test
     * @dataProvider dataProvider
     */
    public function testIsOrderSyncEnabled($is_production_domain, $config_flag, $result) {
        $data_helper = $this->getHelperMock('klevu_search', array("isProductionDomain"));
        $data_helper
            ->expects($this->once())
            ->method("isProductionDomain")
            ->will($this->returnValue($is_production_domain));
        $this->replaceByMock("helper", "klevu_search", $data_helper);

        $this->clearConfigCache();

        $this->getConfig()
            ->saveConfig("klevu_search/order_sync/enabled", $config_flag)
            ->cleanCache();

        $this->assertEquals($result, $this->helper->isOrderSyncEnabled());
    }

    /**
     * @test
     */
    public function testGetOrderSyncFrequencyDefault() {
        $this->assertEquals("0 * * * *", $this->helper->getOrderSyncFrequency());
    }

    /**
     * @test
     * @loadFixture
     */
    public function testGetOrderSyncFrequency() {
        $this->assertEquals("0 */3 * * *", $this->helper->getOrderSyncFrequency());
    }

    /**
     * @test
     */
    public function testIsLoggingForced() {
        // Test the default value
        $this->assertEquals(false, $this->helper->isLoggingForced());

        $this->clearConfigCache();

        // Test a set value
        $this->getConfig()
            ->saveConfig('klevu_search/developer/force_log', true)
            ->cleanCache();

        $this->assertEquals(true, $this->helper->isLoggingForced());
    }

    public function testGetLogLevel() {
        // Test the default value
        $this->assertEquals(Zend_Log::WARN, $this->helper->getLogLevel(), "getLogLevel() returned an incorrect default value.");

        $this->clearConfigCache();

        // Test a set value
        $this->getConfig()
            ->saveConfig('klevu_search/developer/log_level', Zend_Log::INFO)
            ->cleanCache();

        $this->assertEquals(Zend_Log::INFO, $this->helper->getLogLevel(), "getLogLevel() failed to return the value set.");
    }

    /**
     * Mock the helper method that checks whether the current domain is a production domain.
     *
     * @param bool $result The result to be returned by the method
     */
    protected function mockIsProductionDomain($result = false) {
        $data_helper = $this->getHelperMock('klevu_search', array("isProductionDomain"));
        $data_helper
            ->expects($this->any())
            ->method("isProductionDomain")
            ->will($this->returnValue($result));
        $this->replaceByMock("helper", "klevu_search", $data_helper);
    }

    protected function getConfig() {
        return Mage::app()->getConfig();
    }

    /**
     * Get around Magento's aggressive caching strategy and actually clear the configuration cache.
     */
    protected function clearConfigCache() {
        // Flush website and store configuration caches
        foreach (Mage::app()->getWebsites(true) as $website) {
            EcomDev_Utils_Reflection::setRestrictedPropertyValue(
                $website, '_configCache', array()
            );
        }
        foreach (Mage::app()->getStores(true) as $store) {
            EcomDev_Utils_Reflection::setRestrictedPropertyValue(
                $store, '_configCache', array()
            );
        }
    }
}
