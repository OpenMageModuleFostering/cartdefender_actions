<?php

/**
 * Cart Defender logging helper. Currently, our logging is enabled if
 * the extension is running in test mode.
 *
 * @package     CartDefender_Actions
 * @author      Heptium Ltd.
 * @copyright   Copyright (c) 2016 Heptium Ltd. (http://www.cartdefender.com/)
 * @license     Open Software License
 */
class CartDefender_Actions_Helper_Logger extends Mage_Core_Helper_Abstract
{

    /** @var mixed $enabled Whether Cart Defender specific logging is enabled. */
    private $enabled = null;

    /**
     * Logs a message if Cart Defender logging is enabled.
     *
     * @param string $function the function name calling the logger.
     * @param string $message the message to log.
     * @return void
     */
    public function log($function, $message = '')
    {
        if ($this->enabled === null) {
            $settings = Mage::helper('actions')->getSettings();
            $this->enabled = ($settings ? $settings['test'] : null);
        }
        if ($this->enabled) {
            Mage::log('[CD] [' . $function . '] ' . $message, null, 'cartdefender.log');
        }
    }
}
