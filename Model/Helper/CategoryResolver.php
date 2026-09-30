<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Helper;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;

/**
 * Turns a "category_ids" tool argument into existing categories, in one query
 */
class CategoryResolver
{
    /**
     * @param CategoryCollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory
    ) {
    }

    /**
     * Validate the argument and load every category it names; unknown ids are reported all at once
     *
     * @param mixed $ids The raw "category_ids" argument
     * @return CategoryInterface[] Keyed by category id, in the order given
     * @throws LocalizedException
     */
    public function resolve(mixed $ids): array
    {
        if (!is_array($ids)) {
            throw new LocalizedException(__('Argument "category_ids" must be a list of category ids.'));
        }

        $clean = [];
        foreach ($ids as $id) {
            // intval() would turn "6abc" into 6 and 11.9 into 11: a wrong category, reported as success.
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new LocalizedException(
                    __('Category id "%1" is not an integer.', is_scalar($id) ? (string)$id : get_debug_type($id))
                );
            }

            $clean[] = (int)$id;
        }

        $clean = array_values(array_unique($clean));

        $categories = $this->load($clean);
        $missing = array_diff($clean, array_keys($categories));
        if ($missing) {
            throw new LocalizedException(__('No category with id: %1.', implode(', ', $missing)));
        }
        foreach ($categories as $category) {
            // Level 0 is the tree root, level 1 a store root: the admin never offers them for products.
            if ((int)$category->getLevel() < 2) {
                throw new LocalizedException(__(
                    'Category "%1" (%2) is a root category, products cannot be assigned to it.',
                    $category->getName(),
                    $category->getId()
                ));
            }
        }

        $ordered = [];
        foreach ($clean as $id) {
            $ordered[$id] = $categories[$id];
        }

        return $ordered;
    }

    /**
     * Categories by id, with only the name attribute selected
     *
     * @param int[] $ids
     * @return CategoryInterface[] Keyed by category id
     */
    public function load(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId(Store::DEFAULT_STORE_ID)
            ->addAttributeToSelect('name')
            ->addIdFilter($ids);

        /** @var CategoryInterface[] $categories */
        $categories = $collection->getItems();

        return $categories;
    }
}
