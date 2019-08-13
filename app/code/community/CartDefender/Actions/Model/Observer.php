<?php

/**
 * Cart Defender business event capture for Magento
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Model_Observer extends Varien_Event_Observer {

  /**
   * Value for missing or unknown data taken from Helper class
   */
  private $MISSING_VALUE = CartDefender_Actions_Helper_Data::MISSING_VALUE;
  
  /**
   * Whether the event "controller_front_send_response_before" was fired.
   *
   * @since    0.0.1
   * @access   private
   */
  private static $response_started;

  /**
   * The CartDefender id used for correlating web and business events.
   *
   * @since    0.0.1
   * @access   public
   * @var      string    $correlation_id    The CartDefender correlation id.
   */
  public static $correlation_id;

  /**
   * The name of the cookie storing the CartDefender correlation id.
   *
   * @since    0.0.1
   */
  const CD_CORRELATION_COOKIE_NAME = "__cd_732655870348746856";

  /**
   * Number of seconds in 5 minutes.
   *
   * @since    0.0.1
   */
  const FIVE_MINUTES = 300;

  /**
   * Threshold since last event was sent in seconds.
   *
   * @since    0.0.1
   */
  const EVENT_TIME_THRESHOLD = 7200;

  /**
   * Key used to store on the session the time required to send the last biz event to our servers.
   */
  const BIZ_EVENT_LATENCY_KEY = 'biz_event_latency_key';

  /**
   * Key used to store on the session the errors occurring during the sending of biz events.
   */
  const BIZ_EVENT_SENDING_ERRORS_KEY = 'biz_event_sending_errors_key';

  /**
   * Key used to store on the session the latest sequence number of a biz event.
   */
  const BIZ_EVENT_SEQUENCE_NO_KEY = 'biz_event_sequence_no_key';
  const START_OF_SESSION = 'start_of_session';

  private function connectionConfigSetup() {
    // "static" to compute only once and return cached value later. 
    static $async_sender_api;
    static $connection_scheme;
    if (empty($async_sender_api) or empty($connection_scheme)) {
      $async_sender_api = parse_url(Mage::getUrl('cartdefender/CartDefenderSender/sendEvents', 
          array('_secure' => true)));
      if (empty($async_sender_api['port'])) {
        if ($async_sender_api['scheme'] == 'http') {
          $async_sender_api['port'] = 80;
        }
        if ($async_sender_api['scheme'] == 'https') {
          $async_sender_api['port'] = 443;
        }
      }
      $connection_scheme = ($async_sender_api['scheme'] == 'http') ? 'tcp://' : 'ssl://';
    }
    return array(
        'api' => $async_sender_api,
        'scheme' => $connection_scheme);
  }

  private function captureSessionData(&$sessionId, &$visitorData, &$customer_id, &$customerData,
      &$is_logged_in) {
    if (!session_id()) {
      $sessionId = $this->MISSING_VALUE;
      $visitorData = $this->MISSING_VALUE;
      $customer_id = $this->MISSING_VALUE;
      $customerData = $this->MISSING_VALUE;
      $is_logged_in = $this->MISSING_VALUE;
      $sessionCore = $this->MISSING_VALUE;
      $sessionCustomer = $this->MISSING_VALUE;
    } else {
      $sessionCore = Mage::getSingleton("core/session");
      $sessionCustomer = Mage::getSingleton('customer/session');
      $visitorData = $sessionCore['visitor_data'];
      $sessionId = $sessionCore->getEncryptedSessionId();

      if (!isset($sessionCustomer)) {
        $sessionCustomer = $this->MISSING_VALUE;
        $customerData = $this->MISSING_VALUE;
        $customer_id = $this->MISSING_VALUE;
        $is_logged_in = false;
      } else {
        $customer_id = $sessionCustomer->getCustomerId();
        $customerData = $sessionCustomer->getCustomer()->getData();
        /* Remove personal details */
        unset($customerData['email']);
        unset($customerData['prefix']);
        unset($customerData['firstname']);
        unset($customerData['middlename']);
        unset($customerData['lastname']);
        unset($customerData['suffix']);
        unset($customerData['taxvat']);
        unset($customerData['password_hash']);
        
        $is_logged_in = $sessionCustomer->isLoggedIn();
      }
    }
  }

  private function obtainCartFromQuote($quote) {
    $cart_items = array();
    $cart_data = array();
    if (isset($quote)) {
      $cart_data = $quote->getData();
      /* Remove personal details */
      unset($cart_data['customer_tax_class_id']);
      unset($cart_data['customer_email']);
      unset($cart_data['customer_prefix']);
      unset($cart_data['customer_firstname']);
      unset($cart_data['customer_middlename']);
      unset($cart_data['customer_lastname']);
      unset($cart_data['customer_suffix']);
      unset($cart_data['customer_note']);
      unset($cart_data['customer_taxvat']);
      unset($cart_data['password_hash']);
      
      $items = $quote->getAllVisibleItems();
      foreach ($items as $item) {
        $item_data = $item->getData();
        $cart_items[] = $item_data;
      }
    } else {
      $quote = $this->MISSING_VALUE;
      $cart_data[] = $this->MISSING_VALUE;
      $cart_items[] = $this->MISSING_VALUE;
    }
    return array('cart_data' => $cart_data, 'cart_items' => $cart_items);
  }

  private function captureCartData() {
    return session_id() 
        ? $this->obtainCartFromQuote(Mage::getSingleton('checkout/session')->getQuote()) 
        : array($this->MISSING_VALUE);
  }

  private function captureOrderData($observer_data) {
    $full_orders = array($this->MISSING_VALUE);
    if (session_id() && isset($observer_data['order_ids'])) {
      $orderIds = $observer_data['order_ids'];
      foreach ($orderIds as $_orderId) {
        $one_full_order = array(); //init empty var
        $one_full_order['order_id'] = $_orderId;

        $order = Mage::getModel('sales/order')->load($_orderId);
        $order_data = $order->getData();

        /* Remove personal details */      
        unset($order_data['customer_tax_class_id']);          
        unset($order_data['customer_email']);
        unset($order_data['customer_prefix']);
        unset($order_data['customer_firstname']);
        unset($order_data['customer_middlename']);
        unset($order_data['customer_lastname']);
        unset($order_data['customer_suffix']);
        unset($order_data['customer_note']);
        unset($order_data['customer_taxvat']);
        unset($order_data['password_hash']);

        $one_full_order['order_data'] = $order_data;

        $quote_id = $order_data['quote_id'];
        $cart_from_order = Mage::getModel('sales/quote')->load($quote_id);
        $one_full_order['cart'] = $this->obtainCartFromQuote($cart_from_order);

        $items = $order->getAllVisibleItems();
        $order_items = array(); //init empty var
        foreach ($items as $item) {
          $item_data = $item->getData();
          $order_items[] = $item_data;
        }
        $one_full_order['order_items'] = $order_items;

        $full_orders[] = $one_full_order;
      }
    }
    return $full_orders;
  }

  private function prepareBizEventData($event_name, $observer_data, $async_sender_api, $event_no) {
    $website =  Mage::app()->getWebsite(); // Gets the current website details
    $store = Mage::app()->getStore(); // Gets the current store's details  
    $storeId = $store->getStoreId();
    $storeGroup = $store->getGroup();
    
    $app_software_name = "Magento " . Mage::getEdition();
    $app_software_version = Mage::getVersion();
    $this->captureSessionData($sessionId, $visitorData, $customer_id, $customerData, $is_logged_in);
    $cart = $this->captureCartData();
    $full_orders = $this->captureOrderData($observer_data);
    $event = array(
        'api' => Mage::helper('actions')->getApi(),
        'appSoftwareName' => $app_software_name,
        'appSoftwareVersion' => $app_software_version,
        'eventType' => $event_name,
        'timestamp' => time(),
        'shop_current_currency' => $store->getCurrentCurrencyCode(),
        'cart' => $cart,
        'orders' => $full_orders,
        'eventNumber' => $event_no,
        'website_id' => $website->getId(),
        'website_code' => $website->getCode(),
        'website_name' => $website->getName(),
        'websiteData' => $website->getData(),
        'shopData' => $storeGroup->getData(),
        'shopViewData' => $store->getData(),
        'shopViewLocaleCode' => Mage::getStoreConfig('general/locale/code', $storeId),
        'shopViewBaseUrl' => $store->getBaseUrl(),
        'shopViewHomeUrl' => $store->getHomeUrl(),
        'checkout_link' => Mage::helper('checkout/url')->getCheckoutUrl(),
        'multishipping_checkout_link' => Mage::helper('checkout/url')->getMSCheckoutUrl(),
        'correlationId' => self::$correlation_id,
        'visitorId' => isset($visitorData['visitor_id'])
            ? $visitorData['visitor_id'] : $this->MISSING_VALUE,
        'visitorData' => $visitorData,
        'isLoggedIn' => $is_logged_in,
        'customerId' => $customer_id,
        'customerData' => $customerData,
        'previousBizEventLatency' => $this->MISSING_VALUE
    );
    return Zend_Json::encode($event, true);
  }

  private function shouldSendSessionState() {
    if (session_id()) {
      $now = time();
      $last_event_at = Mage::getSingleton('core/session')->getLastEventTime();
      $result = empty($last_event_at) || (($now - $last_event_at) > self::EVENT_TIME_THRESHOLD);
      
      Mage::helper('actions')->log("   -->Last event time: " 
          . (empty($last_event_at) ? "never" : $last_event_at));
      Mage::helper('actions')->log("Should send session state result: " . $result 
          . " Time difference: " . (empty($last_event_at) ? "N/A" : ($now - $last_event_at)));
      return $result;
    }
    return false;    
  }
  
  /**
   * Main event observer function. See ../etc/config.xml for observer configuration.
   * 
   * List of events: http://www.nicksays.co.uk/magento-events-cheat-sheet-1-7/
   */
  public function captureEvent($observer) {
    if (self::isCartDefenderEnabledAndIsRequestNonLocalAndIsRequestNonAdmin()) {
      if (!isset(self::$response_started) && !isset(self::$correlation_id)) {
        Mage::helper('actions')->log("Capture Event - Response not started yet, and no correlation "
            . "ID so we set correlation ID - Event: " . $observer->getEvent()->getName());
        self::setCorrelationId();
      }
      if (isset(self::$correlation_id)) { //do not send an event without correlation id
        if ($this->shouldSendSessionState()) {
          $this->sendStartOfSessionState();
          Mage::helper('actions')->log("Capture Event - Sent Session State Update. Event: " 
              . $observer->getEvent()->getName());
        }
        $event_name = $observer->getEvent()->getName();
        $observer_data = $observer->getData();
        $this->sendEvent($event_name, $observer_data);
        Mage::helper('actions')->log("Capture Event - Success - got the event name: ". $event_name);
      }
    }
  }

  /**
   * Event observer function which should be called whenever a customer logs in.
   * See ../etc/config.xml for observer configuration.
   */
  public function handleCustomerLogin($observer) {
    // Note that the customer_login event carries the pre-login cart.
    $this->captureEvent($observer);
    // This isn't cleared on login automatically even though Magento "frontend" cookie changes.
    Mage::getSingleton('core/session')->unsLastEventTime();
  }

  /**
   * Event observer function which should be called whenever a customer logs out. 
   * See ../etc/config.xml for observer configuration.
   */  
  public function handleCustomerLogout($observer) {
    $this->captureEvent($observer);
    // This isn't cleared on logout automatically even though Magento "frontend" cookie changes.
    Mage::getSingleton('core/session')->unsLastEventTime();
  }

  /**
   * Creates the text of a POST request (headers & contents) carrying a biz event, targeting
   * the biz event sender script on localhost.
   *  
   * @param string $biz_event_sender_url_path the URL path of the event sender
   * @param string $biz_event_sender_host the hostname of localhost. We can't use "localhost" or
   *     "127.0.0.1" in case request is sent via SSL and the certificate doesn't include these two.
   * @param int $event_no sequence number of the event, used for logging purposes.
   * @param string $data_string the biz event data to send.
   * @param bool $is_local_request is the request local for the same server ("PHP to PHP")
   * @param string $correlation_id the correlation identifier for events
   */
  private function createBizEventRequest($biz_event_sender_url_path, $biz_event_sender_host, 
      $event_no, $data_string, $is_local_request, $correlation_id) {
    $settings = Mage::helper('actions')->getSettings();
    $query_params = array(
        'event_no' => $event_no,
        'data' => $data_string,
        'is_local_request' => $is_local_request,
        'correlation_id' => $correlation_id,
        'send_key' => $settings['send_key']
    );
    $postdata = http_build_query($query_params);

    $req = "";
    $req.= "POST " . $biz_event_sender_url_path . " HTTP/1.1\r\n";
    $req.= "Host: " . $biz_event_sender_host . "\r\n"; 
    $req.= "Content-type: application/x-www-form-urlencoded\r\n";
    $req.= "Content-length: " . strlen($postdata) . "\r\n";
    $req.= "\r\n";
    $req.= $postdata;
    $req.= "\r\n\r\n";

    return $req;
  }

  /**
   * If not set yet, sets a 64-bit Cart Defender correlation id for the current session.
   * The value is either taken from a cookie, or generated and put on a new cookie,
   * which we then try to store.
   * 
   * NB: Cookie must be set before any content is sent to user's browser during a
   * given PHP request processing. Call this using a very early hook.
   * Mage::getModel(‘core/cookie’)->set($name, $value, $period);
   */
  public static function setCorrelationId() {
    if (self::isCartDefenderEnabledAndIsRequestNonLocalAndIsRequestNonAdmin()) {
      if (!isset(self::$correlation_id)) {
        Mage::helper('actions')->log("--->>>> Correlation variable not set, checking cookie.");
        $cookie = Mage::getSingleton('core/cookie');
        if (method_exists($cookie, 'get')) {
          $correlation_cookie = $cookie->get(self::CD_CORRELATION_COOKIE_NAME);
          if (!empty($correlation_cookie)) {
            self::$correlation_id = $correlation_cookie;
            Mage::helper('actions')->log("--->>>> Taking id from cookie: " . self::$correlation_id);
          } else {
            // Don't use 4 as param b/c hexdec() has a limit of 7fffffff.
            self::$correlation_id = hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
                . hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
                . hexdec(bin2hex(openssl_random_pseudo_bytes(2)));
            /* This sets an HTTP response header. But if client browser has 1-st party cookies
              disabled, the value won't be saved and returned to PHP engine on next call. We'll
              try to regenerate it in the same way, again failing to make it permanent. However,
              the web sensor will detect cookies disabled in JS and not load itself. The
              eventual outcome will be no web events, and biz events with CD correlation ids varying
              with each PHP request. Also, the nulls set corresponding settings to shop defaults. */
            Mage::getSingleton('core/cookie')->set(self::CD_CORRELATION_COOKIE_NAME,
                self::$correlation_id, 0, '/', null /* domain */,
                null /* secure */, false /* HttpOnly */);
            Mage::helper('actions')->log("--->>>> Cookie was empty, now: " . self::$correlation_id);
          }
        } else {
          Mage::helper('actions')->log("--->>>> Could not get the correlation cookie from the "
              . "browser. Server variable is: " . self::$correlation_id); 
        }
      }
    }
  }

  /**
   * Event observer function which should be called whenever PHP is about to start sending a 
   * response to the browser. See ../etc/config.xml for observer configuration.
   */
  public function ensureCookieSetBeforeResponse($observer) {
    if (self::isCartDefenderEnabledAndIsRequestNonLocalAndIsRequestNonAdmin()) {
      if (empty(self::$response_started)) {
        self::$response_started = true;
        self::setCorrelationId();
        if ($this->shouldSendSessionState()) { //check if should send start of session
          $this->sendStartOfSessionState();
          Mage::helper('actions')->log("Ensure cookie set - Sent Session State Update. Event: "
              . $observer->getEvent()->getName());
        }
        Mage::helper('actions')->log("Ensure cookie set - Response has not started yet - Event: "
            . $observer->getEvent()->getName() . " Correlation ID: " . self::$correlation_id);
      } else {
        Mage::helper('actions')->log("Ensure cookie set - Response started, don't set id - Event: "
            . $observer->getEvent()->getName() . " Correlation ID: " . $self::$correlation_id);
      }
    } else {
      Mage::helper('actions')->log("Ensure cookie set - local/admin/disabled, don't set id - Event:"
          . $observer->getEvent()->getName() . " Correlation ID: " . self::$correlation_id
          . " is Admin: " . Mage::app()->getStore()->isAdmin());
    }
  }

  private function sendStartOfSessionState() {
    Mage::helper('actions')->log("--->>>> Before Start of Session State. " . time());
    if (isset(self::$correlation_id)) { //do not send an event without correlation id
      $event_name = self::START_OF_SESSION;
      $observer_data = array();
      $this->sendEvent($event_name, $observer_data);
      Mage::helper('actions')->log("Send Start of session state - Success");
    }
  }

  private function sendEvent($event_name, $observer_data) {
    //setting the local request flag, which denotes PHP to PHP call within current server
    $is_local_request = true; 
    $connect = $this->connectionConfigSetup();
    $async_sender_api = $connect['api'];
    $connection_scheme = $connect['scheme'];
    $event_no = Mage::getSingleton('core/session')->getEventNo();
    $event_no = empty($event_no) ? 0 : $event_no;
    Mage::getSingleton('core/session')->setEventNo($event_no + 1);
    $data_string =
        $this->prepareBizEventData($event_name, $observer_data, $async_sender_api, $event_no);
    $req = $this->createBizEventRequest($async_sender_api['path'], $async_sender_api['host'], 
        $event_no, $data_string, $is_local_request, self::$correlation_id);
    $socket = @fsockopen($connection_scheme . $async_sender_api['host'], $async_sender_api['port'],
        $errno, $errstr, 0.025); //25ms timeout 
    $success = @fwrite($socket, $req);
    Mage::getSingleton('core/session')->setLastEventTime(time());
    if ($success) {
      Mage::helper('actions')->log("[Send Event] - Success - sent the full event:" . $data_string);
    } else {
      Mage::helper('actions')->log("[Send Event] - ERROR - number: " . $errno 
          . 'Error string: ' . $errstr
          . " the full event:" . $data_string);
    }
    $success = @fclose($socket);
  }
  
  /**
   * Checks if this is an external request as opposed to a local server call to avoid an
   * endless loop. Also verify that it's not Admin area of the store and that module is enabled.
   */
  private static function isCartDefenderEnabledAndIsRequestNonLocalAndIsRequestNonAdmin() {
    $settings = Mage::helper('actions')->getSettings();
    return empty($_POST['is_local_request']) 
        && !Mage::app()->getStore()->isAdmin() 
        && $settings['enabled'];
  }

}
