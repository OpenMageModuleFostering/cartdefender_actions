<?php

class CartDefender_Actions_Model_Api extends Mage_Api_Model_Resource_Abstract
{

    private $storeId;

    private $itemStartIndex;

    private $itemCount;

    private $excludeOutOfStock;

    private $excludeDisabled;

    private $excludeInvisible;

    private $productIds;

    private $store;

    public function getVersion ()
    {
        // Get path to config file
        $filePath = realpath(dirname(__FILE__)) . '/../etc/config.xml';
        
        // Open the config file -- ignore errors
        $configFileContents = @file_get_contents($filePath);
        
        // Return message if config file could not be opened
        if (empty($configFileContents) == true) {
            return 'Could not open config.xml';
        }
        
        // Get version from config file
        $pattern = '|<version>([^<]+)|i';
        if (preg_match($pattern, $configFileContents, $matches) !== 1) {
            return 'Could not get version from config.xml';
        }
        
        // Return the version string
        return $matches[1];
    }

    private function initialize () // TODO add logging and CD API exception
    {
        // Increase maximum execution time to 4 hours
        ini_set('max_execution_time', 14400);
        
        // Check format of the item start and count
        if (0 == preg_match('|^\d+$|', $this->itemStartIndex)) {
            Mage::throwException(
                    'The specified item start index is not formatted correctly: ' .
                             $this->itemStartIndex);
        }
        if (0 == preg_match('|^\d+$|', $this->itemCount)) {
            Mage::throwException(
                    'The specified item count is not formatted correctly: ' .
                             $this->itemCount);
        }
        // Check range of the item start and ccount
        $this->itemStartIndex = intval($this->itemStartIndex);
        $this->itemCount = intval($this->itemCount);
        if ($this->itemStartIndex < 0) {
            Mage::throwException(
                    'The specified item start index is less than zero: ' .
                             $this->itemStartIndex);
        }
        if ($this->itemCount <= 0) {
            Mage::throwException(
                    'The specified item count is less than or equal to zero: ' .
                             $this->itemCount);
        }
        
        // Check format of the storeId
        if (0 == preg_match('|^\d+$|', $this->storeId)) {
            Mage::throwException(
                    'The specified Store is not formatted correctly: ' .
                             $this->storeId);
        }
        $this->storeId = intval($this->storeId);
        try {
            $this->store = Mage::app()->getStore($this->storeId);
        } catch (Exception $e) {
            Mage::throwException(
                    'Error getting store with ID ' . $this->storeId .
                             ". The store probably does not exist. " .
                             get_class($e) . " " . $e->getMessage());
        }
        
        $this->excludeDisabled = (bool) $this->excludeDisabled;
        $this->excludeInvisible = (bool) $this->excludeInvisible;
        
        if (! is_array($this->productIds)) {
            $this->productIds = null;
        }
    }

    public function export ($storeId = 0, $itemStartIndex = 0, $itemCount = 100000, 
            $excludeDisabled = true, $excludeInvisible = true, $productIds = null)
    {
        $this->storeId = $storeId;
        $this->itemStartIndex = $itemStartIndex;
        $this->itemCount = $itemCount;
        $this->excludeDisabled = $excludeDisabled;
        $this->excludeInvisible = $excludeInvisible;
        $this->productIds = $productIds;
        
        $this->initialize();
        
        // TODO Add FINAL price after rules applied
        // TODO Check IMAGE URLS
        
        try {
            $collection = Mage::getResourceModel('catalog/product_collection')->setPage(
                    $itemStartIndex, $itemCount)
                ->addAttributeToSelect('entity_id')
                ->setOrder('entity_id', 'ASC')
                ->addAttributeToSelect('sku')
                ->addAttributeToSelect('status')
                ->addAttributeToSelect('visibility')
                ->addAttributeToSelect('created_at')
                ->addAttributeToSelect('updated_at')
                ->addAttributeToSelect('product_url')
                ->addAttributeToSelect('type_id');
            
            if ($this->excludeInvisible === true) {
                $collection->addFieldToFilter('visibility', 
                        array(
                                'nlike' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE
                        ));
            }
            
            if (is_array($this->productIds)) {
                $collection->addFieldToFilter('entity_id', 
                        array(
                                $this->productIds
                        ));
            }
            
            if ($this->excludeDisabled === true) {
                $collection->addAttributeToFilter('status', 1); // enabled
            }
            
            if (Mage::helper('catalog')->isModuleEnabled(
                    'Mage_CatalogInventory')) {
                $collection->joinField('qty', 'cataloginventory/stock_item', 
                        'qty', 'product_id=entity_id', '{{table}}.stock_id=1', 
                        'left');
            }
            
            if ($this->store->getId()) {
                $collection->addStoreFilter($this->store);
                $collection->joinAttribute('name', 'catalog_product/name', 
                        'entity_id', null, 'inner', $this->store->getId());
                $collection->joinAttribute('description', 
                        'catalog_product/description', 'entity_id', null, 
                        'inner', $this->store->getId());
                $collection->joinAttribute('short_description', 
                        'catalog_product/short_description', 'entity_id', null, 
                        'inner', $this->store->getId());
                $collection->joinAttribute('image', 'catalog_product/image', 
                        'entity_id', null, 'inner', $this->store->getId());
                $collection->joinAttribute('price', 'catalog_product/price', 
                        'entity_id', null, 'left', $this->store->getId());
                $collection->joinAttribute('special_price', 
                        'catalog_product/price', 'entity_id', null, 'left', 
                        $this->store->getId());
            } else {
                $collection->addAttributeToSelect(
                        array(
                                'name',
                                'description',
                                'short_description',
                                'image',
                                'price',
                                'special_price'
                        ));
            }
            $mediaConfig = Mage::getModel('catalog/product_media_config');
            
            $productArray = array(
                    'entity_id' => '',
                    'sku' => '',
                    'status' => '',
                    'visibility' => '',
                    'created_at' => '',
                    'updated_at' => '',
                    'product_url' => '',
                    'type_id' => '',
                    'qty' => '',
                    'name' => '',
                    'description' => '',
                    'short_description' => '',
                    'base_image' => '',
                    'small_image' => '',
                    'thumbnail_image' => '',
                    'price' => '',
                    'special_price' => '',
                    'parent_id' => ''
            );
            
            $content .= implode(',', array_keys($productArray));
            $content .= "\n";
            
            foreach ($collection as $product) {
                if ("no_selection" == $product->getImage()) {
                    $productImage = "";
                } else {
                    $productImage = $mediaConfig->getMediaUrl(
                            $product->getImage());
                }
                
                $parentIdsList = '';
                if ($product->getTypeId() ==
                         Mage_Catalog_Model_Product_Type::TYPE_SIMPLE) {
                    $parentIds = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild(
                            $product->getId());
                    if (! $parentIds) {
                        $parentIds = Mage::getModel(
                                'catalog/product_type_configurable')->getParentIdsByChild(
                                $product->getId());
                    }
                    if (isset($parentIds[0])) {
                        $parentIdsList = implode(',', $parentIds);
                    }
                }
                $productArray['entity_id'] = $product->getId();
                $productArray['sku'] = $product->getSku();
                $productArray['status'] = $product->getStatus();
                $productArray['visibility'] = $product->getVisibility();
                $productArray['created_at'] = $product->getCreatedAt();
                $productArray['updated_at'] = $product->getUpdatedAt();
                $productArray['product_url'] = $product->setStoreId(
                        $this->store->getId())
                    ->getProductUrl();
                $productArray['type_id'] = $product->getTypeId();
                $productArray['qty'] = $product->getQty();
                $productArray['name'] = $product->getName();
                $productArray['description'] = $product->getDescription();
                $productArray['short_description'] = $product->getShortDescription();
                $productArray['base_image'] = $productImage;
                $productArray['small_image'] = $mediaConfig->getMediaUrl(
                        $product->getSmallImage());
                $productArray['thumbnail_image'] = $mediaConfig->getMediaUrl(
                        $product->getThumbnail());
                $productArray['price'] = $product->getPrice();
                $productArray['special_price'] = $product->getSpecialPrice();
                $productArray['parent_id'] = $parentIdsList; // get parent prod
                                                             // ids
                                                             
                // Escape double quotes
                $escapedProductArray = str_replace('"', '""', $productArray);
                
                // Write each product to the file
                $content .= '"';
                $content .= implode('","', $escapedProductArray);
                $content .= "\"\n";
            }
        } catch (Exception $e) {
            return 'The query resulted in an exception: ' . get_class($e) . ' ' .
                     $e->getMessage();
        }
        return $content;
    }
}