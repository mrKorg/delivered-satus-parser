<?php

class Voga_DeliveredStatus_Model_Cron
{
    const PINNUMBER1     = 'SH005';
    const PINNUMBER2     = 'SH006';
    const EMAIL_SENDER   = 'voga_deliveredstatus/deliveredstatus_group/email_field';
    const EMAIL_TEMPLATE = 'voga_deliveredstatus/deliveredstatus_group/email_template';
    const EMAIL_ADDRESS  = 'voga_deliveredstatus/deliveredstatus_group/email_address';
    const DELIVERED_LOG  = 'delivered_status.log';

    protected $_pathToXml;
    protected $_pathToXmlArchive;

    function __construct()
    {
        $this->_pathToXml        = Mage::getBaseDir('var') . DS .'aramex' . DS . 'raw_data' . DS;
        $this->_pathToXmlArchive = Mage::getBaseDir('var') . DS .'aramex' . DS . 'raw_data_archive' . DS;
    }

    protected function _getPathToXml($file = NULL)
    {
        if ($file) {
            return $this->_pathToXml . $file;
        }
        return $this->_pathToXml;
    }

    protected function _getPathToXmlArchive()
    {
        return $this->_pathToXmlArchive;
    }

    public function parseXmlFiles()
    {
        $files = $this->_getAllXmlFiles();
        if (is_array($files)) {
            $deliveredHawbNumbers = $this->_getDeliveredHawbNumbers($files);
            $realHawbNumbers = $this->_setDeliveredStatus($deliveredHawbNumbers);
            $this->_moveXmlFiles($files);
            $this->_diffHawbNumbers(array_keys($deliveredHawbNumbers), $realHawbNumbers);
        }
    }

    /**
     * Set delivered status
     */
    protected function _setDeliveredStatus($deliveredHawbNumbers)
    {
        $realHawbNumbers = array();
        $numbers = array_keys($deliveredHawbNumbers);

        if (count($deliveredHawbNumbers)) {
            $orderCollection = Mage::getModel('sales/order')
                ->getCollection()
                ->addAttributeToSelect('entity_id')
                ->addAttributeToSelect('status')
                ->addAttributeToSelect('state')
                ->addAttributeToSelect('hawb_number')
                ->addFieldToFilter('hawb_number', array('in'=>$numbers));

            foreach ($orderCollection as $order) {

                $orderHuwNumber = $order->getHawbNumber();
                $realHawbNumbers[] = $orderHuwNumber;
                $orderId = $order->getId();

                try {
                    if ( $order->getState() == Mage_Sales_Model_Order::STATE_COMPLETE || $order->getState() == Mage_Sales_Model_Order::STATE_PROCESSING ) {
                        $order->setStatus( Voga_DeliveredStatus_Model_Sales_Order::STATUS_DELIVERED );
                        $comment = 'Order was set to Delivered by our automation tool.';
                        $order->addStatusHistoryComment($comment, false);
                        $order->save();
                        $orderItems= $order->getAllVisibleItems();

                        foreach ($orderItems as $item) {
                            if ( $item->getSupplierOrderStatus() != Voga_Warehouse_Helper_Data::ITEM_OUT_OF_STOCK_STATUS ) {
                                $item->setSupplierOrderStatus(Voga_Warehouse_Helper_Data::ITEM_DELIVERED_STATUS);
                                $item->save();
                            } else {
                                Mage::log("Order #{$orderId} has item with status 'Out of Stock'", null, $this::DELIVERED_LOG);
                            }
                        }
                        Mage::log("Order #{$orderId} appointed status 'delivered'", null, $this::DELIVERED_LOG);
                    } else {
                        throw new Exception("Order #{$orderId} hasn't state 'completed' or 'processing'");
                    }
                } catch (Exception $e) {
                    $file = $this->_getPathToXml($deliveredHawbNumbers[$orderHuwNumber]);
                    $postObject = new Varien_Object();
                    $postObject->setOrderId($orderId);
                    $postObject->setExceptionTrace(nl2br($e->getTraceAsString()));
                    $this->_sendEmail($postObject, $file);
                    Mage::log($e->getMessage(), null, $this::DELIVERED_LOG);
                }
            }
        }

        return $realHawbNumbers;
    }

    /**
     * Read XML files   
     * Return hawb numbers of orders with delivered status
     * @return array
     */
    protected function _getDeliveredHawbNumbers($files)
    {
        $deliveredHawbNumbers = array();

        foreach ($files as $file) {
            $xmlPath = $this->_getPathToXml($file);
            if (file_exists($xmlPath) && is_readable($xmlPath)) {
                $xmlObj = new Varien_Simplexml_Config($xmlPath);
                $hawbItem = $xmlObj->getNode('HAWBUpdate');
                foreach ($hawbItem as $order) {
                    if ( $order->PINumber == $this::PINNUMBER1 || $order->PINumber == $this::PINNUMBER1) {
                        $deliveredHawbNumbers[(string)$order->HAWBNumber] = $file;
                    }
                }
            }
        }

        return $deliveredHawbNumbers;
    }

    /**
     * Get all xml files
     * @return array|NULL
     */
    protected function _getAllXmlFiles()
    {
        $path = $this->_getPathToXml();
        $files = scandir($path);
        $patternFileName = '/^vogacloset_[0-9.]{0,}\.xml$/i';

        foreach ($files as $key => $file) {
            if ( preg_match($patternFileName , $file) == NULL ) {
                unset($files[$key]);
            }
        }

        if (!count($files)) {
            Mage::log("No files in directory", null, $this::DELIVERED_LOG);
            return false;
        }

        return $files;
    }

    /**
     * Remove xml files
     */
    protected function _moveXmlFiles($files)
    {
        $path = $this->_getPathToXml();
        $pathArchiveFiles = $this->_getPathToXmlArchive();

        if (!file_exists($pathArchiveFiles)) {
            mkdir($pathArchiveFiles, 0777);
        }

        foreach ($files as $file) {
            rename($path . $file, $pathArchiveFiles . $file);
        }
    }

    protected function _sendEmail($postObject, $attachmentFile = null)
    {
        $emailTemplate = Mage::getStoreConfig($this::EMAIL_TEMPLATE); Zend_Debug::dump($emailTemplate);
        $emailSender = Mage::getStoreConfig($this::EMAIL_SENDER); Zend_Debug::dump($emailSender);
        $emailAddress = Mage::getStoreConfig($this::EMAIL_ADDRESS); Zend_Debug::dump($emailAddress);
        if (!empty($emailTemplate) && !empty($emailSender) && !empty($emailAddress)) {
            $mailTemplate = Mage::getModel('core/email_template');
            if ($attachmentFile) {
                $attachmentData = file_get_contents($attachmentFile);
                $mailTemplate
                    ->getMail()->createAttachment(
                        $attachmentData,
                        Zend_Mime::TYPE_OCTETSTREAM,
                        Zend_Mime::DISPOSITION_ATTACHMENT,
                        Zend_Mime::ENCODING_BASE64,
                        basename($attachmentFile)
                    );
            }
            $mailTemplate->setDesignConfig(array('area' => 'frontend'))
                ->sendTransactional(
                    $emailTemplate,
                    $emailSender,
                    $emailAddress,
                    NULL,
                    array('data' => $postObject)
                );
            if (!$mailTemplate->getSentSuccess()) {
                Mage::log("Email don't send", null, $this::DELIVERED_LOG);
            }
        } else {
            Mage::log("Email settings empty", null, $this::DELIVERED_LOG);
        }
    }

    protected function _diffHawbNumbers($deliveredHawbNumbers, $realHawbNumbers)
    {
        $unrealHawbNumbers = array_diff($deliveredHawbNumbers, $realHawbNumbers);
        if (count($unrealHawbNumbers)) {
            Mage::log("Order(s) does not exist with Hawb Number(s) " . print_r($unrealHawbNumbers, true), null, $this::DELIVERED_LOG);
        }
    }

}
