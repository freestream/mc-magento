<?php

class Ebizmarts_MailChimp_Model_Api_PromoCodesTest extends PHPUnit_Framework_TestCase
{
    protected $_promoCodesApiMock;

    const BATCH_ID = 'storeid-1_PCD_2017-05-18-14-45-54-38849500';

    const PROMOCODE_ID = 603;

    const MC_STORE_ID = 'a1s2d3f4g5h6j7k8l9n0';

    public function setUp()
    {
        Mage::app('default');

        /**
         * @var Ebizmarts_MailChimp_Model_Api_PromoCodes $apiPromoCodesMock promoCodesApiMock
         */
        $this->_promoCodesApiMock = $this->getMockBuilder(Ebizmarts_MailChimp_Model_Api_PromoCodes::class);
    }

    public function tearDown()
    {
        $this->_promoCodesApiMock = null;
    }

    public function testCreateBatchJson()
    {
        $magentoStoreId = 1;
        $batchArray = array();
        $promoCodesApiMock = $this->_promoCodesApiMock
            ->setMethods(array('_getDeletedPromoCodes', '_getNewPromoCodes'))
            ->getMock();

        $promoCodesApiMock
            ->expects($this->once())
            ->method('_getDeletedPromoCodes')
            ->with(self::MC_STORE_ID)
            ->willReturn($batchArray);
        $promoCodesApiMock
            ->expects($this->once())
            ->method('_getNewPromoCodes')
            ->with(self::MC_STORE_ID, $magentoStoreId)
            ->willReturn($batchArray);

        $promoCodesApiMock->createBatchJson(self::MC_STORE_ID, $magentoStoreId);
    }


    public function testMakePromoCodesCollection()
    {
        $magentoStoreId = 0;

        $promoCodesApiMock = $this->_promoCodesApiMock
            ->setMethods(
                array(
                    'getPromoCodeResourceCollection', 'addWebsiteColumn',
                    'joinPromoRuleData', 'getHelper'
                )
            )
            ->getMock();

        $promoCodesCollectionMock = $this->getMockBuilder(Mage_SalesRule_Model_Resource_Coupon_Collection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mailChimpHelperMock = $this->getMockBuilder(Ebizmarts_MailChimp_Helper_Data::class)
            ->disableOriginalConstructor()
            ->setMethods(array('addResendFilter'))
            ->getMock();

        $promoCodesApiMock->expects($this->once())->method('getHelper')->willReturn($mailChimpHelperMock);

        $promoCodesApiMock
            ->expects($this->once())
            ->method('getPromoCodeResourceCollection')
            ->willReturn($promoCodesCollectionMock);

        $mailChimpHelperMock
            ->expects($this->once())
            ->method('addResendFilter')
            ->with($promoCodesCollectionMock, $magentoStoreId, Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE);

        $promoCodesApiMock->expects($this->once())->method('addWebsiteColumn')->with($promoCodesCollectionMock);
        $promoCodesApiMock->expects($this->once())->method('joinPromoRuleData')->with($promoCodesCollectionMock);

        $return = $promoCodesApiMock->makePromoCodesCollection($magentoStoreId);

        $this->assertContains(Mage_SalesRule_Model_Resource_Coupon_Collection::class, get_class($return));
    }

    public function testMarkAsDeleted()
    {
        $promoRuleId = 1;
        $promoCodesApiMock = $this->_promoCodesApiMock
            ->setMethods(array('_setDeleted'))
            ->getMock();

        $promoCodesApiMock->expects($this->once())->method('_setDeleted')->with(self::PROMOCODE_ID, $promoRuleId);

        $promoCodesApiMock->markAsDeleted(self::PROMOCODE_ID, $promoRuleId);
    }

    public function testDeletePromoCodesSyncDataByRule()
    {
        $promoRuleId = 1;
        $promoCodesIds = array();
        $syncDataItems = array();

        $promoCodesApiMock = $this->_promoCodesApiMock
            ->setMethods(array('getPromoCodesForRule', 'getHelper'))
            ->getMock();

        $promoRuleMock = $this->getMockBuilder(Mage_SalesRule_Model_Rule::class)
            ->disableOriginalConstructor()
            ->setMethods(array('getRelatedId'))
            ->getMock();

        $mailChimpHelperMock = $this->getMockBuilder(Ebizmarts_MailChimp_Helper_Data::class)
            ->disableOriginalConstructor()
            ->setMethods(array('getAllEcommerceSyncDataItemsPerId'))
            ->getMock();

        $syncDataItemCollectionMock = $this
            ->getMockBuilder(Ebizmarts_MailChimp_Model_Resource_Ecommercesyncdata_Collection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncDataItemMock = $this->getMockBuilder(Ebizmarts_MailChimp_Model_Ecommercesyncdata::class)
            ->disableOriginalConstructor()
            ->setMethods(array('delete'))
            ->getMock();

        $promoRuleMock->expects($this->once())->method('getRelatedId')->willReturn($promoRuleId);

        $promoCodesIds[] = self::PROMOCODE_ID;
        $promoCodesApiMock
            ->expects($this->once())
            ->method('getPromoCodesForRule')
            ->with($promoRuleId)
            ->willReturn($promoCodesIds);
        $promoCodesApiMock->expects($this->once())->method('getHelper')->willReturn($mailChimpHelperMock);

        $mailChimpHelperMock
            ->expects($this->once())
            ->method('getAllEcommerceSyncDataItemsPerId')
            ->with(self::PROMOCODE_ID, Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE)
            ->willReturn($syncDataItemCollectionMock);
        $syncDataItems[] = $syncDataItemMock;
        $syncDataItemCollectionMock
            ->expects($this->once())
            ->method("getIterator")
            ->willReturn(new ArrayIterator($syncDataItems));

        $syncDataItemMock->expects($this->once())->method('delete');

        $promoCodesApiMock->deletePromoCodesSyncDataByRule($promoRuleMock);
    }

    public function testDeletePromoCodeSyncData()
    {
        $promoCodesApiMock = $this->_promoCodesApiMock
            ->setMethods(array('getHelper'))
            ->getMock();

        $mailChimpHelperMock = $this->getMockBuilder(Ebizmarts_MailChimp_Helper_Data::class)
            ->disableOriginalConstructor()
            ->setMethods(array('getEcommerceSyncDataItem'))
            ->getMock();

        $syncDataItemMock = $this->getMockBuilder(Ebizmarts_MailChimp_Model_Ecommercesyncdata::class)
            ->disableOriginalConstructor()
            ->setMethods(array('delete'))
            ->getMock();

        $promoCodesApiMock->expects($this->once())->method('getHelper')->willReturn($mailChimpHelperMock);

        $mailChimpHelperMock
            ->expects($this->once())
            ->method('getEcommerceSyncDataItem')
            ->with(self::PROMOCODE_ID, Ebizmarts_MailChimp_Model_Config::IS_PROMO_CODE, self::MC_STORE_ID)
            ->willReturn($syncDataItemMock);

        $syncDataItemMock->expects($this->once())->method('delete');

        $promoCodesApiMock->deletePromoCodeSyncData(self::PROMOCODE_ID, self::MC_STORE_ID);
    }
}
