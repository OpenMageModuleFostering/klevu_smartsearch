<?php

class Klevu_Search_Model_System_Config_Source_Landingoptions {

    const YES    = 1;
    const NO     = 0;
    const KlEVULAND = 2;

    public function toOptionArray() {
        $helper = Mage::helper("klevu_search");

        return array(
            array('value' => static::NO, 'label' => $helper->__("Disable")),
            array('value' => static::KlEVULAND, 'label' => $helper->__("Based on Klevu Template (Recommended)")),
            array('value' => static::YES, 'label' => $helper->__("Preserves Your Theme Layout**"))
            
        );
    }
}
