<?php

/**
 * Specialization of AsyncLocalSender, for sending business events.
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Model_EventAsyncLocalSender
    extends CartDefender_Actions_Model_AsyncLocalSender
{

    /**
     * @var CartDefender_Actions_Model_EventBuilder $eventBuilder Builder of
     *     Cart Defender business events to be sent to Cart Defender servers.
     */
    private $eventBuilder;

    /** Initializes this class, particularly the logger. */
    public function _construct()
    {
        parent::_construct();
        $this->eventBuilder = Mage::getSingleton('actions/eventBuilder');
    }

    /**
     * Asynchronously sends a biz event to Cart Defender servers.
     *
     * @param string $eventName name of the event.
     * @param string|null $correlationId id used for correlating
     *     web and business events.
     * @param array $data event data.
     * @return void
     */
    public function sendEvent($eventName, $correlationId, $data = array())
    {
        $sequenceNo = Mage::helper('actions')->getSequenceNo();
        $event = $this->eventBuilder->buildEvent(
            $eventName,
            $data,
            $sequenceNo
        );
        $millisBefore = round(microtime(true) * 1000);
        $this->send($event, $sequenceNo, $correlationId);
        $millisAfter = round(microtime(true) * 1000);
        
        $this->logger->log(
            'EventAsyncLocalSender->sendEvent', ' Correlation ID: '. $correlationId 
            . ' PHP Session ID: '. session_id()
            . ' Sent event: ' . $eventName
            . ' Request time: ' . $millisBefore
            . ' Request latency: ' . ($millisAfter - $millisBefore)
        );
    }
}
