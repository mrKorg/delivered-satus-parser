<?php

class Voga_DeliveredStatus_Model_Sales_Order extends Mage_Sales_Model_Order
{

    const STATUS_DELIVERED = 'delivered';

    /**
     * Order state protected setter.
     * By default allows to set any state. Can also update status to default or specified value
     * Сomplete and closed states are encapsulated intentionally, see the _checkState()
     *
     * @param string $state
     * @param string|bool $status
     * @param string $comment
     * @param bool $isCustomerNotified
     * @param $shouldProtectState
     * @return Mage_Sales_Model_Order
     */
    protected function _setState($state, $status = false, $comment = '', $isCustomerNotified = null, $shouldProtectState = false)
    {
        // attempt to set the specified state
        if ($shouldProtectState) {
            if ($this->isStateProtected($state)) {
                Mage::throwException(
                    Mage::helper('sales')->__('The Order State "%s" must not be set manually.', $state)
                );
            }
        }

        if ($this->getStatus() == self::STATUS_DELIVERED && $this->getState() == Mage_Sales_Model_Order::STATE_PROCESSING
            && ($state == Mage_Sales_Model_Order::STATE_COMPLETE || $state == Mage_Sales_Model_Order::STATE_PROCESSING) )  {
            $_isStatusDelivered = true;
        }
        $this->setData('state', $state);

        // add status history
        if ($status) {
            if ($status === true) {
                $status = $this->getConfig()->getStateDefaultStatus($state);
            }
            if (isset($_isStatusDelivered)) {
                $this->setStatus(self::STATUS_DELIVERED);
            } else {
                $this->setStatus($status);
            }
            $history = $this->addStatusHistoryComment($comment, false); // no sense to set $status again
            $history->setIsCustomerNotified($isCustomerNotified); // for backwards compatibility
        }
        return $this;
    }
}
