<?php

use CartDefender_Actions_Helper_Data as CDData;

/**
 * Cart Defender business event builder.
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Model_EventBuilder
{

    /**
     * Builds a Cart Defender business event in JSON-encoded form.
     *
     * @param string $eventName name of the event to create.
     * @param array $observerData data coming from the event hook.
     * @param int $eventNo sequence number used for logging.
     * @return string the built event in JSON-encoded form.
     */
    public function buildEvent($eventName, $observerData, $eventNo)
    {
        $website = Mage::app()->getWebsite();
        $store = Mage::app()->getStore();

        $sessionData = $this->captureSessionData();
        $cart = $this->captureCartData($eventName, $observerData);
        $fullOrders = $this->captureOrderData($observerData);
        $visitorData = $sessionData['visitor_data'];
        $event = array(
            'api' => Mage::helper('actions')->getApi(),
            'appSoftwareName' => 'Magento ' . Mage::getEdition(),
            'appSoftwareVersion' => Mage::getVersion(),
            'eventType' => $eventName,
            'timestamp' => time(),
            'shopCurrentCurrency' => $store->getCurrentCurrencyCode(),
            'cart' => $cart,
            'orders' => $fullOrders,
            'eventNumber' => $eventNo,
            'websiteId' => $website->getId(),
            'websiteCode' => $website->getCode(),
            'websiteName' => $website->getName(),
            'websiteData' => $website->getData(),
            'shopData' => $store->getGroup()->getData(),
            'shopViewData' => $store->getData(),
            'shopViewLocaleCode' => Mage::getStoreConfig(
                'general/locale/code',
                $store->getStoreId()
            ),
            'shopViewBaseUrl' => $store->getBaseUrl(),
            'shopViewHomeUrl' => $store->getHomeUrl(),
            'checkoutLink' => Mage::helper('checkout/url')->getCheckoutUrl(),
            'multishippingCheckoutLink' =>
            Mage::helper('checkout/url')->getMSCheckoutUrl(),
            'cartLink' => Mage::helper('checkout/cart')->getCartUrl(),
            'visitorId' => isset($visitorData['visitor_id'])
                ? $visitorData['visitor_id'] : CDData::MISSING_VALUE,
            'visitorData' => $visitorData,
            'isLoggedIn' => $sessionData['is_logged_in'],
            'customerId' => $sessionData['customer_id'],
            'customerData' => $sessionData['customer_data'],
            'previousBizEventLatency' => CDData::MISSING_VALUE
        );
        $done = array();
        $utf8izedEvent = Mage::helper('actions')->utf8ize($event, $done);
        $logger = Mage::helper('actions/logger');
        $logger->log(
            'EventBuilder->buildEvent',
            'Built event: ' . $eventName
        );
        return Zend_Json::encode($utf8izedEvent, true);
    }

    /**
     * Returns various bits of data to be sent on each event,
     * obtained from Magento session.
     *
     * @return array various bits of data to be sent on each event,
     *     obtained from Magento session.
     */
    private function captureSessionData()
    {
        if (!session_id()) {
            return array(
                'visitor_data' => CDData::MISSING_VALUE,
                'customer_id' => CDData::MISSING_VALUE,
                'customer_data' => CDData::MISSING_VALUE,
                'is_logged_in' => CDData::MISSING_VALUE);
        }

        $sessionCore = Mage::getSingleton('core/session');
        $sessionCustomer = Mage::getSingleton('customer/session');
        return array(
            'visitor_data' => $sessionCore['visitor_data'],
            'customer_id' => isset($sessionCustomer)
                ? $sessionCustomer->getCustomerId() : CDData::MISSING_VALUE,
            'customer_data' => isset($sessionCustomer)
                ? $this->removePersonalData(
                    $sessionCustomer->getCustomer()->getData()
                )
                : CDData::MISSING_VALUE,
            'is_logged_in' => isset($sessionCustomer)
                && $sessionCustomer->isLoggedIn());
    }

    /**
     * Returns various bits of data to be sent on each event,
     * obtained from the Magento cart.
     *
     * @return array various bits of data to be sent on each event,
     *         obtained from the Magento cart.
     */
    private function captureCartData ($eventName, $observerData)
    {
        if (session_id()) {
            if ($eventName === 'checkout_cart_save_after') {
                $cart = $observerData['cart'];
                $quote = $cart->getQuote();
            } elseif ($eventName === 'sales_quote_collect_totals_after') {
                $quote = $observerData['quote'];
            } elseif ($eventName === 'sales_quote_product_add_after') {
                    $items = $observerData['items'];
                    $firstItem = $items[0];
                    $quote = $firstItem->getQuote();
            } else {
                $quote = Mage::getSingleton('checkout/cart')->getQuote();
            }
            $cartFromQuote = $this->getCartFromQuote($quote);
            return $cartFromQuote;
        } else {
            return array(
                    CDData::MISSING_VALUE
            );
        }
    }

    /**
     * Returns various bits of data to be sent on each event,
     * obtained from the Magento cart.
     *
     * @param object $quote the Magento cart.
     * @return array various bits of data to be sent on each event,
     *     obtained from the Magento cart.
     */
    private function getCartFromQuote($quote)
    {
        $cartItems = array();
        $cartData = array();
        if (isset($quote)) {
            $cartData = $this->removePersonalData($quote->getData());
            $productMediaConfig = Mage::getModel('catalog/product_media_config');
            $items = $quote->getAllVisibleItems();
            $productIds = array();
            foreach ($items as $item) {
                $itemData = $item->getData();
                $productFromData = $itemData['product'];
                $productIds[] = $productFromData->getId();
                $cartItems[] = $itemData;
            }
            if (! empty($productIds)) {
                Mage::getResourceModel('catalog/product_collection')->setStore(Mage::app()->getStore()->getStoreId());
                $collection = Mage::getResourceModel('catalog/product_collection');
                
                $prodCollection = Mage::getModel('catalog/product')->getCollection()
                    ->setStore(Mage::app()->getStore()->getStoreId())
                    ->addAttributeToSelect('entity_id')
                    ->addAttributeToSelect('meta_title')
                    ->addAttributeToSelect('name')
                    ->addAttributeToSelect('short_description')
                    ->addAttributeToSelect('price')
                    ->addAttributeToSelect('final_price')
                    ->addAttributeToSelect('image')
                    ->addAttributeToSelect('small_image')
                    ->addAttributeToSelect('thumbnail')
                    ->addAttributeToSelect('product_url')
                    ->addFieldToFilter('entity_id', array('in' => array(array_unique($productIds))
                        ));
                    
                    $prodInfo = array();
                
                foreach ($prodCollection as $prodData) {
                    $prodData->setStoreId(Mage::app()->getStore()->getStoreId());
                    $productUrl = $prodData->getProductUrl();
                    $prodArray = $prodData->getData();
                    $prodArray['cd_product_url'] = $productUrl;
                    $prodArray['cd_base_image_url'] = $productMediaConfig->getMediaUrl($prodData->getImage());
                    $prodArray['cd_small_image_url'] = $productMediaConfig->getMediaUrl($prodData->getSmallImage());
                    $prodArray['cd_thumbnail_url'] = $productMediaConfig->getMediaUrl($prodData->getThumbnail());
                    $prodInfo[] = $prodArray;
                }
                $cartData['cd_all_products_info'] = $prodInfo;
            }
        } else {
            $quote = CDData::MISSING_VALUE;
            $cartData[] = CDData::MISSING_VALUE;
            $cartItems[] = CDData::MISSING_VALUE;
        }
        return array('cart_data' => $cartData, 'cart_items' => $cartItems);
    }

    /**
     * Returns various bits of data to be sent on each event,
     * obtained from the Magento orders.
     *
     * @param array $observerData data coming from the event hook.
     * @return array various bits of data to be sent on each event,
     *     obtained from the Magento orders.
     */
    private function captureOrderData($observerData)
    {
        $fullOrders = array(CDData::MISSING_VALUE);
        if (!session_id() || (!isset($observerData['order_ids']) && !isset($observerData['order'])) ) {
            return $fullOrders;
        }
        
        if (!isset($observerData['order_ids'])) {
            $order = $observerData['order'];
            $fullOrders[] = $this->getFullOrder($order);
            
            return $fullOrders;
        }
        
        $orderIds = $observerData['order_ids'];
        foreach ($orderIds as $orderId) {
            $order = Mage::getModel('sales/order')->load($orderId);
            $fullOrders[] = $this->getFullOrder($order);
        }
        return $fullOrders;
    }

    /**
     * Return order data as an array including items and the related shopping cart.
     * @param $order
     * @return array various bits of data obtained from the Magento orders.
     */
    private function getFullOrder($order)
    {
        $oneFullOrder = array();
        $oneFullOrder['order_id'] = $order->getId();
        
        $orderData = $this->removePersonalData($order->getData());
        $oneFullOrder['order_data'] = $orderData;
        
        $quoteId = $orderData['quote_id'];
        $cartFromOrder = Mage::getModel('sales/quote')->load($quoteId);
        $oneFullOrder['cart'] = $this->getCartFromQuote($cartFromOrder);
        
        $items = $order->getAllVisibleItems();
        $orderItems = array();
        foreach ($items as $item) {
            $itemData = $item->getData();
            $orderItems[] = $itemData;
        }
        $oneFullOrder['order_items'] = $orderItems;
        
        return $oneFullOrder;
    }

    /**
     * Removes sensitive data from the input array and returns it.
     *
     * @param array $input some data.
     * @return array $input with sensitive data removed.
     */
    private function removePersonalData($input)
    {
        unset($input['email']);
        unset($input['prefix']);
        unset($input['firstname']);
        unset($input['middlename']);
        unset($input['lastname']);
        unset($input['suffix']);
        unset($input['taxvat']);
        unset($input['password_hash']);
        unset($input['customer_tax_class_id']);
        unset($input['customer_email']);
        unset($input['customer_prefix']);
        unset($input['customer_firstname']);
        unset($input['customer_middlename']);
        unset($input['customer_lastname']);
        unset($input['customer_suffix']);
        unset($input['customer_note']);
        unset($input['customer_taxvat']);
        return $input;
    }
}
