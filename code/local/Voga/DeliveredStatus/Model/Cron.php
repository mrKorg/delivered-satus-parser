<?php

class Voga_DeliveredStatus_Model_Cron
{
    const PINNUMBER1     = 'SH005';
    const PINNUMBER2     = 'SH006';
    const EMAIL_SENDER   = 'voga_deliveredstatus/deliveredstatus_group/email_field';
    const EMAIL_TEMPLATE = 'voga_deliveredstatus/deliveredstatus_group/email_template';
    const EMAIL_ADDRESS  = 'voga_deliveredstatus/deliveredstatus_group/email_address';

    protected $pathToXml;
    protected $pathToXmlArchive;

    function __construct()
    {
        $this->pathToXml        = Mage::getBaseDir('var') . DS .'aramex' . DS . 'raw_data' . DS;
        $this->pathToXmlArchive = Mage::getBaseDir('var') . DS .'aramex' . DS . 'raw_data_archive' . DS;
    }

    protected function _getPathToXml($file = NULL)
    {
        if ($file) {
            return $this->pathToXml . $file;
        }
        return $this->pathToXml;
    }

    protected function _getPathToXmlArchive()
    {
        return $this->pathToXmlArchive;
    }

    public function parseXmlFiles()
    {
        $files = $this->_getAllXmlFiles();
        $deliveredHawbNumbers = $this->_getDeliveredHawbNumbers($files);
        $realHawbNumbers = $this->_setDeliveredStatus($deliveredHawbNumbers);
        $this->_moveXmlFiles($files);
        $this->_diffHawbNumbers($deliveredHawbNumbers, $realHawbNumbers);
    }

    /**
     * Set delivered status
     */
    protected function _setDeliveredStatus($deliveredHawbNumbers)
    {
        $orderCollection = Mage::getModel('sales/order')
            ->getCollection()
            ->addAttributeToSelect('entity_id')
            ->addAttributeToSelect('status')
            ->addAttributeToSelect('state')
            ->addAttributeToSelect('hawb_number')
            ->addFieldToFilter('hawb_number', array('in'=>$deliveredHawbNumbers));

        $realHawbNumbers = array();

        foreach ($orderCollection as $order) {

            $realHawbNumbers[] = $order->getHawbNumber();

            if ( $order->getState() == Mage_Sales_Model_Order::STATE_COMPLETE ) {

                try {
                    $order->setStatus( Voga_Warehouse_Helper_Data::ITEM_DELIVERED_STATUS );
                    $comment = 'Order was set to Delivered by our automation tool.';
                    $history = $order->addStatusHistoryComment($comment, false);
                    $history->setIsCustomerNotified($isCustomerNotified);
                    $order->save();

                    $orderItemsCollection = Mage::getResourceModel('sales/order_item_collection');
                    $orderItemsCollection->addAttributeToSelect('item_id')
                        ->addAttributeToSelect('order_id')
                        ->addAttributeToSelect('supplier_order_status')
                        ->addFieldToFilter('order_id', $order->getEntityId());

                    foreach ($orderItemsCollection->getItems() as $item) {
                        $productId = $item->getProductId();
                        $product = Mage::getModel('catalog/product')->load($productId);
                        if ( $product->getStockItem()->getIsInStock() ) {
                            $item->setSupplierOrderStatus(Voga_Warehouse_Helper_Data::ITEM_DELIVERED_STATUS);
                            $item->save();
                        }
                    }
                } catch (Exception $e) {
                    Mage::logException($e);
                }
                
            } else {
                $id = $order->getId();
                Mage::log("Order #{$id} hasn't status 'completed'", null, 'delivered_status.log');
                $postObject = new Varien_Object();
                $postObject->setOrderId($id);
                $this->_sendEmail($postObject);
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
        $hawbArray = array();
        $deliveredHawbNumbers = array();

        foreach ($files as $file) {
            $xmlPath = $this->_getPathToXml($file);
            if (file_exists($xmlPath) && is_readable($xmlPath)) {
                $xmlObj = new Varien_Simplexml_Config($xmlPath);
                $hawbArray[] = $xmlObj->getNode('HAWBUpdate');
            }
        }

        foreach ($hawbArray as $hawbItem) {
            foreach ($hawbItem as $order) {
                if ( $order->PINumber == $this::PINNUMBER1 || $order->PINumber == $this::PINNUMBER1) {
                    $deliveredHawbNumbers[] = (string)$order->HAWBNumber;
                }
            }
        }

        return $deliveredHawbNumbers;
    }

    /**
     * Get all xml files
     * @return array
     */
    protected function _getAllXmlFiles()
    {
        $path = $this->_getPathToXml();
        $files = scandir($path);
        $patternFileName = '/^vogacloset_[0-9.]{0,}\.xml$/';

        foreach ($files as $key => $file) {
            if ( preg_match($patternFileName , strtolower($file)) == NULL ) {
                unset($files[$key]);
            }
        }

        if (!count($files)) {
            Mage::log("No files in directory", null, 'delivered_status.log');
            return ;
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

    protected function _sendEmail($postObject)
    {
        $mailTemplate = Mage::getModel('core/email_template');
        $mailTemplate->setDesignConfig(array('area' => 'frontend'))
            ->sendTransactional(
                Mage::getStoreConfig(self::EMAIL_TEMPLATE),
                Mage::getStoreConfig(self::EMAIL_SENDER),
                Mage::getStoreConfig(self::EMAIL_ADDRESS),
                NULL,
                array('data' => $postObject)
            );

        if (!$mailTemplate->getSentSuccess()) {
            Mage::log("Email don't send", null, 'delivered_status.log');
        }

    }

    protected function _diffHawbNumbers($deliveredHawbNumbers, $realHawbNumbers)
    {
        $unrealHawbNumbers = array_diff($deliveredHawbNumbers, $realHawbNumbers);
        foreach ($unrealHawbNumbers as $hawbNumber) {
            Mage::log("Order with Hawb Number #{$hawbNumber} does not exist", null, 'delivered_status.log');
        }
    }

}
