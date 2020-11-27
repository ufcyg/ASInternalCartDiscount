<?php declare(strict_types=1);

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

class InternalCartDiscountCollector implements CartProcessorInterface
{
    /** @var PercentagePriceCalculator */
    private $calculator;
    /** @var SystemConfigService $systemConfigService */
    private $systemConfigService;

    public function __construct(PercentagePriceCalculator $calculator, SystemConfigService $systemConfigService)
    {
        $this->calculator = $calculator;
        $this->systemConfigService = $systemConfigService;
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $products = $this->getCartItems($toCalculate);

        if (!$this->systemConfigService->get('ASInternalCartDiscount.config.active') )
        {
            return;
        }
        //check for customer group
        $allowedCustomerGroup = $this->systemConfigService->get('ASInternalCartDiscount.config.discountedCustomerGroup');
        $continue = false;

        /** @var CustomerEntity $customerEntity */
        $customerEntity = $context->getCustomer();
        foreach ($allowedCustomerGroup as $allowedGrp)
        {
            if ($customerEntity === null)
            {break;}
            if($allowedGrp === $context->getCustomer()->getGroupId())
            {$continue = true;}
        }
        if (!$continue)
        {return;}

        $discountLineItem = $this->createDiscount('INTERNAL_DISCOUNT');

        // declare price definition to define how this price is calculated
        $definition = new PercentagePriceDefinition(
            -100,
            $context->getContext()->getCurrencyPrecision(),
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
}