<?php
/**
 * Klevu FrontEnd Controller
 */
class Klevu_Search_SearchController extends Mage_Core_Controller_Front_Action {
    /**
     * Genarate thumbnail using multiple curl request action
     */
    public function indexAction() {     
        try {
            $id = Mage::app()->getRequest()->getParam('id');
            $data = Mage::getModel('klevu_search/product_sync')->getImageProcessingIds($id); 
            foreach(unserialize($data[0]['batchdata']) as $key => $value) {
                $product = Mage::getModel('catalog/product')->load($value);
                $image = $product->getImage();
                if((!empty($image)) && ($image!= "no_selection")) {
                    Mage::getModel('klevu_search/product_sync')->thumbImage($product->getImage());
                }
            }
            Mage::getModel('klevu_search/product_sync')->updateImageProcessingIds($id);
        }
        catch(Exception $e) {
            Mage::helper('klevu_search')->log(Zend_Log::DEBUG, sprintf("Image Error:\n%s", $e->getMessage()));
        }
        
    }

    public function runexternalylogAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }
    /**
     * Send different logs to klevu server to debug the data
     */
    public function runexternalyAction(){
        try {
                $debugapi = Mage::getModel('klevu_search/product_sync')->getApiDebug();
                $content="";
                if($this->getRequest()->getParam('debug') == "klevu") {
                    // get last 500 lines from klevu log 
                    $path = Mage::getBaseDir("log")."/Klevu_Search.log";
                    if($this->getRequest()->getParam('lines')) {
                        $line = $this->getRequest()->getParam('lines'); 
                    }else {
                        $line = 100;
                    }
                    $content.= $this->getLastlines($path,$line,true);
                   
                    //send php and magento version
                    $content.= "</br>".'****Current Magento version on store:'.Mage::getVersion()."</br>";
                    $content.= "</br>".'****Current PHP version on store:'. phpversion()."</br>";
                    
                    //send cron and  logfile data
                    $cron = Mage::getBaseDir()."/cron.php";
                    $cronfile = file_get_contents($cron);
                    $content.= nl2br(htmlspecialchars($content)).nl2br(htmlspecialchars($cronfile));
                    $response = Mage::getModel("klevu_search/api_action_debuginfo")->debugKlevu(array('apiKey'=>$debugapi,'klevuLog'=>$content,'type'=>'log_file'));
                    if($response->getMessage()=="success") {
                        Mage::getSingleton('core/session')->addSuccess("Klevu search log sent.");
                    }
                    
                    $content =  serialize(Mage::getModel('klevu_search/product_sync')->debugsIds());
                    $response = Mage::getModel("klevu_search/api_action_debuginfo")->debugKlevu(array('apiKey'=>$debugapi,'klevuLog'=>$content,'type'=>'product_table'));
                    
                    if($response->getMessage()=="success") {
                        Mage::getSingleton('core/session')->addSuccess("Status of indexing queue sent.");
                    }else {
                        Mage::getSingleton('core/session')->addSuccess($response->getMessage());
                    }
                    //send index status data
                    $content ="";
                    $allIndex= Mage::getSingleton('index/indexer')->getProcessesCollection();
                    foreach ($allIndex as $index) {
                        $content .= $index->getIndexerCode().":".$index->getStatus().'<br>';
                    }
                    $response = Mage::getModel("klevu_search/api_action_debuginfo")->debugKlevu(array('apiKey'=>$debugapi,'klevuLog'=>$content,'type'=>'index'));
                    if($response->getMessage()=="success") {
                        Mage::getSingleton('core/session')->addSuccess("Status of magento indices sent.");
                    }else {
                        Mage::getSingleton('core/session')->addSuccess($response->getMessage());
                    }
                    Mage::helper('klevu_search')->log(Zend_Log::DEBUG, sprintf("klevu debug data was sent to klevu server successfully."));
                }
                $rest_api = $this->getRequest()->getParam('api');
                if(!empty($rest_api)) {
                    Mage::getModel('klevu_search/product_sync')->sheduleCronExteranally($rest_api);
                    Mage::getSingleton('core/session')->addSuccess("Cron scheduled externally."); 
                }
                $this->_redirect('klevu/search/runexternalylog');
                
        }
        catch(Exception $e) {
              Mage::helper('klevu_search')->log(Zend_Log::DEBUG, sprintf("Product Synchronization was Run externally:\n%s", $e->getMessage()));
        }
    }
    
    function getLastlines($filepath, $lines, $adaptive = true) {
        // Open file
        $f = @fopen($filepath, "rb");
        if ($f === false) return false;
        // Sets buffer size
        if (!$adaptive) $buffer = 4096;
        else $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));
        // Jump to last character
        fseek($f, -1, SEEK_END);
        // Read it and adjust line number if necessary
        // (Otherwise the result would be wrong if file doesn't end with a blank line)
        if (fread($f, 1) != "\n") $lines -= 1;
        // Start reading
        $output = '';
        $chunk = '';
        // While we would like more
        while (ftell($f) > 0 && $lines >= 0) {
        // Figure out how far back we should jump
        $seek = min(ftell($f), $buffer);
        // Do the jump (backwards, relative to where we are)
        fseek($f, -$seek, SEEK_CUR);
        // Read a chunk and prepend it to our output
        $output = ($chunk = fread($f, $seek)) . $output;
        // Jump back to where we started reading
        fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
        // Decrease our line counter
        $lines -= substr_count($chunk, "\n");
        }
        // While we have too many lines
        // (Because of buffer size we might have read too many)
        while ($lines++ < 0) {
        // Find first newline and remove all text before that
        $output = substr($output, strpos($output, "\n") + 1);
        }
        // Close file and return
        fclose($f);
        return trim($output);
    }
    
    
    
}