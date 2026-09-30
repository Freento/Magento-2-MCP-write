<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Helper;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;

/**
 * Finds a customer group by ID or by code ("Wholesale")
 */
class CustomerGroupResolver
{
    /**
     * @param GroupRepositoryInterface $groupRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Find the group
     *
     * @param int|string $idOrCode
     * @param bool $allowNotLoggedIn False when the group goes onto a customer account:
     *        NOT LOGGED IN is reserved for guests, so it is neither matched nor suggested
     * @return GroupInterface
     * @throws LocalizedException
     */
    public function resolve(int|string $idOrCode, bool $allowNotLoggedIn = true): GroupInterface
    {
        $groups = $this->groupRepository->getList($this->searchCriteriaBuilder->create())->getItems();
        if (!$allowNotLoggedIn) {
            $groups = array_filter(
                $groups,
                static fn (GroupInterface $group) => (int)$group->getId() !== Group::NOT_LOGGED_IN_ID
            );
        }

        foreach ($groups as $group) {
            if ((is_numeric($idOrCode) && (int)$group->getId() === (int)$idOrCode)
                || strcasecmp((string)$group->getCode(), trim((string)$idOrCode)) === 0
            ) {
                return $group;
            }
        }

        if (!$allowNotLoggedIn && $this->isNotLoggedIn($idOrCode)) {
            throw new LocalizedException(
                __('Customer group "NOT LOGGED IN" (0) is reserved for guests and cannot be assigned to a customer.')
            );
        }

        throw new LocalizedException(__(
            'Customer group "%1" not found. Available: %2',
            $idOrCode,
            implode(', ', array_map(static fn (GroupInterface $group) => $group->getCode() . ' (' . $group->getId() . ')', $groups))
        ));
    }

    /**
     * Whether the argument names the guest group by id or by code
     *
     * @param int|string $idOrCode
     * @return bool
     */
    private function isNotLoggedIn(int|string $idOrCode): bool
    {
        return (is_numeric($idOrCode) && (int)$idOrCode === Group::NOT_LOGGED_IN_ID)
            || strcasecmp(trim((string)$idOrCode), 'NOT LOGGED IN') === 0;
    }
}
