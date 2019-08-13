<?php

class CartDefender_Actions_Model_System_Config_Source_Transport
{
    const ASYNC_SOCKET = 'Async PHP script';
    const CURL_PROCESS = 'Async CURL process';
    const SYNC_HTTP = 'Sync HTTP client';
    
   /**
    * Returns the value for transport mode selection in the admin config panel.
    */
    public function toOptionArray()
    {
        return array(
            array('value'=>self::ASYNC_SOCKET, 'label'=>self::ASYNC_SOCKET),
            array('value'=>self::CURL_PROCESS, 'label'=>self::CURL_PROCESS),
            array('value'=>self::SYNC_HTTP, 'label'=>self::SYNC_HTTP)
        );
    }
}
