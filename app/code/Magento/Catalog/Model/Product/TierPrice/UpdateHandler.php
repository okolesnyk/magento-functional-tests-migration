<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Product\TierPrice;

use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Backend\Tierprice;

/**
 * Process tier price data for handled existing product
 */
class UpdateHandler implements ExtensionInterface
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Catalog\Api\ProductAttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @var \Magento\Customer\Api\GroupManagementInterface
     */
    private $groupManagement;

    /**
     * @var \Magento\Framework\EntityManager\MetadataPool
     */
    private $metadataPoll;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\Backend\Tierprice
     */
    private $tierPriceResource;


    /**
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Catalog\Api\ProductAttributeRepositoryInterface $attributeRepository
     * @param \Magento\Customer\Api\GroupManagementInterface $groupManagement
     * @param \Magento\Framework\EntityManager\MetadataPool $metadataPool
     * @param \Magento\Catalog\Model\ResourceModel\Product\Attribute\Backend\Tierprice $tierPriceResource
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        ProductAttributeRepositoryInterface $attributeRepository,
        GroupManagementInterface $groupManagement,
        MetadataPool $metadataPool,
        Tierprice $tierPriceResource
    ) {
        $this->storeManager = $storeManager;
        $this->attributeRepository = $attributeRepository;
        $this->groupManagement = $groupManagement;
        $this->metadataPoll = $metadataPool;
        $this->tierPriceResource = $tierPriceResource;
    }

    /**
     * @param \Magento\Catalog\Api\Data\ProductInterface|object $entity
     * @param array $arguments
     * @return \Magento\Catalog\Api\Data\ProductInterface|object
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute($entity, $arguments = [])
    {
        $attribute = $this->attributeRepository->get('tier_price');
        $priceRows = $entity->getData($attribute->getName());
        if (null !== $priceRows) {
            $websiteId = $this->storeManager->getStore($entity->getStoreId())->getWebsiteId();
            $isGlobal = $attribute->isScopeGlobal() || $websiteId === 0;
            $identifierField = $this->metadataPoll->getMetadata(ProductInterface::class)->getLinkField();
            $priceRows = array_filter((array)$priceRows);
            $productId = $entity->getData($identifierField);
            $old = [];
            $new = [];

            // prepare original data for compare
            $origPrices = $entity->getOrigData($attribute->getName());
            if (is_array($origPrices)) {
                foreach ($origPrices as $data) {
                    if ($isGlobal === $this->isWebsiteGlobal($data['website_id'])) {
                        $key = $this->getPriceKey($data);
                        $old[$key] = $data;
                    }
                }
            }

            // prepare data for save
            foreach ($priceRows as $data) {
                if (empty($data['delete'])
                    || !empty($data['price_qty'])
                    || isset($data['cust_group'])
                    || ($isGlobal === $this->isWebsiteGlobal($data['website_id']))
                ) {
                    $key = $this->getPriceKey($data);
                    $new[$key] = $this->prepareTierPrice($data);
                }
            }

            $delete = array_diff_key($old, $new);
            $insert = array_diff_key($new, $old);
            $update = array_intersect_key($new, $old);

            $isAttributeChanged = $this->deleteValues($productId, $delete);
            $isAttributeChanged |= $this->insertValues($productId, $insert);
            $isAttributeChanged |= $this->updateValues($update, $old);

            if ($isAttributeChanged) {
                $valueChangedKey = $attribute->getName() . '_changed';
                $entity->setData($valueChangedKey, 1);
            }
        }

        return $entity;
    }

    /**
     * Get additional tier price fields
     *
     * @param array $objectArray
     * @return array
     */
    private function getAdditionalFields(array $objectArray): array
    {
        $percentageValue = $this->getPercentage($objectArray);
        return [
            'value' => $percentageValue ? null : $objectArray['price'],
            'percentage_value' => $percentageValue ?: null
        ];
    }

    /**
     * Check whether price has percentage value.
     *
     * @param array $priceRow
     * @return integer|null
     */
    private function getPercentage(array $priceRow)
    {
        return isset($priceRow['percentage_value']) && is_numeric($priceRow['percentage_value'])
            ? $priceRow['percentage_value']
            : null;
    }

    /**
     * Update existing tier prices for processed product
     *
     * @param array $valuesToUpdate
     * @param array $oldValues
     * @return boolean
     */
    private function updateValues(array $valuesToUpdate, array $oldValues): bool
    {
        $isChanged = false;
        foreach ($valuesToUpdate as $key => $value) {
            if ((float)$oldValues[$key]['price'] !== (float)$value['value']) {
                $price = new \Magento\Framework\DataObject(
                    [
                        'value_id' => $oldValues[$key]['price_id'],
                        'value' => $value['value']
                    ]
                );
                $this->tierPriceResource->savePriceData($price);
                $isChanged = true;
            }
        }

        return $isChanged;
    }

    /**
     * Insert new tier prices for processed product
     *
     * @param int $productId
     * @param array $valuesToInsert
     * @return bool
     */
    private function insertValues(int $productId, array $valuesToInsert): bool
    {
        $isChanged = false;
        foreach ($valuesToInsert as $data) {
            $price = new \Magento\Framework\DataObject($data);
            $price->setData(
                $this->metadataPoll->getMetadata(ProductInterface::class)->getLinkField(),
                $productId
            );
            $this->tierPriceResource->savePriceData($price);
            $isChanged = true;
        }

        return $isChanged;
    }

    /**
     * Delete tier price values for processed product
     *
     * @param int $productId
     * @param array $valuesToDelete
     * @return bool
     */
    private function deleteValues(int $productId, array $valuesToDelete): bool
    {
        $isChanged = false;
        foreach ($valuesToDelete as $data) {
            $this->tierPriceResource->deletePriceData($productId, null, $data['price_id']);
            $isChanged = true;
        }

        return $isChanged;
    }

    /**
     * Get generated price key based on price data
     *
     * @param array $priceData
     * @return string
     */
    private function getPriceKey(array $priceData): string
    {
        $key = implode(
            '-',
            array_merge([$priceData['website_id'], $priceData['cust_group']], [(int)$priceData['price_qty']])
        );

        return $key;
    }

    /**
     * Prepare tier price data by provided price row data
     *
     * @param array $data
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function prepareTierPrice(array $data): array
    {
        $useForAllGroups = (int)$data['cust_group'] === $this->groupManagement->getAllCustomersGroup()->getId();
        $customerGroupId = !$useForAllGroups ? $data['cust_group'] : 0;
        $tierPrice = array_merge(
            $this->getAdditionalFields($data),
            [
                'website_id' => $data['website_id'],
                'all_groups' => $useForAllGroups ? 1 : 0,
                'customer_group_id' => $customerGroupId,
                'value' => $data['price'] ?? null,
                'qty' => (int)$data['price_qty']
            ]
        );

        return $tierPrice;
    }

    /**
     * Check by id is website global
     *
     * @param string|int $websiteId
     * @return bool
     */
    private function isWebsiteGlobal($websiteId): bool
    {
        return (int)$websiteId === 0;
    }
}
