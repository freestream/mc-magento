<?php

/**
 * mailchimp-lib Magento Component
 *
 * @category  Ebizmarts
 * @package   mailchimp-lib
 * @author    Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Ebizmarts_MailChimp_Model_Api_PromoRules extends Ebizmarts_MailChimp_Model_Api_SyncItem
{
    const BATCH_LIMIT = 50;
    const TYPE_FIXED = 'fixed';
    const TYPE_PERCENTAGE = 'percentage';
    const TARGET_PER_ITEM = 'per_item';
    const TARGET_TOTAL = 'total';
    const TARGET_SHIPPING = 'shipping';

    protected $_batchId;

    /**
     * @var Ebizmarts_MailChimp_Model_Api_PromoCodes
     */
    protected $_promoCodes;

    public function __construct()
    {
        parent::__construct();
        $this->_promoCodes = Mage::getModel('mailchimp/api_promoCodes');
    }

    /**
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array
     */
    public function createBatchJson($mailchimpStoreId, $magentoStoreId)
    {
        $batchArray = array();
        $this->_batchId = 'storeid-'
            . $magentoStoreId . '_'
            . Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE . '_'
            . $this->getDateHelper()->getDateMicrotime();
        $batchArray = array_merge($batchArray, $this->_getModifiedAndDeletedPromoRules($mailchimpStoreId));

        return $batchArray;
    }

    /**
     * @param $mailchimpStoreId
     * @return array
     */
    protected function _getModifiedAndDeletedPromoRules($mailchimpStoreId)
    {
        $batchArray = array();
        $deletedPromoRules = $this->makeModifiedAndDeletedPromoRulesCollection($mailchimpStoreId);

        $counter = 0;
        foreach ($deletedPromoRules as $promoRule) {
            $ruleId = $promoRule->getRelatedId();
            $batchArray[$counter]['method'] = "DELETE";
            $batchArray[$counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/promo-rules/' . $ruleId;
            $batchArray[$counter]['operation_id'] = $this->_batchId . '_' . $ruleId;
            $batchArray[$counter]['body'] = '';
            $this->getPromoCodes()->deletePromoCodesSyncDataByRule($promoRule);
            $this->deletePromoRuleSyncData($ruleId, $mailchimpStoreId);
            $counter++;
        }

        return $batchArray;
    }

    /**
     * @param $ruleId
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array
     */
    public function getNewPromoRule($ruleId, $mailchimpStoreId, $magentoStoreId)
    {
        $promoData = array();
        $promoRule = $this->getPromoRule($ruleId);
        $helper = $this->getHelper();
        $dateHelper = $this->getDateHelper();
        try {
            $ruleData = $this->generateRuleData($promoRule);
            $promoRuleJson = json_encode($ruleData);

            if ($promoRuleJson !== false) {
                if (!empty($ruleData)) {
                    $promoData['method'] = "POST";
                    $promoData['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/promo-rules';
                    $promoData['operation_id'] = 'storeid-'
                        . $magentoStoreId . '_'
                        . Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE . '_'
                        . $dateHelper->getDateMicrotime() . '_' . $ruleId;
                    $promoData['body'] = $promoRuleJson;
                    //update promo rule delta
                    $this->addSyncData($ruleId, $mailchimpStoreId);
                } else {
                    $error = $promoRule->getMailchimpSyncError();
                    if (!$error) {
                        $error = $helper->__('Something went wrong when retrieving the information.');
                    }

                    $this->addSyncDataError(
                        $ruleId,
                        $mailchimpStoreId,
                        $error,
                        null,
                        false,
                        $dateHelper->formatDate(null, "Y-m-d H:i:s")
                    );
                }
            } else {
                $jsonErrorMsg = json_last_error_msg();
                $this->logSyncError(
                    "Promo rule " . $ruleId . " json encode failed (".$jsonErrorMsg.")",
                    Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE,
                    $mailchimpStoreId, $magentoStoreId
                );

                $this->addSyncDataError(
                    $ruleId,
                    $mailchimpStoreId,
                    $jsonErrorMsg,
                    null,
                    false,
                    $dateHelper->formatDate(null, "Y-m-d H:i:s")
                );
            }
        } catch (Exception $e) {
            $this->logSyncError(
                $e->getMessage(),
                Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE,
                $mailchimpStoreId, $magentoStoreId
            );
        }

        return $promoData;
    }

    /**
     * @return mixed
     */
    protected function getBatchLimitFromConfig()
    {
        $batchLimit = self::BATCH_LIMIT;
        return $batchLimit;
    }

    /**
     * @param $ruleId
     * @return Mage_Core_Model_Abstract
     */
    protected function getPromoRule($ruleId)
    {
        return Mage::getModel('salesrule/rule')->load($ruleId);
    }

    /**
     * @return Mage_SalesRule_Model_Resource_Rule_Collection
     */
    protected function getPromoRuleResourceCollection()
    {
        return Mage::getResourceModel('salesrule/rule_collection');
    }

    /**
     * @param $magentoStoreId
     * @return Mage_SalesRule_Model_Resource_Rule_Collection
     */
    public function makePromoRulesCollection($magentoStoreId)
    {
        /**
         * @var Mage_SalesRule_Model_Resource_Rule_Collection $collection
         */
        $collection = $this->getPromoRuleResourceCollection();
        $websiteId = $this->getWebsiteIdByStoreId($magentoStoreId);
        $collection->addWebsiteFilter($websiteId);
        return $collection;
    }

    /**
     * @param $mailchimpStoreId
     * @return Ebizmarts_MailChimp_Model_Resource_Ecommercesyncdata_Collection
     */
    protected function makeModifiedAndDeletedPromoRulesCollection($mailchimpStoreId)
    {
        $deletedPromoRules = Mage::getModel('mailchimp/ecommercesyncdata')->getCollection();
        $deletedPromoRules->getSelect()->where(
            "mailchimp_store_id = '" . $mailchimpStoreId
            . "' AND type = '" . Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE
            . "' AND (mailchimp_sync_modified = 1 OR mailchimp_sync_deleted = 1)"
        );
        $deletedPromoRules->getSelect()->limit($this->getBatchLimitFromConfig());
        return $deletedPromoRules;
    }

    /**
     * @param $collection
     * @param $mailchimpStoreId
     */
    public function joinMailchimpSyncDataWithoutWhere($collection, $mailchimpStoreId)
    {
        $joinCondition = "m4m.related_id = main_table.rule_id AND m4m.type = '%s' AND m4m.mailchimp_store_id = '%s'";
        $mailchimpTableName = $this->getMailchimpEcommerceDataTableName();
        $collection->getSelect()->joinLeft(
            array("m4m" => $mailchimpTableName),
            sprintf($joinCondition, Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE, $mailchimpStoreId),
            array(
                "m4m.related_id",
                "m4m.type",
                "m4m.mailchimp_store_id",
                "m4m.mailchimp_sync_delta",
                "m4m.mailchimp_sync_modified"
            )
        );
    }

    /**
     * @param $ruleId
     * @param $mailchimpStoreId
     */
    protected function deletePromoRuleSyncData($ruleId, $mailchimpStoreId)
    {
        $ruleSyncDataItem = $this->getHelper()->getEcommerceSyncDataItem(
            $ruleId,
            Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE,
            $mailchimpStoreId
        );
        $ruleSyncDataItem->delete();
    }

    /**
     * @param $promoRule
     * @return array
     */
    protected function generateRuleData($promoRule)
    {
        $error = null;
        $data = array();
        $data['id'] = $promoRule->getRuleId();
        $data['title'] = $promoRule->getName();

        //Set title as description if description null
        $data['description'] = ($promoRule->getDescription()) ? $promoRule->getDescription() : $promoRule->getName();

        $fromDate = $promoRule->getFromDate();
        if ($fromDate !== null) {
            $data['starts_at'] = $fromDate;
        }

        $toDate = $promoRule->getToDate();
        if ($toDate !== null) {
            $data['ends_at'] = $toDate;
        }

        $data['amount'] = $this->getMailChimpDiscountAmount($promoRule);
        $promoAction = $promoRule->getSimpleAction();
        $data['type'] = $this->getMailChimpType($promoAction);
        $data['target'] = $this->getMailChimpTarget($promoAction);

        $data['enabled'] = (bool)$promoRule->getIsActive();

        if ($this->ruleIsNotCompatible($data)) {
            $error = 'The rule type is not supported by the MailChimp schema.';
        }

        if (!$error && $this->ruleHasMissingInformation($data)) {
            $error = 'There is required information by the MailChimp schema missing.';
        }

        if ($error) {
            $data = array();
            $promoRule->setMailchimpSyncError($error);
        }

        return $data;
    }

    /**
     * @param $promoAction
     * @return string|null
     */
    protected function getMailChimpType($promoAction)
    {
        $mailChimpType = null;
        switch ($promoAction) {
        case Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION:
            $mailChimpType = self::TYPE_PERCENTAGE;
            break;
        case Mage_SalesRule_Model_Rule::BY_FIXED_ACTION:
        case Mage_SalesRule_Model_Rule::CART_FIXED_ACTION:
            $mailChimpType = self::TYPE_FIXED;
            break;
        }

        return $mailChimpType;
    }

    /**
     * @param $promoAction
     * @return string|null
     */
    protected function getMailChimpTarget($promoAction)
    {
        $mailChimpTarget = null;
        switch ($promoAction) {
        case Mage_SalesRule_Model_Rule::CART_FIXED_ACTION:
        case Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION:
            $mailChimpTarget = self::TARGET_TOTAL;
            break;
        case Mage_SalesRule_Model_Rule::BY_FIXED_ACTION:
            $mailChimpTarget = self::TARGET_PER_ITEM;
            break;
        }

        return $mailChimpTarget;
    }

    /**
     * @param $ruleId
     */
    public function update($ruleId)
    {
        $this->_setModified($ruleId);
    }

    /**
     * @param $ruleId
     */
    protected function _setModified($ruleId)
    {
        $helper = $this->getHelper();
        $promoRules = $helper->getAllEcommerceSyncDataItemsPerId(
            $ruleId,
            Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE
        );
        foreach ($promoRules as $promoRule) {
            $mailchimpStoreId = $promoRule->getMailchimpStoreId();
            $this->markSyncDataAsModified($ruleId, $mailchimpStoreId);
        }
    }

    /**
     * @param $ruleId
     */
    public function markAsDeleted($ruleId)
    {
        $this->_setDeleted($ruleId);
    }

    /**
     * @param $ruleId
     */
    protected function _setDeleted($ruleId)
    {
        $helper = $this->getHelper();
        $promoRules = $helper->getAllEcommerceSyncDataItemsPerId(
            $ruleId,
            Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE
        );
        foreach ($promoRules as $promoRule) {
            $mailchimpStoreId = $promoRule->getMailchimpStoreId();
            $this->markSyncDataAsDeleted($ruleId, $mailchimpStoreId);
        }
    }

    /**
     * @param $promoRule
     * @return float|int
     */
    protected function getMailChimpDiscountAmount($promoRule)
    {
        $action = $promoRule->getSimpleAction();
        if ($action == Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION) {
            $mailChimpDiscount = ($promoRule->getDiscountAmount() / 100);
        } else {
            $mailChimpDiscount = $promoRule->getDiscountAmount();
        }

        return $mailChimpDiscount;
    }

    /**
     * @return Ebizmarts_MailChimp_Model_Api_PromoCodes
     */
    protected function getPromoCodes()
    {
        return $this->_promoCodes;
    }

    /**
     * @param $magentoStoreId
     * @return mixed
     */
    protected function getWebsiteIdByStoreId($magentoStoreId)
    {
        return Mage::getModel('core/store')->load($magentoStoreId)->getWebsiteId();
    }

    /**
     * @param $data
     * @return bool
     */
    protected function ruleIsNotCompatible($data)
    {
        $isNotCompatible = null;

        if ($data['target'] === null || $data['type'] === null) {
            $isNotCompatible = true;
        } else {
            $isNotCompatible = false;
        }

        return $isNotCompatible;
    }

    /**
     * @param $data
     * @return bool
     */
    protected function ruleHasMissingInformation($data)
    {
        $hasMissingInformation = null;

        if ($data['amount'] === null || $data['description'] === null || $data['id'] === null) {
            $hasMissingInformation = true;
        } else {
            $hasMissingInformation = false;
        }

        return $hasMissingInformation;
    }

    /**
     * @return string
     */
    protected function getClassConstant()
    {
        return Ebizmarts_MailChimp_Model_Config::IS_PROMO_RULE;
    }
}
