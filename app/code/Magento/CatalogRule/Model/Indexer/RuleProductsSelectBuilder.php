<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogRule\Model\Indexer;

/**
 * Build select for rule relation with product.
 * @since 2.2.0
 */
class RuleProductsSelectBuilder
{
    /**
     * @var \Magento\Framework\App\ResourceConnection
     * @since 2.2.0
     */
    private $resource;

    /**
     * @var \Magento\Eav\Model\Config
     * @since 2.2.0
     */
    private $eavConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     * @since 2.2.0
     */
    private $storeManager;

    /**
     * @var \Magento\Framework\EntityManager\MetadataPool
     * @since 2.2.0
     */
    private $metadataPool;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher
     * @since 2.2.0
     */
    private $activeTableSwitcher;

    /**
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Magento\Eav\Model\Config $eavConfig
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\EntityManager\MetadataPool $metadataPool
     * @param \Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher $activeTableSwitcher
     * @since 2.2.0
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\EntityManager\MetadataPool $metadataPool,
        \Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher $activeTableSwitcher
    ) {
        $this->eavConfig = $eavConfig;
        $this->storeManager = $storeManager;
        $this->metadataPool = $metadataPool;
        $this->resource = $resource;
        $this->activeTableSwitcher = $activeTableSwitcher;
    }

    /**
     * Build select for indexer according passed parameters.
     *
     * @param int $websiteId
     * @param \Magento\Catalog\Model\Product|null $product
     * @param bool $useAdditionalTable
     * @return \Zend_Db_Statement_Interface
     * @since 2.2.0
     */
    public function build(
        $websiteId,
        \Magento\Catalog\Model\Product $product = null,
        $useAdditionalTable = false
    ) {
        $connection = $this->resource->getConnection();
        $indexTable = $this->resource->getTableName('catalogrule_product');
        if ($useAdditionalTable) {
            $indexTable = $this->resource->getTableName(
                $this->activeTableSwitcher->getAdditionalTableName('catalogrule_product')
            );
        }

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
        $select = $connection->select()->from(
            ['rp' => $indexTable]
        )->order(
            ['rp.website_id', 'rp.customer_group_id', 'rp.product_id', 'rp.sort_order', 'rp.rule_id']
        );

        if ($product && $product->getEntityId()) {
            $select->where('rp.product_id=?', $product->getEntityId());
        }

        /**
         * Join default price and websites prices to result
         */
        $priceAttr = $this->eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, 'price');
        $priceTable = $priceAttr->getBackend()->getTable();
        $attributeId = $priceAttr->getId();

        $linkField = $this->metadataPool
            ->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class)
            ->getLinkField();
        $select->join(
            ['e' => $this->resource->getTableName('catalog_product_entity')],
            sprintf('e.entity_id = rp.product_id'),
            []
        );
        $joinCondition = '%1$s.' . $linkField . '=e.' . $linkField . ' AND (%1$s.attribute_id='
            . $attributeId
            . ') and %1$s.store_id=%2$s';

        $select->join(
            ['pp_default' => $priceTable],
            sprintf($joinCondition, 'pp_default', \Magento\Store\Model\Store::DEFAULT_STORE_ID),
            []
        );

        $website = $this->storeManager->getWebsite($websiteId);
        $defaultGroup = $website->getDefaultGroup();
        if ($defaultGroup instanceof \Magento\Store\Model\Group) {
            $storeId = $defaultGroup->getDefaultStoreId();
        } else {
            $storeId = \Magento\Store\Model\Store::DEFAULT_STORE_ID;
        }

        $select->joinInner(
            ['product_website' => $this->resource->getTableName('catalog_product_website')],
            'product_website.product_id=rp.product_id '
            . 'AND product_website.website_id = rp.website_id '
            . 'AND product_website.website_id='
            . $websiteId,
            []
        );

        $tableAlias = 'pp' . $websiteId;
        $select->joinLeft(
            [$tableAlias => $priceTable],
            sprintf($joinCondition, $tableAlias, $storeId),
            []
        );
        $select->columns([
            'default_price' => $connection->getIfNullSql($tableAlias . '.value', 'pp_default.value'),
        ]);

        return $connection->query($select);
    }
}