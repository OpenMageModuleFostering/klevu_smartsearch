<?php
require_once('../abstract.php');
class Klevu_Shell_Sync extends Mage_Shell_Abstract
{
    protected $_argname = array();
 
    public function __construct() {
        parent::__construct();
 
        // Time limit to infinity
        set_time_limit(0);     
    }
 
    // Shell script point of entry
    public function run() {
		if(file_exists("klevu_running_index.lock")){
			echo "Klevu indexing process is in running state";
			return;
		} 
		
		fopen("klevu_running_index.lock", "w");
		
        try {
            if ($this->getArg('updatesonly')) {
                Mage::getModel('klevu_search/product_sync')->run();
                Mage::getModel("content/content")->run();
                echo "Data updates have been sent to Klevu";
            } else if($this->getArg('alldata')) {
                // Modified the updated date klevu_product_sync table
                Mage::getModel('klevu_search/product_sync')->markAllProductsForUpdate();
                // Run the product sync for all store
                Mage::getModel('klevu_search/product_sync')->run();
                Mage::getModel("content/content")->run();
                echo "All Data have been sent to Klevu";

            } else {
                echo $this->usageHelp();
            }
        } catch(Exception $e){
            echo $e->getMessage();
        }
		
		if(file_exists("klevu_running_index.lock")){
			unlink("klevu_running_index.lock");
		}
		
	
    }
 
    // Usage instructions
    public function usageHelp()
    {
        return <<<USAGE
        
Usage:  php -f sync.php -- [options]

  Note:If you choose to run this script at regular intervals to sync data, please make sure to select the value "Never" for the System -> Configuration -> Klevu -> Search Configuration -> Data Sync Settings -> Frequency option.
  
  --updatesonly If you are using this option, only the products updated since the last successful synchronization will be synchronized with the Klevu servers. Klevu uses the updated_at timestamp of the catalog_product_entity table to figure out which products to synchronize.
  
  --alldata     If you are using this option, the entire product catalog is considered for synchronization.
  
  
  
USAGE;
    }
}
// Instantiate
$shell = new Klevu_Shell_Sync();
 
// Initiate script
$shell->run();
?>