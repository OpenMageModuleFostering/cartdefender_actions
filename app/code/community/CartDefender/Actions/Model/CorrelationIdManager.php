<?php

use CartDefender_Actions_Helper_Data as CDData;

/**
 * Cart Defender business logic for managing "correlation id", i.e. a number
 * used by our backend servers to match events.
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Model_CorrelationIdManager extends Varien_Object
{

    /**
     * The name of the cookie storing the CartDefender correlation id.
     */
    const CD_CORRELATION_COOKIE_NAME = "__cd_732655870348746856";
  
    /**
     * The name of the request header storing the CartDefender correlation id.
     */
    const CD_CORRELATION_HEADER_NAME = "X_CD_732655870348746856";
    
    /**
     * @var string|null $correlationId The CartDefender id used for correlating
     *     web and business events.
     */
    private $correlationId = null;

    /**
     * @var bool $responseStarted Whether the event
     *     "controller_front_send_response_before" was fired.
     */
    private $responseStarted = false;

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
        $this->sender = Mage::getSingleton('actions/eventAsyncLocalSender');
        $this->logger = Mage::helper('actions/logger');
    }

    /**
     * Returns the correlation id variable value.
     *
     * @return string|null the correlation id variable value.
     */
    public function getCorrelationId()
    {
        return $this->correlationId;
    }

    /**
     * Indicates that the response to the browser has begun, therefore not
     * permitting setting cookies anymore.
     *
     * @return void
     */
    public function setResponseStartedTrue()
    {
        $this->responseStarted = true;
    }

    /**
     * If correlation id variable is not yet set for the current request,
     * tries to set it.
     *
     * Setting it means either taking it from existing correlation id cookie,
     * or if no such cookie exists and we can still create cookies
     * (before response to the browser starts), trying to do that.
     *
     * @return string|null the correlation id (whether setting it was successful
     *     or not).
     */
    public function ensureCorrelationIdSet()
    {
        if (!Mage::helper('actions')->isCDEnabledAndRequestNonLocalNonAdmin()) {
            return $this->correlationId;
        }

        $this->logger->log(
            'CorrelationIdManager->ensureCorrelationIdSet',
            'Response started: ' . ($this->responseStarted ? 'Y' : 'N')
        );

        if (($this->correlationId !== null) || $this->responseStarted) {
            return $this->correlationId;
        }

        $cookie = Mage::getSingleton('core/cookie');
        $corrIdExternal = $cookie->get(self::CD_CORRELATION_COOKIE_NAME);
        if (!$corrIdExternal) {
            // Take id from header if not present on cookie
            $corrIdExternal = Mage::app()->getRequest()->getHeader(self::CD_CORRELATION_HEADER_NAME);
        }
        // If Correlation ID was not provided, generate it.
        $this->correlationId = $corrIdExternal ?: $this->generateCorrelationId();
        $phpSessionId = session_id();
        if (!$corrIdExternal && !empty($phpSessionId)) {
            $this->setCorrelationIdCookie();
            // Notify CD servers of new correlation id.
            $this->sender->sendEvent(
                CDData::START_OF_SESSION,
                $this->correlationId
            );
        }

        if ($this->correlationId) {
            $this->logger->log(
                'CorrelationIdManager->ensureCorrelationIdSet',
                'Correlation id [' . $this->correlationId . '] '
                . ($corrIdExternal ? 'taken from' : 'created, set on new') . ' cookie or header.'
            );
        }

        return $this->correlationId;
    }

    /**
     * Generates a 64-bit random decimal string.
     *
     * @return string 64-bit random decimal string.
     */
    private function generateCorrelationId()
    {
        // Don't use 4 as param b/c hexdec() has a limit of 7fffffff.
        return hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
            . hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
            . hexdec(bin2hex(openssl_random_pseudo_bytes(2)));
    }

    /**
     * Sets a correlation id cookie on the HTTP response with the value
     * of the correlation_id variable.
     *
     * @return void
     */
    private function setCorrelationIdCookie()
    {
        // This sets an HTTP response header. But if client browser has
        // 1-st party cookies disabled, the value won't be saved and
        // returned to PHP engine on next call. We'll try to regenerate it
        // in the same way, again failing to make it permanent. However,
        // the web sensor will detect cookies disabled in JS and not load
        // itself. The eventual outcome will be no web events, and biz
        // events with CD correlation ids varying with each PHP request.
        // Also, the nulls set corresponding settings to shop defaults.
        Mage::getSingleton('core/cookie')->set(
            self::CD_CORRELATION_COOKIE_NAME,
            $this->correlationId,
            0,
            '/',
            null /*domain*/,
            null /*secure*/,
            false /*HttpOnly*/
        );
    }
}
