<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_CatalogRule
 * @copyright   Copyright (c) 2011 Magento Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/**
 * Catalog rules resource model
 *
 * @category    Mage
 * @package     Mage_CatalogRule
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class Mage_CatalogRule_Model_Resource_Rule extends Mage_Core_Model_Resource_Db_Abstract
{
    const SECONDS_IN_DAY = 86400;

    /**
     * Initialize main table and table id field
     *
     */
    protected function _construct()
    {
        $this->_init('catalogrule/rule', 'rule_id');
    }

    /**
     * Prepare object data for saving
     *
     * @param Mage_Core_Model_Abstract $object
     */
    public function _beforeSave(Mage_Core_Model_Abstract $object)
    {
        if (!$object->getFromDate()) {
            $date = Mage::app()->getLocale()->date();
            $date->setHour(0)
                ->setMinute(0)
                ->setSecond(0);
            $object->setFromDate($date);
        }
        if ($object->getFromDate() instanceof Zend_Date) {
            $object->setFromDate($object->getFromDate()->toString(Varien_Date::DATETIME_INTERNAL_FORMAT));
        }

        if (!$object->getToDate()) {
            $object->setToDate(new Zend_Db_Expr('NULL'));
        } else {
            if ($object->getToDate() instanceof Zend_Date) {
                $object->setToDate($object->getToDate()->toString(Varien_Date::DATETIME_INTERNAL_FORMAT));
            }
        }
        parent::_beforeSave($object);
    }

    /**
     * Update products which are matched for rule
     *
     * @param Mage_CatalogRule_Model_Rule $rule
     * @return Mage_CatalogRule_Model_Resource_Rule
     */
    public function updateRuleProductData(Mage_CatalogRule_Model_Rule $rule)
    {
        $ruleId = $rule->getId();
        $write = $this->_getWriteAdapter();
        $write->beginTransaction();

        if ($rule->getProductsFilter()) {
            $write->delete(
                $this->getTable('catalogrule/rule_product'),
                $write->quoteInto('rule_id=?', $ruleId)
                . $write->quoteInto('and product_id in (?)', implode(',' , $rule->getProductsFilter()))
            );
        } else {
            $write->delete($this->getTable('catalogrule/rule_product'), $write->quoteInto('rule_id=?', $ruleId));
        }

        if (!$rule->getIsActive()) {
            $write->commit();
            return $this;
        }

        $websiteIds = $rule->getWebsiteIds();
        if (!is_array($websiteIds)) {
            $websiteIds = explode(',', $websiteIds);
        }
        if (empty($websiteIds)) {
            return $this;
        }

        Varien_Profiler::start('__MATCH_PRODUCTS__');
        $productIds = $rule->getMatchingProductIds();
        Varien_Profiler::stop('__MATCH_PRODUCTS__');
        $customerGroupIds = $rule->getCustomerGroupIds();

        $fromTime = strtotime($rule->getFromDate());
        $toTime = strtotime($rule->getToDate());
        $toTime = $toTime ? ($toTime + self::SECONDS_IN_DAY - 1) : 0;

        $sortOrder = (int)$rule->getSortOrder();
        $actionOperator = $rule->getSimpleAction();
        $actionAmount = $rule->getDiscountAmount();
        $actionStop = $rule->getStopRulesProcessing();

        $rows = array();

        try {
            foreach ($productIds as $productId) {
                foreach ($websiteIds as $websiteId) {
                    foreach ($customerGroupIds as $customerGroupId) {
                        $rows[] = array(
                            'rule_id'           => $ruleId,
                            'from_time'         => $fromTime,
                            'to_time'           => $toTime,
                            'website_id'        => $websiteId,
                            'customer_group_id' => $customerGroupId,
                            'product_id'        => $productId,
                            'action_operator'   => $actionOperator,
                            'action_amount'     => $actionAmount,
                            'action_stop'       => $actionStop,
                            'sort_order'        => $sortOrder,
                            );

                        if (count($rows) == 1000) {
                            $write->insertMultiple($this->getTable('catalogrule/rule_product'), $rows);
                            $rows = array();
                        }
                    }
                }
            }
            if (!empty($rows)) {
               $write->insertMultiple($this->getTable('catalogrule/rule_product'), $rows);
            }

            $write->commit();
        } catch (Exception $e) {
            $write->rollback();
            throw $e;
        }

        return $this;
    }

    /**
     * Get all product ids matched for rule
     *
     * @param int $ruleId
     * @return array
     */
    public function getRuleProductIds($ruleId)
    {
        $read = $this->_getReadAdapter();
        $select = $read->select()->from($this->getTable('catalogrule/rule_product'), 'product_id')
            ->where('rule_id=?', $ruleId);
        return $read->fetchCol($select);
    }

    /**
     * Remove catalog rules product prices for specified date range and product
     *
     * @param int|string $fromDate
     * @param int|string $toDate
     * @param int|null $productId
     * @return Mage_CatalogRule_Model_Resource_Rule
     */
    public function removeCatalogPricesForDateRange($fromDate, $toDate, $productId = null)
    {
        $write = $this->_getWriteAdapter();
        $conds = array();
        $cond = $write->quoteInto('rule_date between ?', $this->formatDate($fromDate));
        $cond = $write->quoteInto($cond.' and ?', $this->formatDate($toDate));
        $conds[] = $cond;
        if (!is_null($productId)) {
            $conds[] = $write->quoteInto('product_id=?', $productId);
        }

        /**
         * Add information about affected products
         * It can be used in processes which related with product price (like catalog index)
         */
        $select = $this->_getWriteAdapter()->select()
            ->from($this->getTable('catalogrule/rule_product_price'), 'product_id')
            ->where(implode(' AND ', $conds))
            ->group('product_id');

        $replace = $write->insertFromSelect(
            $select,
            $this->getTable('catalogrule/affected_product'),
            array('product_id'),
            true
        );
        $write->query($replace);
        $write->delete($this->getTable('catalogrule/rule_product_price'), $conds);
        return $this;
    }

    /**
     * Delete old price rules data
     *
     * @param unknown_type $date
     * @param mixed $productId
     * @return Mage_CatalogRule_Model_Resource_Rule
     */
    public function deleteOldData($date, $productId = null)
    {
        $write = $this->_getWriteAdapter();
        $conds = array();
        $conds[] = $write->quoteInto('rule_date<?', $this->formatDate($date));
        if (!is_null($productId)) {
            $conds[] = $write->quoteInto('product_id=?', $productId);
        }
        $write->delete($this->getTable('catalogrule/rule_product_price'), $conds);
        return $this;
    }

    /**
     * Get DB resource statement for processing query result
     *
     * @param int $fromDate
     * @param int $toDate
     * @param int|null $productId
     * @param int|null $websiteId
     * @return Zend_Db_Statement_Interface
     */
    protected function _getRuleProductsStmt($fromDate, $toDate, $productId = null, $websiteId = null)
    {
        $read = $this->_getReadAdapter();
        /**
         * Sort order is important
         * It used for check stop price rule condition.
         * website_id   customer_group_id   product_id  sort_order
         *  1           1                   1           0
         *  1           1                   1           1
         *  1           1                   1           2
         * if row with sort order 1 will have stop flag we should exclude
         * all next rows for same product id from price calculation
         */
        $select = $read->select()
            ->from(array('rp' => $this->getTable('catalogrule/rule_product')))
            ->where($read->quoteInto('rp.from_time=0 or rp.from_time<=?', $toDate)
            . ' or ' .$read->quoteInto('rp.to_time=0 or rp.to_time>=?', $fromDate))
            ->order(array('rp.website_id', 'rp.customer_group_id', 'rp.product_id', 'rp.sort_order', 'rp.rule_id'));

        if (!is_null($productId)) {
            $select->where('rp.product_id=?', $productId);
        }

        /**
         * Join default price and websites prices to result
         */
        $priceAttr  = Mage::getSingleton('eav/config')->getAttribute(Mage_Catalog_Model_Product::ENTITY, 'price');
        $priceTable = $priceAttr->getBackend()->getTable();
        $attributeId= $priceAttr->getId();

        $joinCondition = '%1$s.entity_id=rp.product_id AND (%1$s.attribute_id='.$attributeId.') and %1$s.store_id=%2$s';

        $select->join(
            array('pp_default'=>$priceTable),
            sprintf($joinCondition, 'pp_default', Mage_Core_Model_App::ADMIN_STORE_ID),
            array('default_price'=>'pp_default.value')
        );

        if ($websiteId !== null) {
            $website  = Mage::app()->getWebsite($websiteId);
            $defaultGroup = $website->getDefaultGroup();
            if ($defaultGroup instanceof Mage_Core_Model_Store_Group) {
                $storeId = $defaultGroup->getDefaultStoreId();
            } else {
                $storeId = Mage_Core_Model_App::ADMIN_STORE_ID;
            }

            $select->joinInner(
                array('product_website' => $this->getTable('catalog/product_website')),
                'product_website.product_id=rp.product_id ' .
                'AND rp.website_id=product_website.website_id ' .
                'AND product_website.website_id='.$websiteId,
                array()
            );

            $tableAlias = 'pp'.$websiteId;
            $fieldAlias = 'website_'.$websiteId.'_price';
            $select->joinLeft(
                array($tableAlias=>$priceTable),
                sprintf($joinCondition, $tableAlias, $storeId),
                array($fieldAlias=>$tableAlias.'.value')
            );
        } else {
            foreach (Mage::app()->getWebsites() as $website) {
                $websiteId      = $website->getId();
                $defaultGroup   = $website->getDefaultGroup();
                if ($defaultGroup instanceof Mage_Core_Model_Store_Group) {
                    $storeId    = $defaultGroup->getDefaultStoreId();
                } else {
                    $storeId    = Mage_Core_Model_App::ADMIN_STORE_ID;
                }

                $tableAlias = 'pp'.$websiteId;
                $fieldAlias = 'website_'.$websiteId.'_price';
                $select->joinLeft(
                    array($tableAlias => $priceTable),
                    sprintf($joinCondition, $tableAlias, $storeId),
                    array($fieldAlias => $tableAlias . '.value')
                );
            }
        }

        return $read->query($select);
    }

    /**
     * Generate catalog price rules prices for specified date range
     * If from date is not defined - will be used previous day by UTC
     * If to date is not defined - will be used next day by UTC
     *
     * @param int|string|null $fromDate
     * @param int|string|null $toDate
     * @param int $productId
     * @return Mage_CatalogRule_Model_Resource_Rule
     */
    public function applyAllRulesForDateRange($fromDate = null, $toDate = null, $productId = null)
    {
        $write = $this->_getWriteAdapter();
        $write->beginTransaction();

        Mage::dispatchEvent('catalogrule_before_apply', array('resource' => $this));

        $clearOldData = false;
        if ($fromDate === null) {
            $fromDate = mktime(0,0,0,date('m'),date('d')-1);
            /**
             * If fromDate not specified we can delete all data oldest than 1 day
             * We have run it for clear table in case when cron was not installed
             * and old data exist in table
             */
            $clearOldData = true;
        }
        if (is_string($fromDate)) {
            $fromDate = strtotime($fromDate);
        }
        if ($toDate === null) {
            $toDate = mktime(0,0,0,date('m'),date('d')+1);
        }
        if (is_string($toDate)) {
            $toDate = strtotime($toDate);
        }

        $product = null;
        if ($productId instanceof Mage_Catalog_Model_Product) {
            $product    = $productId;
            $productId  = $productId->getId();
        }

        $this->removeCatalogPricesForDateRange($fromDate, $toDate, $productId);
        if ($clearOldData) {
            $this->deleteOldData($fromDate, $productId);
        }

        $dayPrices  = array();

        try {
            /**
             * Update products rules prices per each website separately
             * because of max join limit in mysql
             */
            foreach (Mage::app()->getWebsites(false) as $website) {
                $productsStmt = $this->_getRuleProductsStmt(
                   $fromDate,
                   $toDate,
                   $productId,
                   $website->getId()
                );

                $dayPrices  = array();
                $stopFlags  = array();
                $prevKey    = null;

                while ($ruleData = $productsStmt->fetch()) {
                    $ruleProductId  = $ruleData['product_id'];
                    $productKey     = $ruleProductId . '_'
                       . $ruleData['website_id'] . '_'
                       . $ruleData['customer_group_id'];

                    if ($prevKey && ($prevKey != $productKey)) {
                        $stopFlags = array();
                    }

                    /**
                     * Build prices for each day
                     */
                    for ($time=$fromDate; $time<=$toDate; $time+=self::SECONDS_IN_DAY) {
                        if (($ruleData['from_time']==0 || $time >= $ruleData['from_time'])
                            && ($ruleData['to_time']==0 || $time <=$ruleData['to_time'])
                        ) {
                            $priceKey = $time . '_' . $productKey;

                            if (isset($stopFlags[$priceKey])) {
                                continue;
                            }

                            if (!isset($dayPrices[$priceKey])) {
                                $dayPrices[$priceKey] = array(
                                    'rule_date'         => $time,
                                    'website_id'        => $ruleData['website_id'],
                                    'customer_group_id' => $ruleData['customer_group_id'],
                                    'product_id'        => $ruleProductId,
                                    'rule_price'        => $this->_calcRuleProductPrice($ruleData),
                                    'latest_start_date' => $ruleData['from_time'],
                                    'earliest_end_date' => $ruleData['to_time'],
                                );
                            } else {
                                $dayPrices[$priceKey]['rule_price'] = $this->_calcRuleProductPrice(
                                    $ruleData,
                                    $dayPrices[$priceKey]
                                );
                                $dayPrices[$priceKey]['latest_start_date'] = max(
                                    $dayPrices[$priceKey]['latest_start_date'],
                                    $ruleData['from_time']
                                );
                                $dayPrices[$priceKey]['earliest_end_date'] = min(
                                    $dayPrices[$priceKey]['earliest_end_date'],
                                    $ruleData['to_time']
                                );
                            }

                            if ($ruleData['action_stop']) {
                                $stopFlags[$priceKey] = true;
                            }
                        }
                    }

                    $prevKey = $productKey;
                    if (count($dayPrices)>1000) {
                        $this->_saveRuleProductPrices($dayPrices);
                        $dayPrices = array();
                    }
                }
                $this->_saveRuleProductPrices($dayPrices);
            }
            $this->_saveRuleProductPrices($dayPrices);

            $write->delete($this->getTable('catalogrule/rule_group_website'), array());

            $timestamp = Mage::getModel('core/date')->gmtTimestamp();

            $select = $write->select()
                ->distinct(true)
                ->from($this->getTable('catalogrule/rule_product'), array('rule_id', 'customer_group_id', 'website_id'))
                ->where("{$timestamp} >= from_time AND (({$timestamp} <= to_time AND to_time > 0) OR to_time = 0)");
            $query = $select->insertFromSelect($this->getTable('catalogrule/rule_group_website'));
            $write->query($query);

            $write->commit();
        } catch (Exception $e) {
            $write->rollback();
            throw $e;
        }

        $productCondition = Mage::getModel('catalog/product_condition')
            ->setTable($this->getTable('catalogrule/affected_product'))
            ->setPkFieldName('product_id');
        Mage::dispatchEvent('catalogrule_after_apply', array(
            'product' => $product,
            'product_condition' => $productCondition
        ));
        $write->delete($this->getTable('catalogrule/affected_product'));

        return $this;
    }

    /**
     * Calculate product price based on price rule data and previous information
     *
     * @param array $ruleData
     * @param null|array $productData
     * @return float
     */
    protected function _calcRuleProductPrice($ruleData, $productData = null)
    {
        if ($productData !== null && isset($productData['rule_price'])) {
            $productPrice = $productData['rule_price'];
        } else {
            $websiteId = $ruleData['website_id'];
            if (isset($ruleData['website_'.$websiteId.'_price'])) {
                $productPrice = $ruleData['website_'.$websiteId.'_price'];
            } else {
                $productPrice = $ruleData['default_price'];
            }
        }

        $productPrice = Mage::helper('catalogrule')->calcPriceRule(
            $ruleData['action_operator'],
            $ruleData['action_amount'],
            $productPrice);

        return Mage::app()->getStore()->roundPrice($productPrice);
    }

    /**
     * Save rule prices for products to DB
     *
     * @param array $arrData
     * @return Mage_CatalogRule_Model_Resource_Rule
     */
    protected function _saveRuleProductPrices($arrData)
    {
        if (empty($arrData)) {
            return $this;
        }

        foreach ($arrData as $key => $data) {
            $productIds[$data['product_id']] = true; // to avoid dupes
            $arrData[$key]['rule_date']          = $this->formatDate($data['rule_date'], false);
            $arrData[$key]['latest_start_date']  = $this->formatDate($data['latest_start_date'], false);
            $arrData[$key]['earliest_end_date']  = $this->formatDate($data['earliest_end_date'], false);
        }

        foreach ($productIds as $id => $v) {
            $this->_getWriteAdapter()->delete($this->getTable('catalogrule/affected_product'),
                array("product_id = $id"));
            $this->_getWriteAdapter()->insert($this->getTable('catalogrule/affected_product'),
                array('product_id' => $id));
        }

        $this->_getWriteAdapter()->insertOnDuplicate($this->getTable('catalogrule/rule_product_price'), $arrData);
        return $this;
    }

    /**
     * Get catalog rules product price for specific date, website and
     * customer group
     *
     * @param int|string $date
     * @param int $wId
     * @param int $gId
     * @param int $pId
     * @return float | false
     */
    public function getRulePrice($date, $wId, $gId, $pId)
    {
        $data = $this->getRulePrices($date, $wId, $gId, array($pId));
        if (isset($data[$pId])) {
            return $data[$pId];
        }

        return false;
    }

    /**
     * Return product prices by catalog rule for specific date, website and customer group
     * Return product - price pairs
     *
     * @param int|string $date
     * @param int $websiteId
     * @param int $customerGroupId
     * @param array $productIds
     * @return array
     */
    public function getRulePrices($date, $websiteId, $customerGroupId, $productIds)
    {
        $adapter = $this->_getReadAdapter();
        $select  = $adapter->select()
            ->from($this->getTable('catalogrule/rule_product_price'), array('product_id', 'rule_price'))
            ->where('rule_date = ?', $this->formatDate($date, false))
            ->where('website_id = ?', $websiteId)
            ->where('customer_group_id = ?', $customerGroupId)
            ->where('product_id IN(?)', $productIds);
        return $adapter->fetchPairs($select);
    }

    /**
     * Get active rule data based on few filters
     *
     * @param int|string $date
     * @param int $websiteId
     * @param int $customerGroupId
     * @param int $productId
     * @return array
     */
    public function getRulesFromProduct($date, $websiteId, $customerGroupId, $productId)
    {
        $adapter = $this->_getReadAdapter();
        $dateQuoted = $adapter->quote($this->formatDate($date, false));
        $joinCondsQuoted[] = 'main_table.rule_id = rp.rule_id';
        $joinCondsQuoted[] = $adapter->quoteInto('rp.website_id = ?', $websiteId);
        $joinCondsQuoted[] = $adapter->quoteInto('rp.customer_group_id = ?', $customerGroupId);
        $joinCondsQuoted[] = $adapter->quoteInto('rp.product_id = ?', $productId);
        $fromDate = $adapter->getIfNullSql('main_table.from_date', $dateQuoted);
        $toDate = $adapter->getIfNullSql('main_table.to_date', $dateQuoted);
        $select = $adapter->select()
            ->from(array('main_table' => $this->getTable('catalogrule/rule')))
            ->joinInner(
                array('rp' => $this->getTable('catalogrule/rule_product')),
                implode(' AND ', $joinCondsQuoted),
                array())
            ->where(new Zend_Db_Expr("{$dateQuoted} BETWEEN {$fromDate} AND {$toDate}"))
            ->where('main_table.is_active = ?', 1)
            ->order('main_table.sort_order');
        return $adapter->fetchAll($select);
    }

    /**
     * Get data about product prices for all customer groups
     *
     * @param int|string $date
     * @param int $wId
     * @param int $pId
     * @return array
     */
    public function getRulesForProduct($date, $wId, $pId)
    {
        $read = $this->_getReadAdapter();
        $select = $read->select()
            ->from($this->getTable('catalogrule/rule_product_price'), '*')
            ->where('rule_date=?', $this->formatDate($date, false))
            ->where('website_id=?', $wId)
            ->where('product_id=?', $pId);
        return $read->fetchAll($select);
    }

    /**
     * Apply catalog rule to product
     *
     * @param Mage_CatalogRule_Model_Rule $rule
     * @param Mage_Catalog_Model_Product $product
     * @param array $websiteIds
     * @return Mage_CatalogRule_Model_Resource_Rule
     */
    public function applyToProduct($rule, $product, $websiteIds)
    {
        if (!$rule->getIsActive()) {
            return $this;
        }

        $ruleId = $rule->getId();
        $productId = $product->getId();

        $write = $this->_getWriteAdapter();
        $write->beginTransaction();

        $write->delete($this->getTable('catalogrule/rule_product'), array(
            $write->quoteInto('rule_id=?', $ruleId),
            $write->quoteInto('product_id=?', $productId),
        ));

        if (!$rule->getConditions()->validate($product)) {
            $write->delete($this->getTable('catalogrule/rule_product_price'), array(
                $write->quoteInto('product_id=?', $productId),
            ));
            $write->commit();
            return $this;
        }

        $customerGroupIds = $rule->getCustomerGroupIds();

        $fromTime   = strtotime($rule->getFromDate());
        $toTime     = strtotime($rule->getToDate());
        $toTime     = $toTime ? $toTime+self::SECONDS_IN_DAY - 1 : 0;

        $sortOrder      = (int)$rule->getSortOrder();
        $actionOperator = $rule->getSimpleAction();
        $actionAmount   = $rule->getDiscountAmount();
        $actionStop     = $rule->getStopRulesProcessing();

        $rows = array();
        try {
            foreach ($websiteIds as $websiteId) {
                foreach ($customerGroupIds as $customerGroupId) {
                    $rows[] = array(
                        'rule_id'           => $ruleId,
                        'from_time'         => $fromTime,
                        'to_time'           => $toTime,
                        'website_id'        => $websiteId,
                        'customer_group_id' => $customerGroupId,
                        'product_id'        => $productId,
                        'action_operator'   => $actionOperator,
                        'action_amount'     => $actionAmount,
                        'action_stop'       => $actionStop,
                        'sort_order'        => $sortOrder,
                        );

                    if (count($rows) == 1000) {
                        $write->insertMultiple($this->getTable('catalogrule/rule_product'), $rows);
                        $rows = array();
                    }
                }
            }

            if (!empty($rows)) {
               $write->insertMultiple($this->getTable('catalogrule/rule_product'), $rows);
            }
        } catch (Exception $e) {
            $write->rollback();
            throw $e;
        }

        $this->applyAllRulesForDateRange(null, null, $product);
        $write->commit();

        return $this;
    }
}
