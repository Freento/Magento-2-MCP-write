<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Helper;

use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Converts product attribute values coming from the AI into the form Magento stores.
 *
 * select / boolean: option label ("Red", "Enabled", "Yes") or option id -> option id
 * multiselect: list or comma separated labels -> "id,id"
 * everything else is passed through; the attribute backend model validates it on save.
 */
class AttributeValueResolver
{
    /**
     * Codes that identify or structure the product. Never accepted from "attributes":
     * setting one re-points the save at another entity or bypasses the dedicated argument.
     */
    private const IDENTITY_CODES = [
        'sku',
        'entity_id',
        'row_id',
        'type_id',
        'attribute_set_id',
        'website_ids',
        'category_ids',
    ];

    /**
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param ProductAttributeManagementInterface $attributeManagement
     * @param AttributeSetRepositoryInterface $attributeSetRepository
     * @param DateValue $dateValue
     */
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly ProductAttributeManagementInterface $attributeManagement,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly DateValue $dateValue
    ) {
    }

    /**
     * Refuse codes the product's attribute set does not contain: the EAV save would drop them without a word
     *
     * A code that is not a product attribute at all is reported as such.
     *
     * @param string[] $codes
     * @param int $attributeSetId
     * @param string $sku
     * @throws LocalizedException
     */
    public function assertInSet(array $codes, int $attributeSetId, string $sku): void
    {
        $inSet = array_map(
            static fn (AttributeInterface $attribute) => $attribute->getAttributeCode(),
            $this->attributeManagement->getAttributes((string)$attributeSetId)
        );
        $missing = array_diff($codes, $inSet);
        foreach ($missing as $code) {
            $this->getAttribute($code);
        }

        if ($missing) {
            throw new LocalizedException(__(
                'Attribute "%1" is not in attribute set "%2" (%3) of %4, so Magento would not save it.',
                implode('", "', $missing),
                $this->attributeSetRepository->get($attributeSetId)->getAttributeSetName(),
                $attributeSetId,
                $sku
            ));
        }
    }

    /**
     * Reject attribute codes that must not come from the "attributes" argument
     *
     * Reserved are the identity codes plus every argument of the calling tool: a value that has
     * its own argument must arrive through it, otherwise "attributes" silently wins over it.
     * Comparison ignores case and surrounding spaces, which MySQL collation would ignore as well.
     *
     * @param array $attributes
     * @param string[] $toolArguments Product fields the calling tool takes as dedicated arguments
     * @throws LocalizedException
     */
    public function assertNoReservedCodes(array $attributes, array $toolArguments = []): void
    {
        $reserved = array_merge(self::IDENTITY_CODES, array_map('strtolower', $toolArguments));
        $clash = [];
        foreach (array_keys($attributes) as $code) {
            if (in_array(strtolower(trim((string)$code)), $reserved, true)) {
                $clash[] = (string)$code;
            }
        }

        if ($clash) {
            throw new LocalizedException(__(
                'Cannot set "%1" through "attributes": it identifies the product or has its own argument in this tool. '
                . 'Use that argument; a custom attribute with the same code can be set with update_product_attributes.',
                implode('", "', $clash)
            ));
        }
    }

    /**
     * Scope of the attribute: "global", "website" or "store"
     *
     * @param string $code
     * @return string
     * @throws LocalizedException
     */
    public function getScope(string $code): string
    {
        return (string)$this->getAttribute($code)->getScope();
    }

    /**
     * Storable value for the attribute
     *
     * @param string $code
     * @param mixed $value
     * @return mixed
     * @throws LocalizedException
     */
    public function resolve(string $code, mixed $value): mixed
    {
        $attribute = $this->getAttribute($code);
        $input = $attribute->getFrontendInput();

        // Only a multiselect takes a list; anything else non-scalar would reach the backend as "Array".
        if ($input === 'multiselect' && is_array($value)) {
            return $this->optionIds($attribute, $value);
        }

        if ($value !== null && !is_scalar($value)) {
            throw new LocalizedException(
                __('Attribute "%1" expects a single value, got %2.', $code, get_debug_type($value))
            );
        }

        // Magento's date backend also accepts d-m-Y and guesses at 01-02-2026; hold callers to the documented form.
        if ($input === 'date') {
            return $this->dateValue->normalize((string)$value, __('Attribute "%1"', $code));
        }

        if ($value === null || $value === '' || !in_array($input, ['select', 'multiselect', 'boolean'], true)) {
            return $value;
        }

        if ($input === 'multiselect') {
            return $this->optionIds($attribute, explode(',', (string)$value));
        }

        if ($input === 'boolean' && is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        return $this->optionId($attribute, trim((string)$value));
    }

    /**
     * Readable form of a stored value: "Red (58)" for options, the value itself otherwise
     *
     * @param string $code
     * @param mixed $stored
     * @return string
     */
    public function describe(string $code, mixed $stored): string
    {
        if ($stored === null || $stored === '') {
            return '(empty)';
        }

        $attribute = $this->getAttribute($code);
        if (!in_array($attribute->getFrontendInput(), ['select', 'multiselect', 'boolean'], true)) {
            return (string)$stored;
        }

        $labels = [];
        foreach (explode(',', (string)$stored) as $id) {
            $label = $attribute->getSource()->getOptionText($id);
            $labels[] = ($label ? $label : '?') . ' (' . $id . ')';
        }

        return implode(', ', $labels);
    }

    /**
     * "id,id" for a list of option labels or ids
     *
     * @param Attribute $attribute
     * @param array $labels
     * @return string
     * @throws LocalizedException
     */
    private function optionIds(Attribute $attribute, array $labels): string
    {
        return implode(',', array_map(fn ($label) => $this->optionId($attribute, trim((string)$label)), $labels));
    }

    /**
     * Option id by label or by id
     *
     * @param Attribute $attribute
     * @param string $labelOrId
     * @return string
     * @throws LocalizedException
     */
    private function optionId(Attribute $attribute, string $labelOrId): string
    {
        $options = $attribute->getSource()->getAllOptions();

        foreach ($options as $option) {
            $id = (string)($option['value'] ?? '');
            if ($id !== '' && ($id === $labelOrId || strcasecmp((string)$option['label'], $labelOrId) === 0)) {
                return $id;
            }
        }

        $available = array_filter(array_map(
            static fn ($option) => (string)$option['value'] !== '' ? $option['label'] . ' (' . $option['value'] . ')' : '',
            $options
        ));

        throw new LocalizedException(__(
            'Attribute "%1" has no option "%2". Available: %3',
            $attribute->getAttributeCode(),
            $labelOrId,
            implode(', ', $available)
        ));
    }

    /**
     * Load attribute by code
     *
     * @param string $code
     * @return Attribute
     * @throws LocalizedException
     */
    private function getAttribute(string $code): Attribute
    {
        try {
            /** @var Attribute $attribute */
            $attribute = $this->attributeRepository->get($code);
        } catch (\Exception $e) {
            throw new LocalizedException(__('Product attribute "%1" does not exist.', $code));
        }

        return $attribute;
    }
}
