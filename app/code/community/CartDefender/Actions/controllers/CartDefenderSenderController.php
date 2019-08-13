<?php

/**
 * A script sending business events asynchronously to Cart Defender servers.
 * 
 * The main reason for this script is so that we can launch it not blocking the main
 * PHP process rendering the page for the user. If we blocked, the page rendering or
 * AJAX processing would be slowed down by the time it takes for the round-trips to
 * our servers (at least 3 in case of SSL - about 250 milliseconds for any transatlantic
 * connections).
 *
 * @link       http://cartdefender.com
 * @since      0.0.1
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_CartDefenderSenderController extends Mage_Core_Controller_Front_Action {

  public function sendEventsAction() {
    $settings = Mage::helper('actions')->getSettings();
    // works only if flag is set to denote local API call and data exists
    if (!empty($_POST['is_local_request']) 
        && $settings['send_key'] === $_POST['send_key']  
        && !empty($_POST['data']) 
        && $settings['enabled']) {
      $millis_before = round(microtime(true) * 1000);
      
      $data = $_POST['data'];
      $options = array(
          'http' => array(
              'header' => "Content-type: application/json\r\n"
                  . "Authorization: Basic " . base64_encode($settings['api'] . ":") . "\r\n",
              'method' => 'POST',
              'content' => $data));
      $context = stream_context_create($options);
      $url = $this->getUrl($settings);
      $success = file_get_contents($url, false, $context);
      
      // Logging.
      $millis_after = round(microtime(true) * 1000);
      if (!$success) {
        // These will appear in the general server logs. 
        Mage::helper('actions')->log('[Cart Defender biz event sender ERROR]' 
            . ' Event number: ' . $_POST['event_no']
            . ' Url: ' . $url
            . ' Request time: ' . $millis_before
            . ' Request latency: ' . ($millis_after - $millis_before));
      } else {
        Mage::helper('actions')->log('[Cart Defender biz event sender success]'
            . ' Event number: ' . $_POST['event_no']
            . ' Url: ' . $url
            . ' Request time: ' . $millis_before
            . ' Request latency: ' . ($millis_after - $millis_before)
            . ' Is local equest: ' . $_POST['is_local_request']);
      }
    } else {
      Mage::helper('actions')->log('[Cart Defender biz event sender ERROR] '
          . 'Not a local POST request with an event or disabled');
    }
    Mage::helper('actions')->log('[Cart Defender biz event sender] Work done, time to finish.');
    echo 'Done';
  }
  
  /**
   * Returns the URL to which business events should be sent.
   */
  private function getUrl($settings) {
    $path = CartDefender_Actions_Helper_Data::CD_PLUGIN_BIZ_API_PATH_START
        . '/' . $_POST['correlation_id'] . '/'
        . CartDefender_Actions_Helper_Data::CD_PLUGIN_BIZ_API_VERSION
        . CartDefender_Actions_Helper_Data::CD_PLUGIN_BIZ_API_PATH_END;
    $use_raw_test_url = $settings['use_raw_test_url_for_biz_api'];
    return $settings['test']
        ? ($settings['test_server_url_start'] . ($use_raw_test_url ? '' : $path))
        : (CartDefender_Actions_Helper_Data::CD_HOST . $path);
  }
}

?>