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
class Ebizmarts_MailChimp_Model_Api_Products extends Ebizmarts_MailChimp_Model_Api_SyncItem
{
    const PRODUCT_IS_ENABLED = 1;
    const PRODUCT_IS_DISABLED = 2;
    const BATCH_LIMIT = 100;
    protected $_parentImageUrl = null;
    protected $_parentId = null;
    protected $_parentUrl = null;
    protected $_parentPrice = null;
    protected $_visibility = null;

    /**
     * @var Mage_Catalog_Model_Product_Type_Configurable
     */
    protected $_productTypeConfigurable;

    protected $_visibilityOptions;
    protected $_productTypeConfigurableResource;
    public static $noChildrenIds = array(0 => array());

    const PRODUCT_DISABLED_IN_MAGENTO = 'This product was deleted because it is disabled in Magento.';

    public function __construct()
    {
        parent::__construct();

        $this->_productTypeConfigurable = Mage::getModel('catalog/product_type_configurable');
        $this->_productTypeConfigurableResource = Mage::getResourceSingleton(
            'catalog/product_type_configurable'
        );
        $this->_visibilityOptions = Mage::getModel('catalog/product_visibility')->getOptionArray();
    }

    /**
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_Exception
     */
    public function createBatchJson($mailchimpStoreId, $magentoStoreId)
    {
        $helper = $this->getHelper();
        $dateHelper = $this->getDateHelper();
        $oldStore = $helper->getCurrentStoreId();
        $helper->setCurrentStore($magentoStoreId);

        if ($this->isProductFlatTableEnabled()) {
            $helper->getMageApp()->getStore($magentoStoreId)
                ->setConfig(
                    Mage_Catalog_Helper_Category_Flat::XML_PATH_IS_ENABLED_FLAT_CATALOG_CATEGORY,
                    0
                )
                ->setConfig(
                    Mage_Catalog_Helper_Product_Flat::XML_PATH_USE_PRODUCT_FLAT,
                    0
                );
        }

        $this->_markSpecialPrices($mailchimpStoreId, $magentoStoreId);
        $collection = $this->makeProductsNotSentCollection($magentoStoreId);
        $this->joinMailchimpSyncData($collection, $mailchimpStoreId);
        $batchArray = array();
        $batchId = $this->makeBatchId($magentoStoreId);
        $counter = 0;

        foreach ($collection as $product) {
            $productId = $product->getId();

            if ($this->shouldSendProductUpdate($product)) {
                $buildUpdateOperations = $this->_buildUpdateProductRequest(
                    $product,
                    $batchId,
                    $mailchimpStoreId,
                    $magentoStoreId
                );

                if ($buildUpdateOperations !== false) {
                    $batchArray = array_merge(
                        $buildUpdateOperations,
                        $batchArray
                    );
                    $this->addSyncData($productId, $mailchimpStoreId);
                }

                $counter = $this->_getBatchCounter($batchArray);
                continue;
            } else {
                $data = $this->_buildNewProductRequest($product, $batchId, $mailchimpStoreId, $magentoStoreId);
            }

            if ($data !== false) {
                if (!empty($data)) {
                    $batchArray[$counter] = $data;
                    $counter++;

                    $dataProduct = $helper->getEcommerceSyncDataItem(
                        $productId,
                        Ebizmarts_MailChimp_Model_Config::IS_PRODUCT,
                        $mailchimpStoreId
                    );

                    if ($dataProduct->getId()) {
                        $helper->modifyCounterSentPerBatch(Ebizmarts_MailChimp_Helper_Data::PRO_MOD);
                    } else {
                        $helper->modifyCounterSentPerBatch(Ebizmarts_MailChimp_Helper_Data::PRO_NEW);
                    }

                    //update product delta
                    $this->addSyncData($productId, $mailchimpStoreId);
                } else {
                    $this->addSyncDataError(
                        $productId,
                        $mailchimpStoreId,
                        "This product type is not supported on MailChimp.",
                        null,
                        false,
                        $dateHelper->formatDate(null, 'Y-m-d H:i:s')
                    );
                }
            }
        }

        $helper->setCurrentStore($oldStore);

        return $batchArray;
    }

    /**
     * @param $batchArray
     * @return int
     */
    protected function _getBatchCounter($batchArray)
    {
        return count($batchArray);
    }

    /**
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array
     */
    public function createDeletedProductsBatchJson($mailchimpStoreId, $magentoStoreId)
    {
        $deletedProducts = $this->getProductResourceCollection();

        $this->joinMailchimpSyncDataDeleted($mailchimpStoreId, $deletedProducts);

        $batchArray = array();
        $batchId = $this->makeBatchId($magentoStoreId);
        $counter = 0;

        foreach ($deletedProducts as $product) {
            $data = $this->_buildDeleteProductRequest($product, $batchId, $mailchimpStoreId);

            if (!empty($data)) {
                $batchArray[$counter] = $data;
                $counter++;
            }

            $this->addSyncDataError(
                $product->getId(),
                $mailchimpStoreId,
                self::PRODUCT_DISABLED_IN_MAGENTO
            );
        }

        return $batchArray;
    }

    /**
     * @param $product
     * @param $batchId
     * @param $mailchimpStoreId
     * @return array
     */
    protected function _buildDeleteProductRequest($product, $batchId, $mailchimpStoreId)
    {
        if ($this->isBundleProduct($product)) {
            return array();
        } else {
            $data = array();
            $data['method'] = "DELETE";
            $data['path'] = "/ecommerce/stores/" . $mailchimpStoreId . "/products/" . $product->getId();
            $data['operation_id'] = $batchId . '_' . $product->getId();
        }

        return $data;
    }

    /**
     * @param $product
     * @param $batchId
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array|bool
     */
    protected function _buildNewProductRequest($product, $batchId, $mailchimpStoreId, $magentoStoreId)
    {
        $variantProducts = array();

        if ($this->isSimpleProduct($product)) {
            $variantProducts[] = $product;
        } elseif ($this->isConfigurableProduct($product)) {
            $variantProducts = $this->makeProductChildrenArray($product, $magentoStoreId);
        } elseif ($this->isVirtualProduct($product) || $this->isDownloadableProduct($product)) {
            $variantProducts[] = $product;
        } else {
            return array();
        }

        $bodyData = $this->_buildProductData($product, $magentoStoreId, false, $variantProducts);
        $body = json_encode($bodyData, JSON_HEX_APOS | JSON_HEX_QUOT);

        if ($body === false) {
            //json encode failed
            $jsonErrorMsg = json_last_error_msg();
            $this->logSyncError(
                "Product " . $product->getId() . " json encode failed (".$jsonErrorMsg.")",
                Ebizmarts_MailChimp_Model_Config::IS_PRODUCT,
                $mailchimpStoreId, $magentoStoreId
            );

            $this->addSyncDataError(
                $product->getId(),
                $mailchimpStoreId,
                $jsonErrorMsg,
                null,
                null,
                $this->getDateHelper()->getCurrentDateTime()
            );

            return false;
        }

        $data = array();
        $data['method'] = "POST";
        $data['path'] = "/ecommerce/stores/" . $mailchimpStoreId . "/products";
        $data['operation_id'] = $batchId . '_' . $product->getId();
        $data['body'] = $body;

        return $data;
    }

    /**
     * @param $product
     * @param $batchId
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array|bool
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_Exception
     */
    protected function _buildUpdateProductRequest($product, $batchId, $mailchimpStoreId, $magentoStoreId)
    {
        $variantProducts = array();
        $operations = array();

        if ($this->isSimpleProduct($product)
            || $this->isVirtualProduct($product)
            || $this->isDownloadableProduct($product)
        ) {
            $variantProducts[] = $product;
            $parentIds = $this->_productTypeConfigurableResource->getParentIdsByChild($product->getId());

            foreach ($parentIds as $parentId) {
                $helper = $this->getHelper();
                $productSyncDataItem = $helper->getEcommerceSyncDataItem(
                    $parentId,
                    Ebizmarts_MailChimp_Model_Config::IS_PRODUCT,
                    $mailchimpStoreId
                );

                if ($productSyncDataItem->getMailchimpSyncDelta()) {
                    $parent = $this->_getParentProduct($parentId);
                    $variantProducts = $this->makeProductChildrenArray(
                        $product,
                        $magentoStoreId,
                        true
                    );
                    $bodyData = $this->_buildProductData($parent, $magentoStoreId, false, $variantProducts);
                    $body = json_encode($bodyData, JSON_HEX_APOS | JSON_HEX_QUOT);

                    if ($body === false) {
                        $jsonErrorMsg = json_last_error_msg();
                        $this->logSyncError(
                            "Product " . $parent->getId() . " json encode failed (".$jsonErrorMsg.")",
                            Ebizmarts_MailChimp_Model_Config::IS_PRODUCT,
                            $mailchimpStoreId, $magentoStoreId
                        );

                        $this->addSyncDataError(
                            $product->getId(),
                            $mailchimpStoreId,
                            $jsonErrorMsg,
                            null,
                            null,
                            $this->getDateHelper()->getCurrentDateTime()
                        );

                        return false;
                    }

                    $data = array();
                    $data['method'] = "PATCH";
                    $data['path'] = "/ecommerce/stores/" . $mailchimpStoreId . "/products/" . $parent->getId();
                    $data['operation_id'] = $batchId . '_' . $parent->getId();
                    $data['body'] = $body;
                    $operations[] = $data;
                }
            }
        } elseif ($this->isConfigurableProduct($product)) {
            $variantProducts = $this->makeProductChildrenArray(
                $product,
                $magentoStoreId,
                true
            );
        } else {
            return array();
        }

        $bodyData = $this->_buildProductData($product, $magentoStoreId, false, $variantProducts);
        $body = json_encode($bodyData, JSON_HEX_APOS | JSON_HEX_QUOT);

        if ($body === false) {
            //json encode failed
            $this->logSyncError(
                "Product " . $product->getId() . " json encode failed (".json_last_error_msg().")",
                Ebizmarts_MailChimp_Model_Config::IS_PRODUCT,
                null, $magentoStoreId
            );

            $jsonErrorMsg = json_last_error_msg();
            $this->logSyncError(
                "Product " . $product->getId() . " json encode failed (".$jsonErrorMsg.")",
                Ebizmarts_MailChimp_Model_Config::IS_PRODUCT,
                null, $magentoStoreId
            );

            $this->addSyncDataError(
                $product->getId(),
                $mailchimpStoreId,
                $jsonErrorMsg,
                null,
                null,
                $this->getDateHelper()->getCurrentDateTime()
            );

            return false;
        }

        $data = array();
        $data['method'] = "PATCH";
        $data['path'] = "/ecommerce/stores/" . $mailchimpStoreId . "/products/" . $product->getId();
        $data['operation_id'] = $batchId . '_' . $product->getId();
        $data['body'] = $body;
        $operations[] = $data;

        return $operations;
    }

    /**
     * @param $id
     * @return Mage_Core_Model_Abstract
     */
    protected function _getParentProduct($id)
    {
        return Mage::getModel('catalog/product')->load($id);
    }

    /**
     * @param       $product
     * @param       $magentoStoreId
     * @param bool  $isVariant
     * @param array $variants
     * @return array
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_Exception
     */
    protected function _buildProductData($product, $magentoStoreId, $isVariant = true, $variants = array())
    {
        $data = array();

        $productId = $product->getId();
        $helper = $this->getHelper();
        $rc = $helper->getProductResourceModel();
        //data applied for both root and varient products
        $data["id"] = $productId;
        $data["title"] = $rc->getAttributeRawValue($productId, 'name', $magentoStoreId);
        $this->_visibility = $rc->getAttributeRawValue($productId, 'visibility', $magentoStoreId);
        $url = null;

        if (!$this->currentProductIsVisible()) {
            $url = $this->getNotVisibleProductUrl($product->getId(), $magentoStoreId);
        } else {
            $url = $this->getProductUrl($product);
        }

        if (!$url) {
            $url = Mage::app()->getStore($magentoStoreId)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
        }

        $data["url"] = $url;
        //image
        $imageUrl = $this->getMailChimpImageUrl($product, $magentoStoreId);

        if ($imageUrl) {
            $data["image_url"] = $imageUrl;
        }

        //missing data
        $data["published_at_foreign"] = "";

        if ($isVariant) {
            $data += $this->getProductVariantData($product, $magentoStoreId);
        } else {
            $description = $rc->getAttributeRawValue($productId, 'description', $magentoStoreId);

            if (is_string($description)) {
                $data["description"] = $description;
            }

            //mailchimp product type and vendor (magento category)
            $categoryName = $this->getProductCategories($product, $magentoStoreId);

            if ($categoryName) {
                $data["type"] = $categoryName;
                $data["vendor"] = $data["type"];
            }

            //missing data
            $data["handle"] = "";

            //variants
            if (!empty($variants)) {
                $data = $this->_processVariants($data, $variants, $product, $magentoStoreId);
            }
        }

        return $data;
    }

    /**
     * @param $data
     * @param $variants
     * @param $product
     * @param $magentoStoreId
     * @return array
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_Exception
     */
    protected function _processVariants($data, $variants, $product, $magentoStoreId)
    {
        $data["variants"] = array();

        if (isset($data["image_url"])) {
            $this->_parentImageUrl = $data["image_url"];
        }

        $this->_parentId = $product->getId();

        if ($this->currentProductIsVisible()) {
            $this->_parentUrl = $data['url'];
        }

        $price = $this->getMailchimpFinalPrice($product, $magentoStoreId);

        if ($price) {
            $this->_parentPrice = $price;
        }

        foreach ($variants as $variant) {
            $data["variants"][] = $this->_buildProductData($variant, $magentoStoreId);
        }

        $this->_parentImageUrl = null;
        $this->_parentPrice = null;
        $this->_parentId = null;
        $this->_parentUrl = null;

        return $data;
    }

    /**
     * Get stores to update and call update function after modification.
     *
     * @param $productId
     * @param $mailchimpStoreId
     */
    public function update($productId, $mailchimpStoreId)
    {
        $parentIdArray = $this->getAllParentIds($productId);

        foreach ($parentIdArray as $parentId) {
            $this->markSyncDataAsModified($parentId, $mailchimpStoreId);
        }

        $this->markSyncDataAsModified($productId, $mailchimpStoreId);
    }

    /**
     * Get stores to update and call update function after product is disabled.
     *
     * @param $productId
     * @param $mailchimpStoreId
     */
    public function updateDisabledProducts($productId, $mailchimpStoreId)
    {
        $this->markSyncDataAsDeleted($productId, $mailchimpStoreId, 0);
    }

    /**
     * Return products belonging to an order or a cart in a valid format to be sent to MailChimp.
     *
     * @param  $order
     * @param  $mailchimpStoreId
     * @param  $magentoStoreId
     * @return array
     */
    public function sendModifiedProduct($order, $mailchimpStoreId, $magentoStoreId)
    {
        $data = array();
        $batchId = $this->makeBatchId($magentoStoreId);
        $items = $order->getAllVisibleItems();
        $helper = $this->getHelper();
        $dateHelper = $this->getDateHelper();

        foreach ($items as $item) {
            $itemProductId = $item->getProductId();
            $product = $this->loadProductById($itemProductId);
            $productId = $product->getId();
            $productSyncData = $helper->getEcommerceSyncDataItem(
                $productId,
                Ebizmarts_MailChimp_Model_Config::IS_PRODUCT,
                $mailchimpStoreId
            );

            if ($productId != $itemProductId
                || $this->isBundleProduct($product)
                || $this->isGroupedProduct($product)
            ) {
                if ($productId) {
                    $this->addSyncDataError(
                        $productId,
                        $mailchimpStoreId,
                        "This product type is not supported on MailChimp.",
                        null,
                        null,
                        $dateHelper->formatDate(null, 'Y-m-d H:i:s')
                    );
                }

                continue;
            }

            $syncModified = $productSyncData->getMailchimpSyncModified();
            $productSyncDelta = $productSyncData->getMailchimpSyncDelta();
            $isProductEnabled = $this->isProductEnabled($productId, $magentoStoreId);

            if ($syncModified && $isProductEnabled) {
                $buildUpdateOperations = $this->_buildUpdateProductRequest(
                    $product,
                    $batchId,
                    $mailchimpStoreId,
                    $magentoStoreId
                );

                if ($buildUpdateOperations !== false) {
                    // json correctly encoded
                    $data = array_merge(
                        $buildUpdateOperations,
                        $data
                    );
                    $this->addSyncData($productId, $mailchimpStoreId);
                }
            } elseif (!$productSyncDelta || !$isProductEnabled) {
                $bodyData = $this->_buildNewProductRequest($product, $batchId, $mailchimpStoreId, $magentoStoreId);

                if ($bodyData !== false) {
                    $data[] = $bodyData;
                    // avoid update for disabled products to prevent send the product as modified
                    if ($isProductEnabled) {
                        $this->addSyncData($productId, $mailchimpStoreId);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * @param $magentoStoreId
     * @return string
     */
    public function makeBatchId($magentoStoreId)
    {
        $batchId = 'storeid-' . $magentoStoreId . '_' . Ebizmarts_MailChimp_Model_Config::IS_PRODUCT;
        $batchId .= '_' . $this->getDateHelper()->getDateMicrotime();

        return $batchId;
    }

    /**
     * @param $magentoStoreId
     * @return Mage_Catalog_Model_Resource_Product_Collection
     */
    public function makeProductsNotSentCollection($magentoStoreId, $isParentProduct = false)
    {
        /**
         * @var Mage_Catalog_Model_Resource_Product_Collection $collection
         */
        $collection = $this->getProductResourceCollection();

        if (!$isParentProduct) {
            $collection->addFinalPrice();
        }

        $collection->addStoreFilter($magentoStoreId);
        $this->getHelper()->addResendFilter(
            $collection,
            $magentoStoreId,
            Ebizmarts_MailChimp_Model_Config::IS_PRODUCT
        );

        $this->joinQtyAndBackorders($collection);

        if (!$isParentProduct) {
            $collection->getSelect()->limit($this->getBatchLimitFromConfig());
        }

        return $collection;
    }

    /**
     * @return mixed
     */
    protected function getBatchLimitFromConfig()
    {
        $helper = $this->getHelper();
        return $helper->getProductAmountLimit();
    }

    /**
     * @param $product
     * @return bool
     * @throws Mage_Core_Exception
     */
    protected function shouldSendProductUpdate($product)
    {
        return $product->getMailchimpSyncModified()
            && $product->getMailchimpSyncDelta()
            && $product->getMailchimpSyncedFlag()
            && $product->getMailchimpSyncError() == '';
    }

    /**
     * @param $product
     * @return bool
     */
    protected function isSimpleProduct($product)
    {
        return $product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_SIMPLE;
    }

    /**
     * @param $product
     * @return bool
     */
    protected function isVirtualProduct($product)
    {
        return $product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_VIRTUAL;
    }

    /**
     * @param $product
     * @return bool
     */
    protected function isDownloadableProduct($product)
    {
        return $product->getTypeId() == "downloadable";
    }

    /**
     * @param $product
     * @return bool
     */
    protected function isBundleProduct($product)
    {
        return $product->getTypeId() == 'bundle';
    }

    /**
     * @param $product
     * @return bool
     */
    protected function isGroupedProduct($product)
    {
        return $product->getTypeId() == 'grouped';
    }

    /**
     * @param $product
     * @return bool
     */
    protected function isConfigurableProduct($product)
    {
        return $product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE;
    }

    /**
     * @param $collection
     */
    public function joinQtyAndBackorders($collection)
    {
        $collection->joinField(
            'qty',
            'cataloginventory/stock_item',
            'qty',
            'product_id=entity_id',
            '{{table}}.stock_id=1',
            'left'
        );

        $collection->joinField(
            'backorders',
            'cataloginventory/stock_item',
            'backorders',
            'product_id=entity_id',
            '{{table}}.stock_id=1',
            'left'
        );
    }

    /**
     * @param $product
     * @param $magentoStoreId
     * @return mixed
     */
    protected function getProductVariantData($product, $magentoStoreId)
    {
        $data = array();
        $sku = $product->getSku();
        $data["sku"] = $sku ? $sku : '';

        $price = $this->getMailChimpProductPrice($product, $magentoStoreId);

        if ($price) {
            $data["price"] = $price;
        }

        //stock
        $data["inventory_quantity"] = (int)$product->getQty();
        $data["backorders"] = (string)$product->getBackorders();

        $data["visibility"] = $this->getVisibility($this->_visibility);

        return $data;
    }

    /**
     * @return Mage_Catalog_Model_Resource_Product_Collection
     */
    protected function getProductResourceCollection()
    {
        return Mage::getResourceModel('catalog/product_collection');
    }

    /**
     * @param $product
     * @return array
     */
    protected function getConfigurableChildrenIds($product)
    {
        $childrenIds = $this->getChildrenIdsForConfigurable($product);

        if ($childrenIds === self::$noChildrenIds) {
            return array();
        }

        return $childrenIds;
    }

    /**
     * @param       $product
     * @param       $magentoStoreId
     * @param bool  $isBuildUpdateProductRequest
     * @return array | return an array with the childs of the product passed by parameter
     */
    public function makeProductChildrenArray($product, $magentoStoreId, $isBuildUpdateProductRequest = false)
    {
        $variantProducts[] = $product;
        /**
         * @var Mage_Catalog_Model_Resource_Product_Collection $collection
         */
        $collection = $this->makeProductsNotSentCollection($magentoStoreId, true);

        $childProducts = $this->getConfigurableChildrenIds($product);
        $collection->addAttributeToFilter("entity_id", array("in" => $childProducts));

        foreach ($collection as $childProduct) {
            if ($isBuildUpdateProductRequest) {
                if ($childProduct->getId() != $product->getId()) {
                    $variantProducts[] = $childProduct;
                }
            } else {
                $variantProducts[] = $childProduct;
            }
        }

        return $variantProducts;
    }

    /**
     * @param $product
     * @return array
     */
    protected function getChildrenIdsForConfigurable($product)
    {
        return $this->_productTypeConfigurable->getChildrenIds($product->getId());
    }

    /**
     * This function will perform the join of the collection with the table
     * mailchimp_ecommerce_sync_data when the programcreates the batch json
     * to send the product data to mailchimp
     *
     * @param $collection
     * @param $mailchimpStoreId
     */
    public function joinMailchimpSyncData($collection, $mailchimpStoreId)
    {
        $joinCondition = $this->buildMailchimpDataJoin();
        $this->executeMailchimpDataJoin($collection, $mailchimpStoreId, $joinCondition);
        $this->buildMailchimpDataWhere($collection);

    }

    /**
     * @return string
     */
    protected function buildMailchimpDataJoin()
    {
        $joinCondition = "m4m.related_id = e.entity_id AND m4m.type = '%s' AND m4m.mailchimp_store_id = '%s'";
        return $joinCondition;
    }

    /**
     * This function will perform the join of the collection with the table mailchimp_ecommerce_sync_data
     * to mark products as modified when special price starts/ends
     *
     * @param $collection
     * @param $mailchimpStoreId
     */
    public function joinMailchimpSyncDataForSpecialPrices($collection, $mailchimpStoreId)
    {
        $joinCondition = $this->builMailchimpDataJoinForSpecialPrices();
        $this->executeMailchimpDataJoin($collection, $mailchimpStoreId, $joinCondition);
        $this->builMailchimpDataJoinForSpecialPrices($collection);
    }

    /**
     * @return string
     */
    protected function builMailchimpDataJoinForSpecialPrices()
    {
        $joinCondition = $this->buildMailchimpDataJoin() . " AND m4m.mailchimp_sync_modified = 0";
        return $joinCondition;
    }

    /**
     * @param $collection
     * @param $mailchimpStoreId
     * @param $joinCondition
     */
    protected function executeMailchimpDataJoin($collection, $mailchimpStoreId, $joinCondition)
    {
        $mailchimpTableName = $this->getMailchimpEcommerceDataTableName();
        $collection->getSelect()->joinLeft(
            array("m4m" => $mailchimpTableName),
            sprintf($joinCondition, Ebizmarts_MailChimp_Model_Config::IS_PRODUCT, $mailchimpStoreId),
            array(
                "m4m.related_id",
                "m4m.type",
                "m4m.mailchimp_store_id",
                "m4m.mailchimp_sync_delta",
                "m4m.mailchimp_sync_modified",
                "m4m.mailchimp_synced_flag"
            )
        );
    }

    /**
     * @param $collection
     */
    protected function buildMailchimpDataWhere($collection)
    {
        $whereCreateBatchJson = "m4m.mailchimp_sync_delta IS null OR m4m.mailchimp_sync_modified = 1";
        $collection->getSelect()->where($whereCreateBatchJson);
    }

    /**
     * @param $childId
     * @param $magentoStoreId
     * @return string|null
     */
    public function getNotVisibleProductUrl($childId, $magentoStoreId)
    {
        $helper = $this->getHelper();
        $parentId = null;

        if (!$this->_parentId) {
            $parentId = $this->getParentId($childId);
        } else {
            $parentId = $this->_parentId;
        }

        if ($parentId) {
            $collection = $this->getProductWithAttributesById($magentoStoreId, $parentId);
            $rc = $helper->getProductResourceModel();

            if ($this->_parentUrl) {
                $url = $this->_parentUrl;
            } else {
                $path = $rc->getAttributeRawValue($parentId, 'url_path', $magentoStoreId);
                $url = $this->getUrlByPath($path, $magentoStoreId);
            }

            $tailUrl = '#';
            $count = 0;

            foreach ($collection as $attribute) {
                if ($attribute->getAttributeId()) {
                    $attributeId = $attribute->getAttributeId();
                    $attributeValue = $rc->getAttributeRawValue(
                        $childId,
                        $attribute->getAttributeId(),
                        $magentoStoreId
                    );
                    if ($count > 0) {
                        $tailUrl .= '&';
                    }

                    $tailUrl .= $attributeId . '=' . $attributeValue;
                }

                $count++;
            }

            if ($tailUrl != '#') {
                $url .= $tailUrl;
            }
        } else {
            $url = null;
        }

        return $url;
    }

    /**
     * @param $childId
     * @param $magentoStoreId
     * @return string|null
     * @throws Mage_Core_Model_Store_Exception
     */
    public function getParentImageUrl($childId, $magentoStoreId)
    {
        $imageUrl = null;
        $parentId = null;

        if (!$this->_parentId) {
            $parentId = $this->getParentId($childId);
        } else {
            $parentId = $this->_parentId;
        }

        if ($parentId) {
            $helper = $this->getHelper();
            $imageUrl = $helper->getImageUrlById($parentId, $magentoStoreId);
        }

        return $imageUrl;
    }

    /**
     * @param $product
     * @return mixed
     */
    protected function getProductUrl($product)
    {
        return $product->getProductUrl();
    }

    /**
     * @param $product
     * @param $magentoStoreId
     * @return string|null
     */
    public function getProductCategories($product, $magentoStoreId)
    {
        $categoryIds = $product->getResource()->getCategoryIds($product);
        $categoryNames = array();
        $categoryName = null;

        if (is_array($categoryIds) && !empty($categoryIds)) {
            $collection = $this->makeCatalogCategory()->getCollection();
            $collection->addAttributeToSelect(array('name'))
                ->setStoreId($magentoStoreId)
                ->addAttributeToFilter('is_active', array('eq' => '1'))
                ->addAttributeToFilter('entity_id', array('in' => $categoryIds))
                ->addAttributeToSort('level', 'asc')
                ->addAttributeToSort('name', 'asc');

            foreach ($collection as $category) {
                $categoryNames[] = $category->getName();
            }

            $categoryName = (count($categoryNames)) ? implode(" - ", $categoryNames) : 'None';
        }

        return $categoryName;
    }

    /**
     * @param $childId
     * @return mixed
     */
    protected function getParentId($childId)
    {
        $parentId = null;
        $parentIds = $this->getAllParentIds($childId);

        if (!empty($parentIds)) {
            $parentId = $parentIds[0];
        }

        return $parentId;
    }

    /**
     * @param $childId
     * @return mixed
     */
    protected function getAllParentIds($childId)
    {
        $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
            ->getParentIdsByChild($childId);
        return $parentIds;
    }

    /**
     * @param $magentoStoreId
     * @param $parentId
     * @return Mage_Catalog_Model_Resource_Product_Collection
     */
    protected function getProductWithAttributesById($magentoStoreId, $parentId)
    {
        $tableName = Mage::getSingleton('core/resource')->getTableName('catalog/product_super_attribute');
        $eavTableName = Mage::getSingleton('core/resource')->getTableName('eav/attribute');

        $collection = $this->getProductResourceCollection();
        $collection->addStoreFilter($magentoStoreId);
        $collection->addFieldToFilter('entity_id', array('eq' => $parentId));

        $collection->getSelect()->joinLeft(
            array("super_attribute" => $tableName),
            'entity_id=super_attribute.product_id'
        );

        $collection->getSelect()->joinLeft(
            array("eav_attribute" => $eavTableName),
            'super_attribute.attribute_id=eav_attribute.attribute_id'
        );
        $collection->getSelect()->reset(Zend_Db_Select::COLUMNS)->columns('eav_attribute.attribute_id');
        return $collection;
    }

    /**
     * @param $product
     * @param $magentoStoreId
     * @return mixed|null
     */
    protected function getMailChimpImageUrl($product, $magentoStoreId)
    {
        $imageUrl = $this->getHelper()
            ->getMailChimpProductImageUrl(
                $this->_parentImageUrl,
                $this->getHelper()->getImageUrlById(
                    $product->getId(),
                    $magentoStoreId
                )
            );
        if (!$imageUrl) {
            $imageUrl = $this->getParentImageUrl($product->getId(), $magentoStoreId);
        }

        return $imageUrl;
    }

    /**
     * @param $product
     * @param $magentoStoreId
     * @return float
     */
    protected function getMailChimpProductPrice($product, $magentoStoreId)
    {
        $price = null;
        $parentId = null;
        if (!$this->currentProductIsVisible()) {
            $parentId = $this->getParentId($product->getId());
            if ($parentId) {
                $price = $this->getMailchimpFinalPrice($product, $magentoStoreId);
            }
        } else {
            if ($this->_parentPrice) {
                $price = $this->_parentPrice;
            }
        }

        return $price;
    }

    /**
     * @return bool
     */
    protected function currentProductIsVisible()
    {
        return $this->_visibility != Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE;
    }

    /**
     * @param $path
     * @param $magentoStoreId
     * @return string
     */
    protected function getUrlByPath($path, $magentoStoreId)
    {
        $url = Mage::getUrl($path, array('_store' => $magentoStoreId));
        return $url;
    }

    /**
     * @return mixed
     */
    protected function isProductFlatTableEnabled()
    {
        return Mage::helper('catalog/category_flat')->isEnabled();
    }

    /**
     * @return false|Mage_Core_Model_Abstract
     */
    protected function makeCatalogCategory()
    {
        return Mage::getModel('catalog/category');
    }

    /**
     * @param $productId
     * @return Mage_Catalog_Model_Product
     */
    protected function loadProductById($productId)
    {
        return Mage::getModel('catalog/product')->load($productId);
    }

    /**
     * @param $mailchimpStoreId
     * @param $deletedProducts
     * @param $mailchimpTableName
     */
    protected function joinMailchimpSyncDataDeleted($mailchimpStoreId, $deletedProducts)
    {
        $mailchimpTableName = $this->getMailchimpEcommerceDataTableName();
        $deletedProducts->getSelect()->joinLeft(
            array('m4m' => $mailchimpTableName),
            "m4m.related_id = e.entity_id AND m4m.type = '" . Ebizmarts_MailChimp_Model_Config::IS_PRODUCT
            . "' AND m4m.mailchimp_store_id = '" . $mailchimpStoreId . "'",
            array('m4m.*')
        );
        $deletedProducts->getSelect()->where("m4m.mailchimp_sync_deleted = 1");
        $deletedProducts->getSelect()->where("m4m.mailchimp_sync_error = ''");

        $deletedProducts->getSelect()->limit($this->getBatchLimitFromConfig());
    }

    /**
     * @param string $visibility Visibility.
     * @return int or null
     */
    protected function getVisibility($visibility)
    {
        if (array_key_exists($visibility, $this->_visibilityOptions)) {
            return $this->_visibilityOptions[$visibility];
        }

        return null;
    }

    /**
     * Return price with tax if setting enabled.
     *
     * @param  $product
     * @param  $magentoStoreId
     * @return float \ return the price of the product
     * @throws Mage_Core_Exception
     */
    protected function getMailchimpFinalPrice($product, $magentoStoreId)
    {
        $helper = $this->getHelper();
        $price = Mage::helper('tax')
            ->getPrice(
                $product,
                $product->getFinalPrice(),
                $helper->isIncludeTaxesEnabled($magentoStoreId)
            );

        return $price;
    }

    /**
     * Sync to mailchimp the special price of the products
     *
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     */
    public function _markSpecialPrices($mailchimpStoreId, $magentoStoreId)
    {
        /**
         * get the products with current special price that are not synced and mark it as modified
         */
        $resource = $this->getCoreResource();
        $connection = $resource->getConnection('core_write');

        $collection = $this->getProductResourceCollection();
        $collection->addStoreFilter($magentoStoreId);

        $this->joinMailchimpSyncDataForSpecialPrices($collection, $mailchimpStoreId);

        $collection->addAttributeToFilter(
            'special_price',
            array('gt' => 0),
            'left'
        )->addAttributeToFilter(
            'special_from_date',
            array('lteq' => $this->getDateHelper()->formatDate() . " 23:59:59"),
            'left'
        )->addAttributeToFilter(
            'special_from_date',
            array('gt' => new Zend_Db_Expr('m4m.mailchimp_sync_delta')),
            'left'
        );

        $whereCondition = $connection->quoteInto(
            'm4m.mailchimp_sync_delta IS NOT NULL '
            . 'AND m4m.mailchimp_sync_delta < ?',
            $this->getDateHelper()->formatDate() . " 00:00:00"
        );
        $collection->getSelect()->where($whereCondition);

        foreach ($collection as $item) {
            $this->update($item->getEntityId(), $mailchimpStoreId);
        }

        /**
         * get the products that was synced when it have special price and have no more special price
         */
        $collectionNoSpecialPrice = $this->getProductResourceCollection();
        $collectionNoSpecialPrice->addStoreFilter($magentoStoreId);
        $this->joinMailchimpSyncDataForSpecialPrices($collectionNoSpecialPrice, $mailchimpStoreId);

        $collectionNoSpecialPrice->addAttributeToFilter(
            'special_price',
            array('gt' => 0),
            'left'
        )->addAttributeToFilter(
            'special_to_date',
            array('lt' => $this->getDateHelper()->formatDate() . " 00:00:00"),
            'left'
        )->addAttributeToFilter(
            'special_to_date',
            array('gt' => new Zend_Db_Expr('m4m.mailchimp_sync_delta')),
            'left'
        );

        $collectionNoSpecialPrice->getSelect()->where($whereCondition);

        foreach ($collectionNoSpecialPrice as $item) {
            $this->update($item->getEntityId(), $mailchimpStoreId);
        }
    }

    /**
     * @param $productId
     * @return bool | return true if the product is enabled in Magento.
     */
    public function isProductEnabled($productId, $magentoStoreId)
    {
        $isProductEnabled = false;
        $status = $this->getCatalogProductStatusModel()->getProductStatus($productId, $magentoStoreId);

        if ($status[$productId] == self::PRODUCT_IS_ENABLED) {
            $isProductEnabled = true;
        }

        return $isProductEnabled;
    }

    /**
     * @return Mage_Catalog_Model_Product_Status
     */
    protected function getCatalogProductStatusModel()
    {
        return Mage::getModel('catalog/product_status');
    }

    /**
     * @return string
     */
    protected function getClassConstant()
    {
        return Ebizmarts_MailChimp_Model_Config::IS_PRODUCT;
    }
}
