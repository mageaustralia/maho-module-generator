<?php
/* Synthetic fixture: one Ddl_Table-style table + one raw-SQL table. */
$installer = $this;
$installer->startSetup();

$table = $installer->getConnection()
    ->newTable($installer->getTable('gadgets/gadget'))
    ->addColumn('gadget_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Gadget Id')
    ->addColumn('sku', Varien_Db_Ddl_Table::TYPE_TEXT, 64, ['nullable' => false], 'SKU')
    ->addColumn('title', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Title')
    ->addColumn('description', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', [], 'Description')
    ->addColumn('price', Varien_Db_Ddl_Table::TYPE_DECIMAL, '12,4', ['nullable' => false], 'Price')
    ->addColumn('is_active', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['nullable' => false, 'default' => 1], 'Active')
    ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, [], 'Created At');
$installer->getConnection()->createTable($table);

$installer->run("
CREATE TABLE IF NOT EXISTS `acme_gadget_log` (
  `log_id` int(10) unsigned NOT NULL auto_increment,
  `gadget_id` int(10) unsigned NOT NULL,
  `event` varchar(32) NOT NULL,
  `detail` text,
  `logged_at` datetime NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `IDX_GADGET` (`gadget_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");

$installer->endSetup();
