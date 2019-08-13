<?php

use CartDefender_Actions_Helper_Data as CDData;

/**
 * Asynchronous sender of text data to "synchronous remote sender" (another
 * Cart Defender PHP script available on localhost), which then sends the
 * data synchronously to Cart Defender servers.
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Model_AsyncLocalSender extends Varien_Object
{

    /**
     * @var CartDefender_Actions_Helper_Logger $logger Logger of Cart Defender
     *     specific messages.
     */
    protected $logger;

    /** Initializes this class, particularly the logger. */
    public function _construct()
    {
        parent::_construct();
        $this->logger = Mage::helper('actions/logger');
    }

    /**
     * Sends the data passed to this function using one of the possible
     * transport methods.
     *
     * @param string $data
     *            the data to send.
     * @param int $sequenceNo
     *            sequence number of the data being sent,
     *            used for logging purposes.
     * @param string $correlationId
     *            the correlation identifier needed
     *            by Cart Defender backend.
     * @return void
     */
    public function send($data, $sequenceNo, $correlationId)
    {
        $settings = Mage::helper('actions')->getSettings();
        $localControllerUrl = $settings['sender_controller_url'];
        if ($settings['transport_method'] ===
                CartDefender_Actions_Model_System_Config_Source_Transport::ASYNC_SOCKET) {
                    $this->sendAsyncSocket($data, $sequenceNo, $correlationId,
                            $settings['send_key'], $localControllerUrl);
                } elseif ($settings['transport_method'] ===
                        CartDefender_Actions_Model_System_Config_Source_Transport::CURL_PROCESS) {
                            $this->sendCurl($data, $sequenceNo, $correlationId, //TODO check
                                    $settings, $this->getUrl($correlationId, $settings));
                } elseif ($settings['transport_method'] ===
                        CartDefender_Actions_Model_System_Config_Source_Transport::SYNC_HTTP) {
                            $this->sendSyncHttpClient($data, $sequenceNo, $correlationId,
                                    $settings['send_key'], $localControllerUrl);
                }
    }

    /**
     * Executes an async cURL process to send the data to the Cart Defender servers
     *
     */
    private function sendCurl($data, $sequenceNo, $correlationId, $settings, $targetUrl) 
    {
        $apiKey = $settings['api'];
        $cmd = "curl -u $apiKey: -X POST -H 'Content-Type: application/json'";
        $cmd .= " -d '$data' " . " -L --post301 --post302 --post303 '$targetUrl'";
        if ( ! $settings['test']) { // Async when in normal mode (not test)
            $cmd .= " > /dev/null 2>&1 &"; 
        }
        exec($cmd, $output, $exit);
        
        Mage::getSingleton('core/session')->setLastEventTime(time());
        
        $this->logger->log(
                'AsyncLocalSender->sendCurl', ' Correlation ID: '. $correlationId 
                . ' PHP Session ID: '. session_id() 
                . ' Sequence No: ' . $sequenceNo
                . ' Executed curl request. '
                . ' Command:' . $cmd
                . ' Exec output: ' . print_r($output,true)
                . ' Return Var: ' . $exit
        );
    }
   
    /**
     * Mainly for connection and controller testing purposes. 
     * Synchronously sends the data passed to "synchronous remote sender"
     * (another Cart Defender PHP script available on localhost),
     * which then sends the data synchronously to Cart Defender servers.
     *
     */
    private function sendSyncHttpClient($data, $sequenceNo, $correlationId, $sendKey, $localControllerUrl)
    {
        $client = new Varien_Http_Client($localControllerUrl);
        $client->setMethod(Varien_Http_Client::POST);
        $client->setCookie('CartDefenderCorrelationId', $correlationId);
        $client->setConfig(array('strictredirects' => true));
        $client->setEncType();
        $client->setParameterPost('sequence_no', $sequenceNo);
        $client->setParameterPost('is_local_request', true);
        $client->setParameterPost('correlation_id', $correlationId);
        $client->setParameterPost('send_key', $sendKey);
        $client->setParameterPost('data', $data);
        $response = $client->request('POST');
        $lastRequest = $client->getLastRequest();
        
        Mage::getSingleton('core/session')->setLastEventTime(time());
        
        $this->logger->log(
                'AsyncLocalSender->sendSyncHttpClient', ' Correlation ID: '. $correlationId 
                . ' PHP Session ID: '. session_id() . 'Request: ' . $lastRequest 
                . ' Response: '. (($response->getStatus() == 200) ? 'Success ' : 'Error ')
                . 'Response Status Code: ' . $response->getStatus()
                . 'Entire Response string: ' . $response->asString()
        );
    }
    
    /**
     * Asynchronously sends the data passed to "synchronous remote sender"
     * (another Cart Defender PHP script available on localhost),
     * which then sends the data synchronously to Cart Defender servers.
     *
     * See https://segment.com/blog/how-to-make-async-requests-in-php/
     * for background on using fsockopen/pfsockopen. See however
     * http://stackoverflow.com/questions/34769361 for the problem with
     * pfsockopen. For this reason we use fsockopen, but to circumvent
     * the fact that the connection opening is blocking, which can be
     * very costly with remote servers, we connect to the same server
     * (or group of servers) that the PHP process executing this runs on.
     * Thus, we get blocking, but very fast connection opening, and then
     * we send the request asynchronously, without waiting for response -
     * using fclose() immediately.
     *
     * @param string $data the data to send.
     * @param int $sequenceNo sequence number of the data being sent,
     *     used for logging purposes.
     * @param string $correlationId the correlation identifier needed
     *     by Cart Defender backend.
     * @return void
     */
    private function sendAsyncSocket($data, $sequenceNo, $correlationId, $sendKey, $localControllerUrl) 
    {
        $remoteSenderConf = $this->getRemoteSenderConf($localControllerUrl);
        $request = $this->createRequest(
            $remoteSenderConf['path'],
            $remoteSenderConf['host'],
            $data,
            $sequenceNo,
            $correlationId,
            $sendKey
        );
        $socket = fsockopen(
            $remoteSenderConf['protocol'] . $remoteSenderConf['host'],
            $remoteSenderConf['port'],
            $errno,
            $errstr,
            0.3 /*timeout*/
        );
        $strLen = strlen($request);
        $writeResult = $this->fwriteStream($socket, $request, $strLen, 6);
        fclose($socket);
        $success = ($writeResult === $strLen);
        
        Mage::getSingleton('core/session')->setLastEventTime(time());
        
        $this->logger->log('AsyncLocalSender->sendAsyncSocket', ' Correlation ID: '. $correlationId 
            . ' PHP Session ID: '. session_id() 
            . ($success ? ' Success. ' : ('Error (num: [' . $errno . '], msg: [' . $errstr . '])'))
            . ' Request: [' . $request . ']');
    }
    
    /**
     * Writes stream data over network sockets and expects the number of zero bytes written to be 
     * smaller than the set parameter.
     *
     * @return int the number of bytes written
     */
    private function fwriteStream($fp, $string, $strLen, $maxZeros = 3) 
    {
        $numZeros = 0;
        for ($written = 0; $written < $strLen; $written += $fwrite) {
            $fwrite = fwrite($fp, substr($string, $written));
            if ($fwrite === false || $numZeros >= $maxZeros) {
                return $written;
            }
            if ($fwrite === 0) {
                $numZeros += 1;
            }
        }
        return $written;
    }
    
    /**
     * Retrieves the configuration of synchronous remote sender,
     * specifically its URL parts.
     *
     * @return array the configuration of synchronous remote sender, with
     *     entries such as 'protocol', 'host', 'port', 'path'.
     */
    private function getRemoteSenderConf($localControllerUrl)
    {
        // "static" to compute only once and return cached value later.
        static $remoteSenderConf = null;
        if ($remoteSenderConf === null) {
            $remoteSenderConf = parse_url($localControllerUrl);
            if (empty($remoteSenderConf['port'])) {
                $remoteSenderConf['port'] =
                    ($remoteSenderConf['scheme'] == 'http') ? 80 : 443;
            }
            $remoteSenderConf['protocol'] =
                ($remoteSenderConf['scheme'] == 'http') ? 'tcp://' : 'ssl://';
        }
        return $remoteSenderConf;
    }

    /**
     * Creates the text of a POST request (headers & contents)
     * carrying some data, targeting the synchronous remote sender
     * PHP script on localhost.
     *
     * Note that we can't use Magento's Varien_Http_Client to construct
     * the request because it doesn't allow for extracting the request
     * text without sending it too, synchronously.
     *
     * @param string $remoteSenderUrlPath the URL path
     *     of the synchronous remote sender.
     * @param string $remoteSenderHost the hostname of localhost.
     *     We can't use "localhost" or "127.0.0.1" in case request
     *     is sent via SSL and the certificate doesn't include these two.
     * @param string $data the data to send.
     * @param int $sequenceNo sequence number of the data being sent,
     *     used for logging purposes.
     * @param string $correlationId the correlation identifier
     *     needed by Cart Defender backend.
     * @return string the text of a POST request, which can be passed to fwrite.
     */
    private function createRequest(
        $remoteSenderUrlPath,
        $remoteSenderHost,
        $data,
        $sequenceNo,
        $correlationId,
        $sendKey
    ) {
        $queryParams = array(
            'sequence_no' => $sequenceNo,
            'is_local_request' => true, // PHP to PHP call within current server
            'correlation_id' => $correlationId,
            'send_key' => $sendKey,
            'data' => $data
        );
        $postdata = http_build_query($queryParams);

        // Note that Carriage Return (\r) characters below are in accordance
        // with RFC 2616. See http://stackoverflow.com/questions/5757290 Also,
        // note that double quotes let \r & \n be interpreted correctly.
        $request = "";
        $request.= "POST " . $remoteSenderUrlPath . " HTTP/1.1\r\n";
        $request.= "Host: " . $remoteSenderHost . "\r\n";
        $request.= "Content-Type: application/x-www-form-urlencoded; charset=UTF-8\r\n";
        $request.= "Content-Length: " . strlen($postdata) . "\r\n";
        $request.= "\r\n";
        $request.= $postdata;
        $request.= "\r\n\r\n";

        return $request;
    }
    
    /**
     * Returns the URL to which the data should be sent by this script.
     *
     * @param array $settings Cart Defender configuration settings.
     * @return string URL to which the data should be sent by this script.
     */
    private function getUrl($correlationId, $settings)
    {
        $path = CDData::CD_PLUGIN_BIZ_API_PATH_START
        . '/' . $correlationId . '/'
                . CDData::CD_PLUGIN_BIZ_API_VERSION
                . CDData::CD_PLUGIN_BIZ_API_PATH_END;
                $useRawTestUrl = $settings['use_raw_test_url_for_biz_api'];
                return $settings['test'] ? ($settings['test_server_url_start']
                        . ($useRawTestUrl ? '' : $path)) : (CDData::CD_HOST . $path);
    }
}
