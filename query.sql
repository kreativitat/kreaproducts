the objective is to get a table with the stock movements in a given period by company, fk_entrepot grouped by origintype 
(invoice_supplier, facture, inventory) 

for example:

llxnm_product.ref   llxnm_product.label llxnm_entity.label  llxnm_entrepot.ref   llxnm_stock_mouvement.origintype(group)   llxnm_stock_mouvement.datem(group)     llxnm_stock_mouvement.value(sum) Calculated_stock
703                 patty carne         DG01                DG01                 facture                                   2025-04-01                             6                                23

the Calculated_stock is the llxnm_product_stock.reel + llxnm_stock_mouvement.value the stock of the last movment is llxnm_product_stock.reel, than it must operate all movements to get the value in each line


llxnm_entity.rowid = llxnm_entrepot.entity
llxnm_entrepot.rowid = llxnm_stock_mouvement.fk_entrepot
llxnm_product_stock.fk_product = llxnm_stock_mouvement.fk_product
llxnm_product_stock.fk_entrepot = llxnm_stock_mouvement.fk_entrepot
llxnm_product.rowid = llxnm_stock_mouvement.fk_product

CREATE TABLE `llxnm_entity` (
  `rowid` int(11) NOT NULL,
  `label` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `tms` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `datec` datetime DEFAULT NULL,
  `fk_user_creat` int(11) DEFAULT NULL,
  `options` text DEFAULT NULL,
  `visible` tinyint(4) NOT NULL DEFAULT 1,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `rang` smallint(6) NOT NULL DEFAULT 0
)

CREATE TABLE `llxnm_product_stock` (
  `rowid` int(11) NOT NULL,
  `tms` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fk_product` int(11) NOT NULL,
  `fk_entrepot` int(11) NOT NULL,
  `reel` double DEFAULT NULL,
  `import_key` varchar(14) DEFAULT NULL
)

CREATE TABLE `llxnm_entrepot` (
  `rowid` int(11) NOT NULL,
  `ref` varchar(255) NOT NULL,
  `datec` datetime DEFAULT NULL,
  `tms` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `entity` int(11) NOT NULL DEFAULT 1,
  `fk_project` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `lieu` varchar(64) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `zip` varchar(10) DEFAULT NULL,
  `town` varchar(50) DEFAULT NULL,
  `fk_departement` int(11) DEFAULT NULL,
  `fk_pays` int(11) DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL,
  `fax` varchar(20) DEFAULT NULL,
  `statut` tinyint(4) DEFAULT 1,
  `fk_user_author` int(11) DEFAULT NULL,
  `model_pdf` varchar(255) DEFAULT NULL,
  `import_key` varchar(14) DEFAULT NULL,
  `fk_parent` int(11) DEFAULT 0,
  `warehouse_usage` int(11) DEFAULT 1,
  `barcode` varchar(180) DEFAULT NULL,
  `fk_barcode_type` int(11) DEFAULT NULL
)

CREATE TABLE `llxnm_stock_mouvement` (
  `rowid` int(11) NOT NULL,
  `tms` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `datem` datetime DEFAULT NULL,
  `fk_product` int(11) NOT NULL,
  `batch` varchar(128) DEFAULT NULL,
  `eatby` date DEFAULT NULL,
  `sellby` date DEFAULT NULL,
  `fk_entrepot` int(11) NOT NULL,
  `value` double DEFAULT NULL,
  `price` double(24,8) DEFAULT 0.00000000,
  `type_mouvement` smallint(6) DEFAULT NULL,
  `fk_user_author` int(11) DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `inventorycode` varchar(128) DEFAULT NULL,
  `fk_project` int(11) DEFAULT NULL,
  `fk_origin` int(11) DEFAULT NULL,
  `origintype` varchar(64) DEFAULT NULL,
  `model_pdf` varchar(255) DEFAULT NULL,
  `fk_projet` int(11) NOT NULL DEFAULT 0
)


CREATE TABLE `llxnm_product` (
  `rowid` int(11) NOT NULL,
  `ref` varchar(128) NOT NULL,
  `entity` int(11) NOT NULL DEFAULT 1,
  `ref_ext` varchar(128) DEFAULT NULL,
  `datec` datetime DEFAULT NULL,
  `tms` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fk_parent` int(11) DEFAULT 0,
  `label` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `note_public` mediumtext DEFAULT NULL,
  `note` mediumtext DEFAULT NULL,
  `customcode` varchar(32) DEFAULT NULL,
  `fk_country` int(11) DEFAULT NULL,
  `fk_state` int(11) DEFAULT NULL,
  `price` double(24,8) DEFAULT 0.00000000,
  `price_ttc` double(24,8) DEFAULT 0.00000000,
  `price_min` double(24,8) DEFAULT 0.00000000,
  `price_min_ttc` double(24,8) DEFAULT 0.00000000,
  `price_base_type` varchar(3) DEFAULT 'HT',
  `price_label` varchar(255) DEFAULT NULL,
  `cost_price` double(24,8) DEFAULT NULL,
  `default_vat_code` varchar(10) DEFAULT NULL,
  `tva_tx` double(6,3) DEFAULT NULL,
  `recuperableonly` int(11) NOT NULL DEFAULT 0,
  `localtax1_tx` double(6,3) DEFAULT 0.000,
  `localtax1_type` varchar(10) NOT NULL DEFAULT '0',
  `localtax2_tx` double(6,3) DEFAULT 0.000,
  `localtax2_type` varchar(10) NOT NULL DEFAULT '0',
  `fk_user_author` int(11) DEFAULT NULL,
  `fk_user_modif` int(11) DEFAULT NULL,
  `tosell` tinyint(4) DEFAULT 1,
  `tobuy` tinyint(4) DEFAULT 1,
  `tobatch` tinyint(4) NOT NULL DEFAULT 0,
  `sell_or_eat_by_mandatory` tinyint(4) NOT NULL DEFAULT 0,
  `fk_product_type` int(11) DEFAULT 0,
  `duration` varchar(6) DEFAULT NULL,
  `seuil_stock_alerte` float DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `barcode` varchar(180) DEFAULT NULL,
  `fk_barcode_type` int(11) DEFAULT NULL,
  `accountancy_code_sell` varchar(32) DEFAULT NULL,
  `accountancy_code_sell_intra` varchar(32) DEFAULT NULL,
  `accountancy_code_sell_export` varchar(32) DEFAULT NULL,
  `accountancy_code_buy` varchar(32) DEFAULT NULL,
  `accountancy_code_buy_intra` varchar(32) DEFAULT NULL,
  `accountancy_code_buy_export` varchar(32) DEFAULT NULL,
  `partnumber` varchar(32) DEFAULT NULL,
  `net_measure` float DEFAULT NULL,
  `net_measure_units` tinyint(4) DEFAULT NULL,
  `weight` float DEFAULT NULL,
  `weight_units` tinyint(4) DEFAULT NULL,
  `length` float DEFAULT NULL,
  `length_units` tinyint(4) DEFAULT NULL,
  `width` float DEFAULT NULL,
  `width_units` tinyint(4) DEFAULT NULL,
  `height` float DEFAULT NULL,
  `height_units` tinyint(4) DEFAULT NULL,
  `surface` float DEFAULT NULL,
  `surface_units` tinyint(4) DEFAULT NULL,
  `volume` float DEFAULT NULL,
  `volume_units` tinyint(4) DEFAULT NULL,
  `stock` double DEFAULT NULL,
  `pmp` double(24,8) NOT NULL DEFAULT 0.00000000,
  `fifo` double(24,8) DEFAULT NULL,
  `lifo` double(24,8) DEFAULT NULL,
  `fk_default_warehouse` int(11) DEFAULT NULL,
  `canvas` varchar(32) DEFAULT NULL,
  `finished` tinyint(4) DEFAULT NULL,
  `hidden` tinyint(4) DEFAULT 0,
  `import_key` varchar(14) DEFAULT NULL,
  `model_pdf` varchar(255) DEFAULT NULL,
  `fk_price_expression` int(11) DEFAULT NULL,
  `desiredstock` float DEFAULT NULL,
  `fk_unit` int(11) DEFAULT NULL,
  `price_autogen` tinyint(4) DEFAULT 0,
  `fk_project` int(11) DEFAULT NULL,
  `batch_mask` varchar(32) DEFAULT NULL,
  `lifetime` int(11) DEFAULT NULL,
  `qc_frequency` int(11) DEFAULT NULL,
  `mandatory_period` tinyint(4) DEFAULT 0,
  `fk_default_bom` int(11) DEFAULT NULL,
  `stock_available` int(10) DEFAULT NULL,
  `fk_default_workstation` int(11) DEFAULT NULL,
  `stockable_product` int(11) NOT NULL DEFAULT 1,
  `last_main_doc` varchar(255) DEFAULT NULL,
  `packaging` float(24,8) DEFAULT NULL
)