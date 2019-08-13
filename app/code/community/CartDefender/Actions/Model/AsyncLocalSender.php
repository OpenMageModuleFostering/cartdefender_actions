<?php

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
    public function send($data, $sequenceNo, $correlationId)
    {
        $remoteSenderConf = $this->getRemoteSenderConf();
        $request = $this->createRequest(
            $remoteSenderConf['path'],
            $remoteSenderConf['host'],
            $data,
            $sequenceNo,
            $correlationId
        );
        $socket = fsockopen(
            $remoteSenderConf['protocol'] . $remoteSenderConf['host'],
            $remoteSenderConf['port'],
            $errno,
            $errstr,
            0.2 /*timeout*/
        );
        $success = fwrite($socket, $request);
        Mage::getSingleton('core/session')->setLastEventTime(time());
        $this->logger->log('AsyncLocalSender->send', ($success ? 'Success.'
            : ('Error (num: [' . $errno . '], msg: [' . $errstr . '])'))
            . ' Data: [' . $data . ']');
        fclose($socket);
    }

    /**
     * Retrieves the configuration of synchronous remote sender,
     * specifically its URL parts.
     *
     * @return array the configuration of synchronous remote sender, with
     *     entries such as 'protocol', 'host', 'port', 'path'.
     */
    private function getRemoteSenderConf()
    {
        // "static" to compute only once and return cached value later.
        static $remoteSenderConf = null;
        if ($remoteSenderConf === null) {
            $remoteSenderConf = parse_url(Mage::getUrl(
                'cartdefender/CartDefenderSender/send',
                array('_secure' => true)
            ));
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
        $correlationId
    ) {
        $settings = Mage::helper('actions')->getSettings();
        $queryParams = array(
            'sequence_no' => $sequenceNo,
            'data' => $data,
            'is_local_request' => true, // PHP to PHP call within current server
            'correlation_id' => $correlationId,
            'send_key' => $settings['send_key']
        );
        $postdata = http_build_query($queryParams);

        // Note that Carriage Return (\r) characters below are in accordance
        // with RFC 2616. See http://stackoverflow.com/questions/5757290 Also,
        // note that double quotes let \r & \n be interpreted correctly.

        $request = "";
        $request.= "POST " . $remoteSenderUrlPath . " HTTP/1.1\r\n";
        $request.= "Host: " . $remoteSenderHost . "\r\n";
        $request.= "Content-type: application/x-www-form-urlencoded\r\n";
        $request.= "Content-length: " . strlen($postdata) . "\r\n";
        $request.= "\r\n";
        $request.= $postdata;
        $request.= "\r\n\r\n";

        return $request;
    }
}
