<?php

/**
 * Cart Defender extension helper, with miscellaneous utility functions.
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Helper_Data extends Mage_Core_Helper_Abstract
{

    /**
     * Value for missing or unknown data.
     */
    const MISSING_VALUE = '(?MV?)';

    /**
     * The protocol & hostname of the Cart Defender server.
     */
    const CD_HOST = 'https://app.cartdefender.com';

    /*
     * The CD_PLUGIN_BIZ_API_ constants refer to the CartDefender biz event
     * API for shop platform plugins (e.g. WooCommerce, Magento, etc),
     * as opposed to JSON REST API for custom shops.
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

    /**
     * The name of the start of session biz event.
     */
    const START_OF_SESSION = 'start_of_session';

    /**
     * Returns the API setting.
     *
     * @return string the API setting.
     */
    public function getApi()
    {
        return Mage::getStoreConfig('actions/settings/api');
    }

    /**
     * Returns whether the Cart Defender extension is enabled.
     *
     * @return bool whether the Cart Defender extension is enabled.
     */
    public function enabled()
    {
        return (bool) Mage::getStoreConfig('actions/settings/enabled');
    }

    /**
     * Returns whether the Cart Defender extension is running in test mode.
     *
     * @return bool whether the Cart Defender extension is running in test mode.
     */
    private function test()
    {
        return (bool) Mage::getStoreConfig('actions/settings/test');
    }

    /**
     * For cases when Cart Defender extension is running in test mode, returns
     * the test server URL protocol, domain, and path prefix (if any).
     *
     * @return string the test server URL protocol, domain,
     *     and path prefix (if any).
     */
    private function getTestServerUrlStart()
    {
        return Mage::getStoreConfig('actions/settings/test_server_url_start');
    }

    /**
     * Returns whether the value returned by getTestServerUrlStart() is used
     * exactly as provided for sending biz events - i.e. without appending
     * things like "/plugin/[correlation id]/v1-beta/bizEvent". This is useful
     * for inspecting biz event JSONs with something like RequestBin.
     *
     * @return bool whether the value returned by getTestServerUrlStart()
     *     is used exactly as provided for sending biz events.
     */
    private function getUseRawTestUrlForBizApi()
    {
        return (bool) Mage::getStoreConfig(
            'actions/settings/use_raw_test_url_for_biz_api'
        );
    }

    /**
     * Obtains an internal shared key to access the synchronous remote
     * sender PHP script, used e.g. to send business events to Cart Defender
     * servers.
     *
     * @return string shared key needed to access the remote sender script.
     */
    public function getSendKey()
    {
        $sendKey = Mage::getStoreConfig('actions/settings/send_key');
        if (empty($sendKey)) {
            $sendKey = hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
                . hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
                . hexdec(bin2hex(openssl_random_pseudo_bytes(3)))
                . hexdec(bin2hex(openssl_random_pseudo_bytes(3)));
            Mage::getConfig()->saveConfig(
                'actions/settings/send_key',
                $sendKey,
                'default',
                0
            );
        }
        return $sendKey;
    }

    /**
     * Returns an array containing Cart Defender configuration settings.
     *
     * @return array Cart Defender configuration settings.
     */
    public function getSettings()
    {
        // "static" to compute only once and return cached value later.
        static $data = null;
        if ($data === null) {
            // Settings
            $data = array(
                'api' => $this->getApi(),
                'enabled' => $this->enabled(),
                'test' => $this->test(),
                'test_server_url_start' => $this->getTestServerUrlStart(),
                'use_raw_test_url_for_biz_api' =>
                    $this->getUseRawTestUrlForBizApi(),
                'send_key' => $this->getSendKey()
            );
        }
        return $data;
    }

    /**
     * Recursively converts to UTF-8 all the strings contained in an array,
     * an object, or just a single string.
     *
     * @param mixed $input the variable whose string entries are
     *     to be converted to UTF-8. If it's not an array, object,
     *     or string, the function exits immediately and does nothing.
     * @param array $objectsDone helper array storing what has already
     *     been seen in the recursion. Pass in an empty array when
     *     using this function.
     * @return mixed input, but with string entries converted to UTF-8.
     */
    public function utf8ize($input, &$objectsDone)
    {
        if (!in_array($input, $objectsDone, true)) {
            if (is_array($input)) {
                $objectsDone[] = $input;
                foreach ($input as $key => $value) {
                    $input[$key] = $this->utf8ize($value, $objectsDone);
                }
            } elseif (is_string($input)) {
                if (!mb_detect_encoding($input, 'utf-8', true)) {
                    $input = utf8_encode($input);
                    return $input;
                } else {
                    return $input;
                }
            } elseif (is_object($input)) {
                $objectsDone[] = $input;
                foreach ($input as $key => $value) {
                    $input->$key = $this->utf8ize($value, $objectsDone);
                }
            }
            return $input;
        } else {
            return $input;
        }
    }

    /**
     * First call to this function in a given Magento session returns 0.
     * Each following call returns the previous number incremened by 1.
     *
     * @return int the next sequence number in this Magento session.
     */
    public function getSequenceNo()
    {
        $session = Mage::getSingleton('core/session');
        $number = $session->getSequenceNo() ? : 0;
        $session->setSequenceNo($number + 1);
        return $number;
    }

    /**
     * Checks that:
     * (1) Cart Defender extension is enabled,
     * (2) this is an external request as opposed to a local server call,
     * to avoid an endless loop,
     * (3) we're not in the Admin area of the store.
     *
     * @return bool whether extension is enabled, and current request is
     *     non local & non admin.
     */
    public function isCDEnabledAndRequestNonLocalNonAdmin()
    {
        $settings = $this->getSettings();
        return !Mage::app()->getRequest()->getPost('is_local_request')
            && !Mage::app()->getStore()->isAdmin()
            && $settings['enabled'];
    }
}
