<?php

use CartDefender_Actions_Helper_Data as CDData;

/**
 * Cart Defender main Block.
 *
 * @category    design_default
 * @package     CartDefender_Actions
 * @author      Heptium Ltd. (http://www.cartdefender.com/)
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Block_Script extends Mage_Core_Block_Template
{

    /**
     * @var CartDefender_Actions_Helper_Logger $logger Logger of Cart Defender
     *     specific messages.
     */
    private $logger;

    /** Initializes this class, particularly the logger and its Template. */
    public function _construct()
    {
        parent::_construct();
        $this->logger = Mage::helper('actions/logger');
        $this->logger->log('Script->_construct');
        $api = Mage::helper('actions')->getApi();
        if (Mage::helper('actions')->enabled() == true && !empty($api)) {
            $this->setTemplate('actions/script.phtml');
        }
    }

    /**
     * Returns a named Cart Defender configuration setting value.
     *
     * @param string $key the name of the setting.
     * @return string|null the value of the setting or null if key is invalid.
     */
    public function getSetting($key)
    {
        $data = Mage::helper('actions')->getSettings();
        return isset($data[$key]) ? $data[$key] : null;
    }

    /**
     * Returns the CartDefender JavaScript URL.
     *
     * @return string the CartDefender JavaScript URL.
     */
    public function getJavaScriptUrl()
    {
        return $this->getHost() . CDData::CD_SCRIPT_PATH;
    }

    /**
     * Returns the hostname of Cart Defender server.
     *
     * @return string the hostname of Cart Defender server.
     */
    private function getHost()
    {
        return $this->getSetting('test')
            ? $this->getSetting('test_server_url_start') : CDData::CD_HOST;
    }

    /**
     * Returns a JSON-encoded list of values to be (invisibly) rendered
     * on the page, for use of Cart Defender JavaScript.
     *
     * @return string JSON-encoded list of values of interest to our JS.
     */
    public function getVariables()
    {
        // "static" to compute only once and return cached value later.
        static $cdsvarData = null;
        if ($cdsvarData === null) {
            $store = Mage::app()->getStore();
            $storeGroup = $store->getGroup();
            $storeId = $store->getId();
            $website = Mage::app()->getWebsite();
            Mage::getSingleton('actions/correlationIdManager')
                ->ensureCorrelationIdSet();

            $cdsvarData = array(
                'api' => Mage::helper('actions')->getApi(),
                'website_url' =>
                    Mage::getStoreConfig('web/unsecure/base_url', 0),
                'app_software_name' => 'Magento ' . Mage::getEdition(),
                'app_software_version' => Mage::getVersion(),
                'website_id' => $website->getId(),
                'website_code' => $website->getCode(),
                'website_name' => $website->getName(),
                'website_default_shop_id' => $website->getDefaultGroupId(),
                'website_is_default' => $website->getIsDefault(),
                'shop_id' => $storeGroup->getGroupId(),
                'shop_name' => $storeGroup->getName(),
                'shop_root_category_id' => $storeGroup->getRootCategoryId(),
                'shop_default_shop_view_id' =>
                    $storeGroup->getDefaultStoreId(),
                'shop_view_id' => $store->getStoreId(),
                'shop_view_code' => $store->getCode(),
                'shop_view_name' => $store->getName(),
                'shop_view_locale_code' =>
                    Mage::getStoreConfig('general/locale/code', $storeId),
                'shop_view_url' => $store->getBaseUrl(),
                'shop_view_home_url' => $store->getHomeUrl(),
                'checkout_link' =>
                    Mage::helper('checkout/url')->getCheckoutUrl(),
                'multishipping_checkout_link' =>
                    Mage::helper('checkout/url')->getMSCheckoutUrl(),
                'request_route_name' =>
                    Mage::app()->getRequest()->getRouteName(),
                'page_identifier' =>
                    Mage::getSingleton('cms/page')->getIdentifier()
            );
        }
        $serializedData = Zend_Json::encode($cdsvarData, true);
        return $serializedData;
    }
}
