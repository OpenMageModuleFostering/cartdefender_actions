<?php

/**
 * Cart Defender Actions plugin for Magento 
 *
 * @category    design_default
 * @package     CartDefender_Actions
 * @author      Heptium Ltd. (http://www.cartdefender.com/)
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Block_Script extends Mage_Core_Block_Template {

  public function _construct() {
    parent::_construct();
    Mage::helper('actions')->log("Script Block constructing");
    $api = Mage::helper('actions')->getApi();
    if (Mage::helper('actions')->enabled() == true && !empty($api)) {
      $this->setTemplate('actions/script.phtml');
    }
  }

  /**
   * Helper method to get configuration data
   */
  public function getSetting($key) {
    $data = Mage::helper('actions')->getSettings();
    return isset($data[$key]) ? $data[$key] : null;
  }

  /**
   * Helper method to get CartDefender JavaScript URL
   */
  public function getJavaScriptUrl() {
    return $this->getHost() . CartDefender_Actions_Helper_Data::CD_SCRIPT_PATH;
  }
  
  private function getHost() {
    return $this->getSetting('test') 
        ? $this->getSetting('test_server_url_start')
        : CartDefender_Actions_Helper_Data::CD_HOST;
  }
  
  private function getCurrentQuoteData() {
    $quote = Mage::getSingleton('checkout/session')->getQuote();
    // get array of all items which can be displayed directly
    $itemsVisible = $quote->getAllVisibleItems();
    $cart_items = array();
    foreach ($itemsVisible as $item) {
      $item_data = $item->getData();
      $cart_items[] = $item_data;
    }
    //load data into array
    $quoteData = $quote->getData();
    $quoteData['itemsVisible'] = $cart_items;
    return $quoteData;
  }
  
  public function getVariables() {
    // "static" to compute only once and return cached value later. 
    static $cdsvar_data;

    if (empty($cdsvar_data)) {
      $store = Mage::app()->getStore();
      $storeGroup = $store->getGroup();
      $storeId = $store->getId();
      $website =  Mage::app()->getWebsite(); // Gets the current website details
      if (empty(CartDefender_Actions_Model_Observer::$correlation_id)) {
        Mage::helper('actions')->log("Script Block Setting correlation because it's empty");
        CartDefender_Actions_Model_Observer::setCorrelationId();
      }

      $cdsvar_data = array(
          'website_url' => Mage::getStoreConfig('web/unsecure/base_url', 0),
          'app_software_name' => 'Magento ' . Mage::getEdition(),
          'app_software_version' => Mage::getVersion(),
          'website_code' => $website->getCode(),
          'website_name' => $website->getName(),
          'website_default_shop_id' => $website->getDefaultGroupId(),
          'website_is_default' => $website->getIsDefault(),
          'shop_id' => $storeGroup->getGroupId(),
          'shop_name' => $storeGroup->getName(),
          'shop_root_category_id' => $storeGroup->getRootCategoryId(),
          'shop_default_shop_view_id' => $storeGroup->getDefaultStoreId(),
          'shop_view_id' => $store->getStoreId(),
          'shop_view_code' => $store->getCode(),
          'shop_view_name' => $store->getName(),
          'shop_view_locale_code' => Mage::getStoreConfig('general/locale/code', $storeId),
          'shop_view_url' => $store->getBaseUrl(),
          'shop_view_home_url' => $store->getHomeUrl(),
          'checkout_link' => Mage::helper('checkout/url')->getCheckoutUrl(),
          'multishipping_checkout_link' => Mage::helper('checkout/url')->getMSCheckoutUrl(),
          'request_route_name' => Mage::app()->getRequest()->getRouteName(),
          'page_identifier' => Mage::getSingleton('cms/page')->getIdentifier()
      );
    }
    $serialized_data = Zend_Json::encode($cdsvar_data, true);
    return $serialized_data;
  }

}
