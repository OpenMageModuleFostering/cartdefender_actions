<?php

use CartDefender_Actions_Helper_Data as CDData;

/**
 * Cart Defender business event capture for Magento
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Model_Observer extends Varien_Event_Observer
{

    /**
     * Threshold since last event was sent in seconds.
     */
    const EVENT_TIME_THRESHOLD = 7200;

    /**
     * @var CartDefender_Actions_Model_CorrelationIdManager
     *     $correlationIdMgr Container for business logic related
     *     to correlation id, used to match business events and web events.
     */
    private $correlationIdMgr;

    /**
     * @var CartDefender_Actions_Model_EventAsyncLocalSender $sender
     *     Asynchronous sender of data to "synchronous remote sender"
     *     (another Cart Defender PHP script available on localhost),
     *     which then sends the data synchronously to Cart Defender servers.
     */
    private $sender;

    /**
     * @var CartDefender_Actions_Helper_Logger $logger Logger of Cart Defender
     *     specific messages.
     */
    private $logger;

    /** Initializes this class, particularly the utility objects it uses. */
    public function _construct()
    {
        parent::_construct();
        $this->correlationIdMgr =
            Mage::getSingleton('actions/correlationIdManager');
        $this->sender = Mage::getSingleton('actions/eventAsyncLocalSender');
        $this->logger = Mage::helper('actions/logger');
    }

    /**
     * Main event observer function.
     *
     * List of events: http://www.nicksays.co.uk/magento-events-cheat-sheet-1-7/
     *
     * @param object $observer event data.
     * @return void
     */
    public function captureEvent($observer)
    {
        if (!Mage::helper('actions')->isCDEnabledAndRequestNonLocalNonAdmin()) {
            return;
        }
        $this->logger->log('Observer->captureEvent');

        $corrId = $this->correlationIdMgr->ensureCorrelationIdSet();
        if ($corrId !== null) {
            if ($this->isLongSinceLastEvent()) {
                $this->sender->sendEvent(CDData::START_OF_SESSION, $corrId);
            }

            $eventName = $observer->getEvent()->getName();
            $eventData = $observer->getData();
            $this->sender->sendEvent($eventName, $corrId, $eventData);
        }
    }

    /**
     * Event observer function for event "controller_front_send_response_before"
     *
     * @param object $observer event data.
     * @return void
     */
    public function handleControllerFrontSendResponseBefore($observer)
    {
        if (!Mage::helper('actions')->isCDEnabledAndRequestNonLocalNonAdmin()) {
            return;
        }
        $this->logger->log('Observer->handleControllerFrontSendResponseBefore');

        $corrId = $this->correlationIdMgr->ensureCorrelationIdSet();
        if ($corrId !== null) {
            if ($this->isLongSinceLastEvent()) {
                $this->sender->sendEvent(CDData::START_OF_SESSION, $corrId);
            }
        }

        $this->correlationIdMgr->setResponseStartedTrue();
    }

    /**
     * Event observer function for event "customer_login"
     * (when a customer logs in.)
     *
     * @param object $observer event data.
     * @return void
     */
    public function handleCustomerLogin($observer)
    {
        // Note that the customer_login event carries the pre-login cart.
        $this->captureEvent($observer);
        // This isn't cleared on login automatically
        // even though Magento "frontend" cookie changes.
        Mage::getSingleton('core/session')->unsLastEventTime();
    }

    /**
     * Event observer function for event "customer_logout"
     * (when a customer logs out.)
     *
     * @param object $observer event data.
     * @return void
     */
    public function handleCustomerLogout($observer)
    {
        $this->captureEvent($observer);
        // This isn't cleared on logout automatically
        // even though Magento "frontend" cookie changes.
        Mage::getSingleton('core/session')->unsLastEventTime();
    }

    /**
     * Returns whether last biz event sent to Cart Defender servers happened a
     * long time ago (EVENT_TIME_THRESHOLD). It's intended use is to determine
     * whether we should now send session state biz event.
     *
     * @return bool whether last biz event was sent long ago.
     */
    private function isLongSinceLastEvent()
    {
        if (!session_id()) {
            return false;
        }

        $now = time();
        $lastEventAt = Mage::getSingleton('core/session')->getLastEventTime();
        $result = empty($lastEventAt)
            || (($now - $lastEventAt) > self::EVENT_TIME_THRESHOLD);

        $this->logger->log('Observer->isLongSinceLastEvent', 'Result: '
            . ($result ? 'Y' : 'N') . ' Time diff: '
            . ($lastEventAt ? ($now - $lastEventAt): 'N/A'));
        return $result;
    }
}
