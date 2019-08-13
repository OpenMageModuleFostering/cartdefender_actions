<?php

use CartDefender_Actions_Helper_Data as CDData;

/**
 * A script sending text data to Cart Defender servers. While it sends
 * the data synchronously, it's meant to be used indirectly, via
 * AsyncLocalSender. This other utility does pass the data to this script
 * asynchronously, thus achieving non blocking behavior as far as
 * the PHP process from which we wanted to send the data is concerned.
 *
 * The reason for this setup is not blocking the PHP process rendering
 * the page for the user. If we blocked, the page rendering or AJAX
 * processing would be slowed down by the time it takes for the round-trips
 * to our servers (at least 3 in case of SSL - about 250 milliseconds for
 * transatlantic connections).
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_CartDefenderSenderController
    extends Mage_Core_Controller_Front_Action
{

    /**
     * @var CartDefender_Actions_Helper_Logger $logger Logger of Cart Defender
     *     specific messages.
     */
    private $logger;

    /** Initializes this class, particularly the logger. */
    public function _construct()
    {
        parent::_construct();
        $this->logger = Mage::helper('actions/logger');
    }
    
    public function preDispatch()
    {
        $this->setFlag('', self::FLAG_NO_START_SESSION, 1);
        $this->setFlag('', self::FLAG_NO_PRE_DISPATCH, 1);
        parent::preDispatch();
        return $this;
    }
    
    public function postDispatch()
    {
        $this->setFlag('', self::FLAG_NO_START_SESSION, 1);
        $this->setFlag('', self::FLAG_NO_POST_DISPATCH, 1);
        parent::postDispatch();
        return $this;
    }


    /**
     * Synchronously sends the text data contained in the POST request
     * invoking this controller to the Cart Defender servers.
     *
     * @return void
     */
    public function sendAction()
    {
        $settings = Mage::helper('actions')->getSettings();
        $request = $this->getRequest();
        if ($this->isRequestAllowed($request, $settings)) {
            $millisBefore = round(microtime(true) * 1000);

            // This is where the biz event is actually sent.
            $response = $this->sendRequest($request, $settings);

            // Logging.
            $millisAfter = round(microtime(true) * 1000);
            $this->logger->log(
                'CartDefenderSenderController->sendAction',
                (($response->getStatus() == 200) ? 'Success' : 'Error')
                . ' Sequence number: ' . $request->getPost('sequence_no')
                . ' Url: ' . $this->getUrl($settings)
                . ' Request time: ' . $millisBefore
                . ' Request latency: ' . ($millisAfter - $millisBefore)
            );
        } else {
            $this->logger->log(
                'CartDefenderSenderController->sendAction',
                'Error - request not allowed.'
            );
            exit;
        }
        $this->logger->log('CartDefenderSenderController->sendAction', 'Done');
        echo 'Done';
        exit;
    }

    /**
     * Returns whether the request is allowed for processing.
     *
     * @param object $request the request to check.
     * @param array $settings Cart Defender configuration settings.
     * @return bool whether the request is allowed for processing.
     */
    private function isRequestAllowed($request, $settings)
    {
        $isLocalRequest = $request->getPost('is_local_request');
        $data = $request->getPost('data');
        return $settings['enabled']            // CD plugin enabled?
            && !empty($isLocalRequest)   // Is local request?
            // Send key matches?
            && ($settings['send_key'] === $request->getPost('send_key'))
            && !empty($data);              // Data non-empty?
    }

    /**
     * Returns the URL to which the data should be sent by this script.
     *
     * @param array $settings Cart Defender configuration settings.
     * @return string URL to which the data should be sent by this script.
     */
    private function getUrl($settings)
    {
        $path = CDData::CD_PLUGIN_BIZ_API_PATH_START
            . '/' . $this->getRequest()->getPost('correlation_id') . '/'
            . CDData::CD_PLUGIN_BIZ_API_VERSION
            . CDData::CD_PLUGIN_BIZ_API_PATH_END;
        $useRawTestUrl = $settings['use_raw_test_url_for_biz_api'];
        return $settings['test'] ? ($settings['test_server_url_start']
            . ($useRawTestUrl ? '' : $path)) : (CDData::CD_HOST . $path);
    }

    /**
     * Sends a POST request to Cart Defender servers.
     *
     * @param object $request the request to send.
     * @param array $settings Cart Defender configuration settings.
     * @return void
     */
    private function sendRequest($request, $settings)
    {
        $client = new Varien_Http_Client($this->getUrl($settings));
        $client->setMethod(Varien_Http_Client::POST);
        $client->setAuth($settings['api'], '', Zend_Http_Client::AUTH_BASIC);
        $client->setRawData($request->getPost('data'));
        $client->setEncType('application/json');
        return $client->request();
    }
}
