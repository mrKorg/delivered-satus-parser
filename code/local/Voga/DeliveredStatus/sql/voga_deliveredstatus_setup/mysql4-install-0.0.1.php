<?php

$installer = $this;
$connection = $installer->getConnection();

// Required tables
$statusTable = $installer->getTable('sales/order_status');

$sql  = "SELECT status FROM {$statusTable} WHERE `status`='" . Voga_DeliveredStatus_Model_Sales_Order::STATUS_DELIVERED . "'";
$rows = $connection->fetchAll($sql);

if (count($rows) == 0) {

    // Insert statuses
    $installer->getConnection()->insertArray(
        $statusTable,
        array(
            'status',
            'label'
        ),
        array(
            array('status' => Voga_DeliveredStatus_Model_Sales_Order::STATUS_DELIVERED, 'label' => Voga_Warehouse_Helper_Data::ITEM_DELIVERED_STATUS),
        )
    );

}
