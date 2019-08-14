<?php

/**
 * Add all of the attributes mapped in "Additional Attributes" section to the
 * "Other Attributes to Index" section
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$content = '<div class="kuContainer">
    <div id="loader" style="text-align: center;"><img src="{{skin url=images/klevu/ku-loader.gif}}" alt="search" /></div>
	<div class="kuProListing" id="kuProListing" style="display:none;">
		<div class="kuNoRecordFound" id="kuNoRecordFound" style="display:none;">
			<p>No matching products found for "red awef"</p>
		</div>
		<div class="kuFilters" id="kuFilters">
		</div><!-- End of kuFilters -->
		
		<div class="kuResultList" id="kuResultListBlock">
		<div class="kuListHeader">
			<div class="kuTotResults" id="kuTotResults"></div>
			<div class="kuSortingOpt">
			<div class="kuSortby">
				<label id="klevuSortLbl">Sort by:</label>
				<select name="kuSortby" id="kuSortby" onchange="klevu_changeSortingOptionsForLandigPage(this.value);">
					<option value="rel" id="klevuRelSort" >Relevance</option>
					<option value="lth" id="klevuLthSort">Price: Low to high</option>
					<option value="htl" id="klevuHtlSort">Price: High to low</option>
				</select>
			</div>
			
			<div class="kuView">
				<a class="kuGridviewBtn" id="gridViewBtn" onclick="setKuView('."'grid'".');">
					<span class="icon-gridview">&nbsp;</span>
				</a>
				<a class="kuListviewBtn kuCurrent" id="listViewBtn" onclick="setKuView('."'list'".');">
					<span class="icon-listview">&nbsp;</span>
				</a>
			</div>
			
			<div class="kuPerPage">
				<label id="klevuItemsPerPage">Items per page:</label>
				<select onchange="klevu_changeItemsPerPage(this.value);" id="noOfRecords1">
					<option>12</option>
					<option>24</option>
					<option>36</option>
				</select>
			</div>
			
			<div class="kuPagination" id="kuPagination1">	
			</div>
			
			<div class="kuClearLeft"></div>
			</div>
			
		</div>
		<div class="kuListView" id="kuResultsView">

		</div>
		<div class="kuBottomPagi">
			<div class="kuPerPage">
				<label id="klevuItemsPerPageFooter">Items per page:</label>
				<select onchange="klevu_changeItemsPerPage(this.value);" id="noOfRecords2">
					<option>12</option>
					<option>24</option>
					<option>36</option>
				</select>
			</div>
			<div class="kuPagination" id="kuPagination2">
				
			</div>
			<div class="kuClearLeft"></div>
			</div>
		
		
		</div>
		<div class="klevu-clear-both"></div>
	</div><!-- End of kuProListing -->
</div><!-- End of klevu-container -->
<input type="hidden" name="noOfRecords" id="noOfRecords" value="12"/>
<input type="hidden" name="startPos" id="startPos" value="0"/>
<input type="hidden" name="totalResultsFound" id="totalResultsFound" value="0"/>
<input type="hidden" name="searchedKeyword" id="searchedKeyword" value=""/>
<input type="hidden" name="totalPages" id="totalPages" value="0"/>
<script type="text/javascript" src="https://box.klevu.com/klevu-js-v1/js-1-1/klevu-landing.js">
</script>
<script type="text/javascript">// <![CDATA[
document.getElementById("searchedKeyword").value= klevu_getParamValue("q");
// ]]></script>';
//if you want one block for each store view, get the store collection
$stores = Mage::getModel('core/store')->getCollection()->addFieldToFilter('store_id', array('gt'=>0))->getAllIds();
// add static block 
$block = Mage::getModel('cms/block');
    $block->setTitle('Klevu Search Landing Page Block');
    $block->setIdentifier('klevu-search-landing-page-block-html');
    $block->setStores(array(0));
    $block->setIsActive(1);
    $block->setContent($content);
    $block->save();
    
// add cms page
$cmsPage = array(
    'title'           => 'Search results',
    'root_template'   => 'one_column',
    'identifier'      => 'search-result',
    'is_active'       => 1,
    'stores'          => array(0),
    'content' => '{{block type="cms/block" block_id="klevu-search-landing-page-block-html"}}',
);

Mage::getModel('cms/page')->setData($cmsPage)->save();
$installer->endSetup();
