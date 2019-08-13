<?php

/**
 * Cart Defender Actions plugin for Magento 
 *
 * @category    design_default
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Helper_Data extends Mage_Core_Helper_Abstract {

  /**
   * Value for missing or unknown data.
   */
  const MISSING_VALUE = '(?MV?)';

  /**
   * The protocol & hostname of the Cart Defender server.
   */
  const CD_HOST = 'https://app.cartdefender.com';
  
  /*
   * The CD_PLUGIN_BIZ_API_ constants refer to the CartDefender biz event API for shop platform
   * plugins (e.g. WooCommerce, Magento, etc), as opposed to JSON REST API for custom shops.
   */
  
  /**
   * The start of the path part of the biz API URL.  
   */
  const CD_PLUGIN_BIZ_API_PATH_START = '/plugin';
  
  /**
   * The version of the biz API.
   */
  const CD_PLUGIN_BIZ_API_VERSION = 'v1-beta';
  
  /**
   * The end of the path part of the biz API URL.
   */
  const CD_PLUGIN_BIZ_API_PATH_END = '/magentoBizEvent';

  /**
   * The path part of the URL to Cart Defender JavaScript file.
   */
  const CD_SCRIPT_PATH = '/script/cartdefender.js';

  public function getApi() {
    return Mage::getStoreConfig('actions/settings/api');
  }

  public function enabled() {
    return (bool) Mage::getStoreConfig('actions/settings/enabled');
  }

  private function test() {
    return (bool) Mage::getStoreConfig('actions/settings/test');
  }

  private function getTestServerUrlStart() {
    return Mage::getStoreConfig('actions/settings/test_server_url_start');
  }

  private function getUseRawTestUrlForBizApi() {
    return (bool) Mage::getStoreConfig('actions/settings/use_raw_test_url_for_biz_api');
  }

  private function getCurrentSendKeyValue() {
    return Mage::getStoreConfig('actions/settings/send_key');
  }
  
  /**
   * Obtains an internal shared key to access the controller which is sending the business events.
   */
  public function getSendKey() {
    $send_key = $this->getCurrentSendKeyValue();
    if (empty($send_key)) {
      $send_key = hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
          . hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
          . hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
          . hexdec(bin2hex(openssl_random_pseudo_bytes(3)));
      Mage::getConfig()->saveConfig('actions/settings/send_key', $send_key, 'default', 0);
    }
    return $send_key;
  }
  
  public function getSettings() {
    // "static" to compute only once and return cached value later. 
    static $data;
    if (empty($data)) {
      // Settings
      $data = array(
          'api' => $this->getApi(),
          'enabled' => $this->enabled(),
          'test' => $this->test(),
          'test_server_url_start' => $this->getTestServerUrlStart(),
          'use_raw_test_url_for_biz_api' => $this->getUseRawTestUrlForBizApi(),
          'send_key' => $this->getSendKey()
      );
    }
    return isset($data) ? $data : null;
  }
  
  /**
   * Logs a message if running in test mode.
   */
  public function log($message) {
    $settings = $this->getSettings();
    if (!empty($settings) && $settings['test']) {
      Mage::log($message);
    }
  }
}
