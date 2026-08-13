<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Commercial support and integration services are available from
 * Kreativität Works <mail@kreativitat.com>.
 */

/**
 * \file       htdocs/custom/kreaproducts/class/KreaProductsProductStatistics.class.php
 * \ingroup    kreaproducts
 * \brief      Entity-safe commercial and operational product statistics.
 */

/**
 * Read-only statistics service for one product or service.
 */
class KreaProductsProductStatistics
{
	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Load commercial and operational statistics over a current and comparison period.
	 *
	 * @param int  $productId             Product identifier
	 * @param int  $periodStart           Current period start timestamp
	 * @param int  $periodEndExclusive    Current period exclusive end timestamp
	 * @param int  $comparisonStart       Comparison period start timestamp
	 * @param int  $comparisonEndExclusive Comparison period exclusive end timestamp
	 * @param User $user                  Current user
	 * @param bool $loadSales             Load customer invoice statistics
	 * @param bool $loadPurchases         Load supplier invoice statistics
	 * @param bool $loadMargin            Load invoice-line cost and margin data
	 * @param bool $loadOperations        Load stock movement and product relation statistics
	 * @return array<string,mixed>|int<-1,-1> Statistics array, or -1 on error
	 */
	public function load($productId, $periodStart, $periodEndExclusive, $comparisonStart, $comparisonEndExclusive, $user, $loadSales, $loadPurchases, $loadMargin, $loadOperations = false)
	{
		$result = array(
			'sales' => $this->emptyFlow(),
			'purchases' => $this->emptyFlow(),
			'operations' => $this->emptyOperationalFlow(),
		);

		if ($loadSales) {
			$sales = $this->loadSales(
				$productId,
				$periodStart,
				$periodEndExclusive,
				$comparisonStart,
				$comparisonEndExclusive,
				$user,
				$loadMargin
			);
			if ($sales === -1) {
				return -1;
			}
			$result['sales'] = $sales;
		}

		if ($loadPurchases) {
			$purchases = $this->loadPurchases(
				$productId,
				$periodStart,
				$periodEndExclusive,
				$comparisonStart,
				$comparisonEndExclusive,
				$user
			);
			if ($purchases === -1) {
				return -1;
			}
			$result['purchases'] = $purchases;
		}

		if ($loadOperations) {
			$operations = $this->loadOperations(
				$productId,
				$periodStart,
				$periodEndExclusive,
				$comparisonStart,
				$comparisonEndExclusive
			);
			if ($operations === -1) {
				return -1;
			}
			$result['operations'] = $operations;
		}

		return $result;
	}

	/**
	 * Load signed operational movement flows and product usage relationships.
	 *
	 * Inventory movements are retained as a separate adjustment measure and are
	 * never mixed into production, demand, or operational net flow.
	 *
	 * @param int $productId Product identifier
	 * @param int $periodStart Current period start timestamp
	 * @param int $periodEndExclusive Current period exclusive end timestamp
	 * @param int $comparisonStart Comparison period start timestamp
	 * @param int $comparisonEndExclusive Comparison period exclusive end timestamp
	 * @return array<string,mixed>|int<-1,-1>
	 */
	private function loadOperations($productId, $periodStart, $periodEndExclusive, $comparisonStart, $comparisonEndExclusive)
	{
		$warehouseIds = $this->loadVisibleWarehouseIds();
		if ($warehouseIds === -1) {
			return -1;
		}

		$operations = $this->emptyOperationalFlow();
		if (empty($warehouseIds)) {
			return $operations;
		}
		$warehouseIdList = implode(',', $warehouseIds);

		$positiveQty = $this->db->ifsql('sm.value > 0', 'sm.value', '0');
		$negativeQty = $this->db->ifsql('sm.value < 0', '-1 * sm.value', '0');
		$positiveMove = $this->db->ifsql('sm.value > 0', '1', '0');
		$negativeMove = $this->db->ifsql('sm.value < 0', '1', '0');
		$positiveSource = $this->db->ifsql('sm.value > 0 AND sm.fk_origin > 0', 'sm.fk_origin', 'NULL');
		$negativeSource = $this->db->ifsql('sm.value < 0 AND sm.fk_origin > 0', 'sm.fk_origin', 'NULL');

		$sql = "SELECT DATE_FORMAT(sm.datem, '%Y-%m-01') AS movement_month, sm.origintype,";
		$sql .= ' SUM('.$positiveQty.') AS qty_in, SUM('.$negativeQty.') AS qty_out,';
		$sql .= ' SUM('.$positiveMove.') AS moves_in, SUM('.$negativeMove.') AS moves_out,';
		$sql .= ' COUNT(DISTINCT '.$positiveSource.') AS sources_in, COUNT(DISTINCT '.$negativeSource.') AS sources_out';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'stock_mouvement AS sm';
		$sql .= ' WHERE sm.fk_product = '.((int) $productId);
		$sql .= ' AND sm.fk_entrepot IN ('.$warehouseIdList.')';
		$sql .= " AND ((sm.datem >= '".$this->db->idate($comparisonStart)."'";
		$sql .= " AND sm.datem < '".$this->db->idate($comparisonEndExclusive)."')";
		$sql .= " OR (sm.datem >= '".$this->db->idate($periodStart)."'";
		$sql .= " AND sm.datem < '".$this->db->idate($periodEndExclusive)."'))";
		$sql .= " GROUP BY DATE_FORMAT(sm.datem, '%Y-%m-01'), sm.origintype";
		$sql .= ' ORDER BY movement_month ASC, sm.origintype ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' operational movement query failed: '.$this->error, LOG_ERR);
			return -1;
		}

		while ($row = $this->db->fetch_object($resql)) {
			$date = $this->db->jdate($row->movement_month);
			if ($date >= $periodStart && $date < $periodEndExclusive) {
				$bucket = 'current';
			} elseif ($date >= $comparisonStart && $date < $comparisonEndExclusive) {
				$bucket = 'previous';
			} else {
				continue;
			}

			$values = array(
				'qty_in' => price2num($row->qty_in, 'MS'),
				'qty_out' => price2num($row->qty_out, 'MS'),
				'moves_in' => (int) $row->moves_in,
				'moves_out' => (int) $row->moves_out,
				'sources_in' => (int) $row->sources_in,
				'sources_out' => (int) $row->sources_out,
			);
			$this->addOperationalGroup($operations[$bucket], (string) $row->origintype, $values);

			if ($bucket === 'current') {
				$monthKey = dol_print_date($date, '%Y-%m');
				if (!isset($operations['monthly'][$monthKey])) {
					$operations['monthly'][$monthKey] = $this->emptyOperationalSummary();
				}
				$this->addOperationalGroup($operations['monthly'][$monthKey], (string) $row->origintype, $values);
			}
		}
		$this->db->free($resql);

		$profile = $this->loadOperationalProfile($productId, $operations['current'], $operations['previous']);
		if ($profile === -1) {
			return -1;
		}
		$operations['profile'] = $profile;

		$recent = $this->loadRecentOperations($productId, $periodStart, $periodEndExclusive, $warehouseIdList);
		if ($recent === -1) {
			return -1;
		}
		$operations['recent'] = $recent;

		return $operations;
	}

	/**
	 * Resolve the warehouses visible through the active stock entity scope.
	 *
	 * Explicit identifiers let MySQL intersect the core product and warehouse
	 * indexes before grouping high-volume stock histories.
	 *
	 * @return array<int,int>|int<-1,-1>
	 */
	private function loadVisibleWarehouseIds()
	{
		$sql = 'SELECT e.rowid FROM '.MAIN_DB_PREFIX.'entrepot AS e';
		$sql .= ' WHERE e.entity IN ('.getEntity('stock').')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' warehouse scope query failed: '.$this->error, LOG_ERR);
			return -1;
		}

		$warehouseIds = array();
		while ($row = $this->db->fetch_object($resql)) {
			$warehouseId = (int) $row->rowid;
			if ($warehouseId > 0) {
				$warehouseIds[$warehouseId] = $warehouseId;
			}
		}
		$this->db->free($resql);

		return array_values($warehouseIds);
	}

	/**
	 * Add one grouped stock origin to an operational summary.
	 *
	 * @param array<string,mixed> $summary Summary bucket
	 * @param string $originType Dolibarr stock movement origin type
	 * @param array<string,mixed> $values Aggregated quantities and counts
	 * @return void
	 */
	private function addOperationalGroup(&$summary, $originType, $values)
	{
		$qtyIn = (float) $values['qty_in'];
		$qtyOut = (float) $values['qty_out'];
		$movesIn = (int) $values['moves_in'];
		$movesOut = (int) $values['moves_out'];
		$sourcesIn = (int) $values['sources_in'];
		$sourcesOut = (int) $values['sources_out'];

		if ($originType === 'inventory') {
			$summary['inventory_net'] += $qtyIn - $qtyOut;
			$summary['inventory_events'] += $movesIn + $movesOut;
			return;
		}

		$summary['inbound'] += $qtyIn;
		$summary['outbound'] += $qtyOut;
		$summary['movements'] += $movesIn + $movesOut;
		if ($originType === 'mo') {
			$summary['produced'] += $qtyIn;
			$summary['production_orders'] += $sourcesIn;
			$summary['manufacturing_usage'] += $qtyOut;
			$summary['manufacturing_orders'] += $sourcesOut;
		} elseif ($originType === 'facture') {
			$summary['customer_returns'] += $qtyIn;
			$summary['customer_usage'] += $qtyOut;
			$summary['customer_documents'] += $sourcesOut;
		} elseif ($originType === 'invoice_supplier') {
			$summary['supplier_receipts'] += $qtyIn;
			$summary['supplier_documents'] += $sourcesIn;
			$summary['supplier_returns'] += $qtyOut;
		} else {
			$summary['other_in'] += $qtyIn;
			$summary['other_out'] += $qtyOut;
		}

		$summary['usage'] = $summary['customer_usage'] + $summary['manufacturing_usage'];
		$summary['operational_net'] = $summary['inbound'] - $summary['outbound'];
	}

	/**
	 * Classify the product's operational role from period activity and product relations.
	 *
	 * @param int $productId Product identifier
	 * @param array<string,mixed> $current Current-period operations
	 * @param array<string,mixed> $previous Comparison-period operations
	 * @return array<string,mixed>|int<-1,-1>
	 */
	private function loadOperationalProfile($productId, $current, $previous)
	{
		$relations = $this->loadProductRelations($productId);
		if ($relations === -1) {
			return -1;
		}
		$hasProductionStructure = $this->hasProductionStructure($productId);
		if ($hasProductionStructure === -1) {
			return -1;
		}

		$producedQty = (float) $current['produced'] + (float) $previous['produced'];
		$usageQty = (float) $current['usage'] + (float) $previous['usage'];
		$supplierReceiptQty = (float) $current['supplier_receipts'] + (float) $previous['supplier_receipts'];

		return array(
			'manufactured' => $hasProductionStructure || $producedQty > 0.0000001,
			'ingredient' => !empty($relations['count']) || $usageQty > 0.0000001,
			'received' => $supplierReceiptQty > 0.0000001,
			'relation_count' => (int) $relations['count'],
			'relations' => $relations['rows'],
		);
	}

	/**
	 * Check whether an active BOM defines the product as a manufacturing parent
	 * or as an output of a dismantling operation.
	 *
	 * @param int $productId Product identifier
	 * @return bool|int<-1,-1>
	 */
	private function hasProductionStructure($productId)
	{
		$sql = 'SELECT b.rowid FROM '.MAIN_DB_PREFIX.'bom_bom AS b';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'bom_bomline AS bl ON bl.fk_bom = b.rowid';
		$sql .= ' WHERE b.status = 1 AND b.entity IN (0,'.getEntity('bom').')';
		$sql .= ' AND ((b.bomtype = 0 AND b.fk_product = '.((int) $productId).')';
		$sql .= ' OR (b.bomtype = 1 AND bl.fk_product = '.((int) $productId).'))';
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' production structure query failed: '.$this->error, LOG_ERR);
			return -1;
		}
		$found = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $found;
	}

	/**
	 * Load products that consume this product through composed-product or active manufacturing BOM relations.
	 *
	 * product_association has no entity column, so the parent product is always
	 * constrained through the active product entity scope.
	 *
	 * @param int $productId Product identifier
	 * @return array<string,mixed>|int<-1,-1>
	 */
	private function loadProductRelations($productId)
	{
		$sql = 'SELECT relation.parent_id, relation.parent_ref, relation.parent_label, relation.qty, relation.source';
		$sql .= ' FROM (';
		$sql .= ' SELECT p.rowid AS parent_id, p.ref AS parent_ref, p.label AS parent_label, pa.qty,\'association\' AS source';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product_association AS pa';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = pa.fk_product_pere';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE pa.fk_product_fils = '.((int) $productId).' AND pa.incdec = 1';
		$sql .= ' UNION ALL';
		$sql .= ' SELECT p.rowid AS parent_id, p.ref AS parent_ref, p.label AS parent_label, bl.qty,\'bom\' AS source';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'bom_bomline AS bl';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bom_bom AS b ON b.rowid = bl.fk_bom';
		$sql .= ' AND b.status = 1 AND b.bomtype = 0 AND b.entity IN (0,'.getEntity('bom').')';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = b.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE bl.fk_product = '.((int) $productId);
		$sql .= ') AS relation ORDER BY relation.parent_ref ASC, relation.parent_id ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' product relation query failed: '.$this->error, LOG_ERR);
			return -1;
		}

		$unique = array();
		while ($row = $this->db->fetch_object($resql)) {
			$parentId = (int) $row->parent_id;
			if (!isset($unique[$parentId])) {
				$unique[$parentId] = array(
					'id' => $parentId,
					'ref' => (string) $row->parent_ref,
					'label' => (string) $row->parent_label,
					'qty' => price2num($row->qty, 'MS'),
					'source' => (string) $row->source,
				);
			}
		}
		$this->db->free($resql);

		return array(
			'count' => count($unique),
			'rows' => array_slice(array_values($unique), 0, 10),
		);
	}

	/**
	 * Load recent stock activity grouped by business origin and warehouse.
	 *
	 * @param int $productId Product identifier
	 * @param int $periodStart Current period start timestamp
	 * @param int $periodEndExclusive Current period exclusive end timestamp
	 * @param string $warehouseIdList Comma-separated visible warehouse identifiers
	 * @return array<int,array<string,mixed>>|int<-1,-1>
	 */
	private function loadRecentOperations($productId, $periodStart, $periodEndExclusive, $warehouseIdList)
	{
		$sourceKey = $this->db->ifsql('sm.fk_origin > 0', 'sm.fk_origin', '-1 * sm.rowid');
		$sql = 'SELECT MAX(sm.rowid) AS rowid, MAX(sm.datem) AS datem, SUM(sm.value) AS qty,';
		$sql .= ' sm.origintype, sm.fk_origin, e.rowid AS warehouse_id, e.ref AS warehouse_ref,';
		$sql .= ' MAX(sm.label) AS label, COUNT(sm.rowid) AS movement_count';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'stock_mouvement AS sm';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'entrepot AS e ON e.rowid = sm.fk_entrepot';
		$sql .= ' WHERE sm.fk_product = '.((int) $productId);
		$sql .= ' AND sm.fk_entrepot IN ('.$warehouseIdList.')';
		$sql .= " AND sm.datem >= '".$this->db->idate($periodStart)."'";
		$sql .= " AND sm.datem < '".$this->db->idate($periodEndExclusive)."'";
		$sql .= ' GROUP BY sm.origintype, '.$sourceKey.', sm.fk_origin, e.rowid, e.ref';
		$sql .= ' ORDER BY datem DESC, rowid DESC';
		$sql .= $this->db->plimit(12);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' recent operational movement query failed: '.$this->error, LOG_ERR);
			return -1;
		}

		$rows = array();
		while ($row = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'id' => (int) $row->rowid,
				'date' => $this->db->jdate($row->datem),
				'qty' => price2num($row->qty, 'MS'),
				'origin_type' => (string) $row->origintype,
				'origin_id' => (int) $row->fk_origin,
				'warehouse_id' => (int) $row->warehouse_id,
				'warehouse_ref' => (string) $row->warehouse_ref,
				'label' => (string) $row->label,
				'movement_count' => (int) $row->movement_count,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Load customer invoice statistics.
	 *
	 * @param int  $productId              Product identifier
	 * @param int  $periodStart            Current period start timestamp
	 * @param int  $periodEndExclusive     Current period exclusive end timestamp
	 * @param int  $comparisonStart        Comparison period start timestamp
	 * @param int  $comparisonEndExclusive Comparison period exclusive end timestamp
	 * @param User $user                   Current user
	 * @param bool $loadMargin             Load protected margin data
	 * @return array<string,mixed>|int<-1,-1>
	 */
	private function loadSales($productId, $periodStart, $periodEndExclusive, $comparisonStart, $comparisonEndExclusive, $user, $loadMargin)
	{
		$creditQuantity = $this->db->ifsql('f.type = 2', '-1 * d.qty', 'd.qty');
		$costSignCondition = '(d.total_ht < 0 OR (d.total_ht = 0 AND f.type = 2))';
		$situationPercent = $this->db->ifsql('d.situation_percent IS NULL', '100', 'd.situation_percent');
		$costExpression = 'd.qty * d.buy_price_ht * ('.$situationPercent.' / 100)';
		$signedCost = $this->db->ifsql($costSignCondition, '-1 * '.$costExpression, $costExpression);
		$costedAmount = $this->db->ifsql('d.buy_price_ht IS NULL', '0', 'd.total_ht');
		$missingCostLine = $this->db->ifsql('d.buy_price_ht IS NULL', '1', '0');

		$sql = 'SELECT f.rowid, f.ref, f.datef, f.fk_soc, f.type, s.nom AS thirdparty_name,';
		$sql .= ' SUM(d.total_ht) AS amount, SUM('.$creditQuantity.') AS qty';
		if ($loadMargin) {
			$sql .= ', SUM('.$signedCost.') AS cost_amount';
			$sql .= ', SUM('.$costedAmount.') AS costed_amount';
			$sql .= ', SUM('.$missingCostLine.') AS missing_cost_lines';
		}
		$sql .= ' FROM '.MAIN_DB_PREFIX.'facture AS f';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facturedet AS d ON d.fk_facture = f.rowid';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = f.fk_soc';
		$sql .= ' WHERE f.entity IN ('.getEntity('invoice').')';
		$sql .= ' AND f.fk_statut IN (1, 2)';
		$sql .= ' AND d.fk_product = '.((int) $productId);
		$sql .= " AND ((f.datef >= '".$this->db->idate($comparisonStart)."'";
		$sql .= " AND f.datef < '".$this->db->idate($comparisonEndExclusive)."')";
		$sql .= " OR (f.datef >= '".$this->db->idate($periodStart)."'";
		$sql .= " AND f.datef < '".$this->db->idate($periodEndExclusive)."'))";
		$sql .= $this->thirdpartyRestrictionSql($user, 'f');
		$sql .= ' GROUP BY f.rowid, f.ref, f.datef, f.fk_soc, f.type, s.nom';
		$sql .= ' ORDER BY f.datef DESC, f.rowid DESC';

		return $this->executeFlowQuery(
			$sql,
			$periodStart,
			$periodEndExclusive,
			$comparisonStart,
			$comparisonEndExclusive,
			$loadMargin,
			'customer'
		);
	}

	/**
	 * Load supplier invoice statistics.
	 *
	 * @param int  $productId              Product identifier
	 * @param int  $periodStart            Current period start timestamp
	 * @param int  $periodEndExclusive     Current period exclusive end timestamp
	 * @param int  $comparisonStart        Comparison period start timestamp
	 * @param int  $comparisonEndExclusive Comparison period exclusive end timestamp
	 * @param User $user                   Current user
	 * @return array<string,mixed>|int<-1,-1>
	 */
	private function loadPurchases($productId, $periodStart, $periodEndExclusive, $comparisonStart, $comparisonEndExclusive, $user)
	{
		$creditQuantity = $this->db->ifsql('f.type = 2', '-1 * d.qty', 'd.qty');

		$sql = 'SELECT f.rowid, f.ref, f.ref_supplier, f.datef, f.fk_soc, f.type, s.nom AS thirdparty_name,';
		$sql .= ' SUM(d.total_ht) AS amount, SUM('.$creditQuantity.') AS qty';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'facture_fourn AS f';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture_fourn_det AS d ON d.fk_facture_fourn = f.rowid';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = f.fk_soc';
		$sql .= ' WHERE f.entity IN ('.getEntity('facture_fourn').')';
		$sql .= ' AND f.fk_statut IN (1, 2)';
		$sql .= ' AND d.fk_product = '.((int) $productId);
		$sql .= " AND ((f.datef >= '".$this->db->idate($comparisonStart)."'";
		$sql .= " AND f.datef < '".$this->db->idate($comparisonEndExclusive)."')";
		$sql .= " OR (f.datef >= '".$this->db->idate($periodStart)."'";
		$sql .= " AND f.datef < '".$this->db->idate($periodEndExclusive)."'))";
		$sql .= $this->thirdpartyRestrictionSql($user, 'f');
		$sql .= ' GROUP BY f.rowid, f.ref, f.ref_supplier, f.datef, f.fk_soc, f.type, s.nom';
		$sql .= ' ORDER BY f.datef DESC, f.rowid DESC';

		return $this->executeFlowQuery(
			$sql,
			$periodStart,
			$periodEndExclusive,
			$comparisonStart,
			$comparisonEndExclusive,
			false,
			'supplier'
		);
	}

	/**
	 * Apply external-user and commercial-assignment restrictions.
	 *
	 * @param User   $user         Current user
	 * @param string $invoiceAlias Invoice table alias
	 * @return string SQL fragment
	 */
	private function thirdpartyRestrictionSql($user, $invoiceAlias)
	{
		if (!empty($user->socid)) {
			return ' AND '.$invoiceAlias.'.fk_soc = '.((int) $user->socid);
		}
		if (!$user->hasRight('societe', 'client', 'voir')) {
			return ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'societe_commerciaux AS sc'
				.' WHERE sc.fk_soc = '.$invoiceAlias.'.fk_soc AND sc.fk_user = '.((int) $user->id).')';
		}

		return '';
	}

	/**
	 * Execute and aggregate a document-level statistics query.
	 *
	 * @param string $sql                    SQL query
	 * @param int    $periodStart            Current period start timestamp
	 * @param int    $periodEndExclusive     Current period exclusive end timestamp
	 * @param int    $comparisonStart        Comparison period start timestamp
	 * @param int    $comparisonEndExclusive Comparison period exclusive end timestamp
	 * @param bool   $loadMargin             Query includes margin columns
	 * @param string $flowType               customer or supplier
	 * @return array<string,mixed>|int<-1,-1>
	 */
	private function executeFlowQuery($sql, $periodStart, $periodEndExclusive, $comparisonStart, $comparisonEndExclusive, $loadMargin, $flowType)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$flowType.' statistics query failed: '.$this->error, LOG_ERR);
			return -1;
		}

		$flow = $this->emptyFlow();
		while ($row = $this->db->fetch_object($resql)) {
			$date = $this->db->jdate($row->datef);
			if ($date >= $periodStart && $date < $periodEndExclusive) {
				$bucket = 'current';
			} elseif ($date >= $comparisonStart && $date < $comparisonEndExclusive) {
				$bucket = 'previous';
			} else {
				continue;
			}

			$amount = price2num($row->amount, 'MT');
			$qty = price2num($row->qty, 'MS');
			$costAmount = $loadMargin ? price2num($row->cost_amount, 'MT') : 0.0;
			$costedAmount = $loadMargin ? price2num($row->costed_amount, 'MT') : 0.0;
			$missingCostLines = $loadMargin ? (int) $row->missing_cost_lines : 0;

			$this->addToSummary($flow[$bucket], $amount, $qty, $costAmount, $costedAmount, $missingCostLines, (int) $row->fk_soc);

			if ($bucket !== 'current') {
				continue;
			}

			$monthKey = dol_print_date($date, '%Y-%m');
			if (!isset($flow['monthly'][$monthKey])) {
				$flow['monthly'][$monthKey] = $this->emptySummary();
			}
			$this->addToSummary($flow['monthly'][$monthKey], $amount, $qty, $costAmount, $costedAmount, $missingCostLines, (int) $row->fk_soc);

			$thirdpartyId = (int) $row->fk_soc;
			if (!isset($flow['top'][$thirdpartyId])) {
				$flow['top'][$thirdpartyId] = array(
					'id' => $thirdpartyId,
					'name' => (string) $row->thirdparty_name,
					'amount' => 0.0,
					'qty' => 0.0,
					'documents' => 0,
				);
			}
			$flow['top'][$thirdpartyId]['amount'] += $amount;
			$flow['top'][$thirdpartyId]['qty'] += $qty;
			$flow['top'][$thirdpartyId]['documents']++;

			$flow['recent'][] = array(
				'id' => (int) $row->rowid,
				'ref' => (string) $row->ref,
				'ref_supplier' => isset($row->ref_supplier) ? (string) $row->ref_supplier : '',
				'date' => $date,
				'thirdparty_id' => $thirdpartyId,
				'thirdparty_name' => (string) $row->thirdparty_name,
				'amount' => $amount,
				'qty' => $qty,
			);
		}
		$this->db->free($resql);

		$flow['current']['partners'] = count($flow['current']['partner_ids']);
		$flow['previous']['partners'] = count($flow['previous']['partner_ids']);
		unset($flow['current']['partner_ids'], $flow['previous']['partner_ids']);
		foreach ($flow['monthly'] as &$month) {
			$month['partners'] = count($month['partner_ids']);
			unset($month['partner_ids']);
		}
		unset($month);

		$top = array_values($flow['top']);
		usort($top, static function ($left, $right) {
			if ((float) $left['amount'] === (float) $right['amount']) {
				return strcmp((string) $left['name'], (string) $right['name']);
			}
			return ((float) $left['amount'] < (float) $right['amount']) ? 1 : -1;
		});
		$flow['top'] = array_slice($top, 0, 10);
		$flow['recent'] = array_slice($flow['recent'], 0, 8);

		return $flow;
	}

	/**
	 * Add document values to one summary bucket.
	 *
	 * @param array<string,mixed> $summary          Summary bucket
	 * @param float               $amount           Net amount
	 * @param float               $qty              Signed quantity
	 * @param float               $costAmount       Signed cost
	 * @param float               $costedAmount     Revenue with cost evidence
	 * @param int                 $missingCostLines Number of lines missing cost evidence
	 * @param int                 $partnerId        Third-party identifier
	 * @return void
	 */
	private function addToSummary(&$summary, $amount, $qty, $costAmount, $costedAmount, $missingCostLines, $partnerId)
	{
		$summary['amount'] += $amount;
		$summary['qty'] += $qty;
		$summary['documents']++;
		$summary['cost'] += $costAmount;
		$summary['costed_amount'] += $costedAmount;
		$summary['margin'] = price2num($summary['costed_amount'] - $summary['cost'], 'MT');
		$summary['missing_cost_lines'] += $missingCostLines;
		$summary['partner_ids'][$partnerId] = true;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function emptyFlow()
	{
		return array(
			'current' => $this->emptySummary(),
			'previous' => $this->emptySummary(),
			'monthly' => array(),
			'top' => array(),
			'recent' => array(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function emptyOperationalFlow()
	{
		return array(
			'current' => $this->emptyOperationalSummary(),
			'previous' => $this->emptyOperationalSummary(),
			'monthly' => array(),
			'profile' => array(
				'manufactured' => false,
				'ingredient' => false,
				'received' => false,
				'relation_count' => 0,
				'relations' => array(),
			),
			'recent' => array(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function emptyOperationalSummary()
	{
		return array(
			'produced' => 0.0,
			'production_orders' => 0,
			'customer_usage' => 0.0,
			'customer_documents' => 0,
			'manufacturing_usage' => 0.0,
			'manufacturing_orders' => 0,
			'usage' => 0.0,
			'supplier_receipts' => 0.0,
			'supplier_documents' => 0,
			'customer_returns' => 0.0,
			'supplier_returns' => 0.0,
			'other_in' => 0.0,
			'other_out' => 0.0,
			'inbound' => 0.0,
			'outbound' => 0.0,
			'operational_net' => 0.0,
			'inventory_net' => 0.0,
			'inventory_events' => 0,
			'movements' => 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function emptySummary()
	{
		return array(
			'amount' => 0.0,
			'qty' => 0.0,
			'documents' => 0,
			'partners' => 0,
			'partner_ids' => array(),
			'cost' => 0.0,
			'costed_amount' => 0.0,
			'margin' => 0.0,
			'missing_cost_lines' => 0,
		);
	}
}
