<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\McpWrite\Model\Helper\CustomerGroupResolver;
use Freento\McpWrite\Model\Helper\DateValue;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Data\Customer;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Update customer account fields through CustomerRepositoryInterface::save
 */
class UpdateCustomer extends AbstractWriteTool
{
    /** Argument name => CustomerInterface field name */
    private const FIELDS = [
        'firstname' => CustomerInterface::FIRSTNAME,
        'lastname' => CustomerInterface::LASTNAME,
        'middlename' => CustomerInterface::MIDDLENAME,
        'prefix' => CustomerInterface::PREFIX,
        'suffix' => CustomerInterface::SUFFIX,
        'taxvat' => CustomerInterface::TAXVAT,
        'dob' => CustomerInterface::DOB,
        'new_email' => CustomerInterface::EMAIL,
    ];

    /** Fields that must keep a value: a blank one would pass Magento's required check */
    private const REQUIRED_FIELDS = [CustomerInterface::FIRSTNAME, CustomerInterface::LASTNAME];

    /**
     * @param Context $context
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerGroupResolver $groupResolver
     * @param StoreManagerInterface $storeManager
     * @param DataObjectHelper $dataObjectHelper
     * @param DateValue $dateValue
     */
    public function __construct(
        Context $context,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerGroupResolver $groupResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly DataObjectHelper $dataObjectHelper,
        private readonly DateValue $dateValue
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_customer';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update a customer account: group, name, email, date of birth, tax/VAT number.

Identify the customer by customer_id, or by email (+ website_id for per-website accounts).
Only the given fields change. Addresses are not handled here.

Example prompts:
- "Move customer john@example.com to the Wholesale group"
- "Rename customer 42 to Jane Doe"';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Use this OR email'],
                'email' => ['type' => 'string', 'description' => 'Current email. Use this OR customer_id'],
                'website_id' => ['type' => 'integer', 'description' => 'Website of the account when looking up by email. Default: default website'],
                'group' => ['type' => 'string', 'description' => 'New customer group code or ID'],
                'new_email' => ['type' => 'string'],
                'firstname' => ['type' => 'string'],
                'lastname' => ['type' => 'string'],
                'middlename' => ['type' => 'string'],
                'prefix' => ['type' => 'string'],
                'suffix' => ['type' => 'string'],
                'dob' => ['type' => 'string', 'description' => 'Date of birth YYYY-MM-DD'],
                'taxvat' => ['type' => 'string', 'description' => 'Tax / VAT number'],
            ] + $this->dryRunProperty(),
            'examples' => [
                ['email' => 'john@example.com', 'group' => 'Wholesale'],
                ['customer_id' => 42, 'firstname' => 'Jane', 'lastname' => 'Doe'],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(array $arguments, bool $dryRun): string
    {
        /** @var Customer $customer */
        $customer = $this->load($arguments);

        // __toArray() returns a copy, so the old values survive populateWithArray() below.
        $before = $customer->__toArray();

        $changes = [];
        foreach (self::FIELDS as $argument => $field) {
            if (!isset($arguments[$argument])) {
                continue;
            }
            // Magento rejects an empty name but not a blank one; other fields may be cleared with "".
            $value = $this->stringArgument($arguments, $argument);
            if ($value === '' && in_array($field, self::REQUIRED_FIELDS, true)) {
                throw new LocalizedException(__('Argument "%1" cannot be empty.', $argument));
            }
            if ($field === CustomerInterface::DOB) {
                // The repository keeps the stored value for null; "" clears it
                $value = $this->dateValue->normalize($value, __('Argument "%1"', $argument)) ?? '';
            }
            $changes[$argument] = [$field, $value];
        }
        $this->dataObjectHelper->populateWithArray(
            $customer,
            array_column($changes, 1, 0),
            CustomerInterface::class
        );

        $groupLine = null;
        if (isset($arguments['group'])) {
            $group = $this->groupResolver->resolve($arguments['group'], false);
            $current = $this->groupResolver->resolve((int)$customer->getGroupId());
            $groupLine = sprintf(
                '  group: %s (%d) -> %s (%d)',
                $current->getCode(),
                $current->getId(),
                $group->getCode(),
                $group->getId()
            );
            $customer->setGroupId((int)$group->getId());
        }

        if (!$changes && $groupLine === null) {
            throw new LocalizedException(__(
                'Nothing to change. Pass at least one of these fields: %1.',
                implode(', ', ['group', ...array_keys(self::FIELDS)])
            ));
        }

        if (!$dryRun) {
            /** @var Customer $customer */
            $customer = $this->customerRepository->save($customer);
        }

        $after = $customer->__toArray();
        $lines = [];
        foreach ($changes as $argument => [$field]) {
            $lines[] = sprintf('  %s: "%s" -> "%s"', $argument, $before[$field] ?? '', $after[$field] ?? '');
        }
        if ($groupLine !== null) {
            $lines[] = $groupLine;
        }

        return sprintf(
            "%s customer %s %s (id %d):\n%s",
            $dryRun ? 'Would update' : 'Updated',
            $customer->getFirstname(),
            $customer->getLastname(),
            $customer->getId(),
            implode("\n", $lines)
        );
    }

    /**
     * Load by id or by email
     *
     * @param array $arguments
     * @return CustomerInterface
     * @throws LocalizedException
     */
    private function load(array $arguments): CustomerInterface
    {
        if (!empty($arguments['customer_id']) && !empty($arguments['email'])) {
            throw new LocalizedException(__('Give either customer_id or email, not both.'));
        }

        if (!empty($arguments['customer_id'])) {
            return $this->customerRepository->getById((int)$arguments['customer_id']);
        }
        if (!empty($arguments['email'])) {
            $websiteId = (int)($arguments['website_id'] ?? $this->storeManager->getDefaultStoreView()->getWebsiteId());

            return $this->customerRepository->get($this->stringArgument($arguments, 'email'), $websiteId);
        }

        throw new LocalizedException(__('Specify customer_id or email.'));
    }
}
