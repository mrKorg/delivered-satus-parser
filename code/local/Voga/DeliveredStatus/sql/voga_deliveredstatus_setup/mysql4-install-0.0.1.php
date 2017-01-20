<?php

$installer = $this;
$connection = $installer->getConnection();

// Required tables
$statusTable = $installer->getTable('sales/order_status');

$sql  = "SELECT status FROM {$statusTable} WHERE `status`='delivered'";
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
            array('status' => Voga_DeliveredStatus_Model_Sales_Order::STATUS_DELIVERED, 'label' => 'Delivered'),
        )
    );

}
