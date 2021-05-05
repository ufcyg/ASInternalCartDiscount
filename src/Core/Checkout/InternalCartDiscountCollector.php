<?php

declare(strict_types=1);

namespace ASInternalCartDiscount\Core\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\PercentagePriceDefinition;
use Shopware\Core\Checkout\Cart\Rule\LineItemRule;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Cart\Order\IdStruct;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class InternalCartDiscountCollector implements CartProcessorInterface
{
    /** @var PercentagePriceCalculator */
    private $calculator;
    /** @var SystemConfigService $systemConfigService */
    private $systemConfigService;
    /** @var ContainerInterface $container */
    protected $container;

    public function __construct(PercentagePriceCalculator $calculator, SystemConfigService $systemConfigService)
    {
        $this->calculator = $calculator;
        $this->systemConfigService = $systemConfigService;
    }
    /** @internal @required */
    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        $previous = $this->container;
        $this->container = $container;

        return $previous;
    }
    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $products = $this->getCartItems($toCalculate);
        $cartExtension = $toCalculate->getExtensions();
        if (array_key_exists('originalId', $cartExtension)) {
            $originalOrderIDStruct = $cartExtension['originalId'];
            /** @var IdStruct $originalOrderIDStruct */
            $originalOrderID = $originalOrderIDStruct->getId();
            $orderLineItemRepository = $this->container->get('order_line_item.repository');

            $orderLineItems = $this->getFilteredEntitiesOfRepository($orderLineItemRepository, 'orderId', $originalOrderID, $context->getContext());
            /** @var OrderLineItemEntity $orderLineItem */
            foreach ($orderLineItems as $orderLineItemID => $orderLineItem) {
                if ($orderLineItem->getIdentifier() == 'INTERNAL_DISCOUNT') {
                    $orderLineItemRepository->delete([['id' => $orderLineItemID]], $context->getContext());
                }
            }
        }

        if (count($products) == 0)
            return;
        if (!$this->systemConfigService->get('ASInternalCartDiscount.config.active')) {
            return;
        }
        //check for customer group
        $allowedCustomerGroup = $this->systemConfigService->get('ASInternalCartDiscount.config.discountedCustomerGroup');
        $continue = false;

        /** @var CustomerEntity $customerEntity */
        $customerEntity = $context->getCustomer();
        foreach ($allowedCustomerGroup as $allowedGrp) {
            if ($customerEntity === null) {
                break;
            }
            if ($allowedGrp === $context->getCustomer()->getGroupId()) {
                $continue = true;
            }
        }
        if (!$continue) {
            return;
        }
        $discountLineItem = null;
        /** @var LineItem $lineItem */
        foreach ($products as $lineItemID => $lineItem) {
            if ($lineItem->getLabel() == 'Internal customer discount') {
                $discountLineItem = $lineItem;
                break;
            }
        }
        if ($discountLineItem == null)
            $discountLineItem = $this->createDiscount('INTERNAL_DISCOUNT');

        // declare price definition to define how this price is calculated
        $definition = new PercentagePriceDefinition(
            -100,
            new LineItemRule(LineItemRule::OPERATOR_EQ, $products->getKeys())
        );

        $discountLineItem->setPriceDefinition($definition);

        // calculate price
        $discountLineItem->setPrice(
            $this->calculator->calculate($definition->getPercentage(), $products->getPrices(), $context)
        );

        // add discount to new cart
        $toCalculate->add($discountLineItem);
    }

    private function getCartItems(Cart $cart): LineItemCollection
    {
        return $cart->getLineItems();
    }

    private function createDiscount(string $name): LineItem
    {
        $discountLineItem = new LineItem($name, 'internal_discount', null, 1);

        $discountLineItem->setLabel('Internal customer discount');
        $discountLineItem->setGood(false);
        $discountLineItem->setStackable(false);
        $discountLineItem->setRemovable(false);

        return $discountLineItem;
    }


    public function getAllEntitiesOfRepository(EntityRepositoryInterface $repository, Context $context): ?EntitySearchResult
    {
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria, $context);

        return $result;
    }
    public function getFilteredEntitiesOfRepository(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): ?EntitySearchResult
    {
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria, $context);

        return $result;
    }
    public function entityExistsInRepositoryCk(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): bool
    {
        $criteria = new Criteria();

        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));

        /** @var EntitySearchResult $searchResult */
        $searchResult = $repository->search($criteria, $context);

        return count($searchResult) != 0 ? true : false;
    }
}
