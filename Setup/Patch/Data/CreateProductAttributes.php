<?php
/**
 * Amadeco_ElasticSuiteBehavioral
 *
 * @category  Amadeco
 * @package   Amadeco_ElasticSuiteBehavioral
 * @author    Amadeco Core Team
 * @copyright Copyright (c) 2026 Amadeco
 * @license   Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Amadeco\ElasticSuiteBehavioral\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates Behavioral Merchandising Attributes
 *
 * This patch creates EAV attributes AND assigns them to ALL product attribute sets
 * using native Magento 2.4.8 framework methods.
 *
 * Prevents "getAttributeId() on false" errors during ProductAction::updateAttributes().
 */
class CreateProductAttributes implements DataPatchInterface
{
    /**
     * New product attributes to create.
     */
    public const PRODUCT_ATTRIBUTE_BEHAVIORAL_SCORE = 'behavioral_score';
    public const PRODUCT_ATTRIBUTE_IS_NEW_BOOST = 'is_new_boost';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    /**
     * Apply the data patch to create attributes and assign them to all attribute sets
     *
     * The attributes are empty containers in MySQL, but they become full of data inside Elasticsearch.
     *
     * @return self
     */
    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Attribute definitions
        $attributes = [
            self::PRODUCT_ATTRIBUTE_BEHAVIORAL_SCORE => [
                'type'                    => 'int',
                'label'                   => 'Behavioral Global Score',
                'input'                   => 'text',
                'required'                => false,
                'sort_order'              => 100,
                'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                'group'                   => 'General',
                'visible'                 => false,
                'used_in_product_listing' => false,
                'visible_on_front'        => false,
                'is_used_in_grid'         => false,
                'is_visible_in_grid'      => false,
                'is_filterable_in_grid'   => false,
                'used_for_sort_by'        => false,
                'user_defined'            => false,
                'default'                 => 0,
                'system'                  => 0,
                'note'                    => 'AI-calculated score (0-100) based on engagement.',
            ],
            self::PRODUCT_ATTRIBUTE_IS_NEW_BOOST => [
                'type'                    => 'int',
                'label'                   => 'Freshness Boost Factor',
                'input'                   => 'text',
                'required'                => false,
                'sort_order'              => 110,
                'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                'group'                   => 'General',
                'visible'                 => false,
                'used_in_product_listing' => false,
                'visible_on_front'        => false,
                'is_used_in_grid'         => false,
                'is_visible_in_grid'      => false,
                'is_filterable_in_grid'   => false,
                'used_for_sort_by'        => false,
                'user_defined'            => false,
                'default'                 => 0,
                'system'                  => 0,
                'note'                    => 'Internal decay factor for new product discovery.',
            ]
        ];

        // Step 1: Create attributes
        foreach ($attributes as $code => $properties) {
            if (!$eavSetup->getAttributeId(Product::ENTITY, $code)) {
                $eavSetup->addAttribute(Product::ENTITY, $code, $properties);
            }
        }

        // Step 2: CRITICAL - Assign to ALL existing product attribute sets
        $this->assignAttributesToAllSets($eavSetup, array_keys($attributes));

        return $this;
    }

    /**
     * Assigns specified attributes to ALL product attribute sets
     *
     * @param EavSetup $eavSetup
     * @param array<string> $attributeCodes
     * @return void
     */
    private function assignAttributesToAllSets(EavSetup $eavSetup, array $attributeCodes): void
    {
        // Get all product attribute sets using native method
        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
        $attributeSets = $eavSetup->getAllAttributeSetIds($entityTypeId);

        foreach ($attributeSets as $attributeSetId) {
            // Get the default attribute group for this set (usually "General")
            $attributeGroupId = $eavSetup->getDefaultAttributeGroupId(
                $entityTypeId,
                $attributeSetId
            );

            foreach ($attributeCodes as $attributeCode) {
                // Native method to add attribute to set
                // This is idempotent - safe to call multiple times
                $eavSetup->addAttributeToSet(
                    Product::ENTITY,
                    $attributeSetId,
                    $attributeGroupId,
                    $attributeCode,
                    999 // Sort order (end of group)
                );
            }
        }
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}