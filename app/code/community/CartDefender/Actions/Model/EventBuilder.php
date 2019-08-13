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
        $cart = $this->captureCartData();
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
     *     obtained from the Magento cart.
     */
    private function captureCartData()
    {
        return session_id()
            ? $this->getCartFromQuote(
                Mage::getSingleton('checkout/session')->getQuote()
            )
            : array(CDData::MISSING_VALUE);
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
            $items = $quote->getAllVisibleItems();
            foreach ($items as $item) {
                $itemData = $item->getData();
                $cartItems[] = $itemData;
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
        if (!session_id() || !isset($observerData['order_ids'])) {
            return $fullOrders;
        }

        $orderIds = $observerData['order_ids'];
        foreach ($orderIds as $orderId) {
            $oneFullOrder = array();
            $oneFullOrder['order_id'] = $orderId;

            $order = Mage::getModel('sales/order')->load($orderId);
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

            $fullOrders[] = $oneFullOrder;
        }
        return $fullOrders;
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
