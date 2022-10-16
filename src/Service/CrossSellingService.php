<?php declare(strict_types=1);

namespace Frosh\CartCrossSelling\Service;

use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\ProductCloseoutFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\SearchRequestException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Parser\QueryStringParser;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\CountSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CrossSellingService
{
    private SalesChannelRepositoryInterface $productRepository;
    private EntityRepositoryInterface $productStreamRepository;
    private EntityDefinition $productDefinition;
    private SystemConfigService $systemConfigService;

    public function __construct(
        SalesChannelRepositoryInterface $productRepository,
        EntityRepositoryInterface       $productStreamRepository,
        EntityDefinition                $productDefinition,
        SystemConfigService             $systemConfigService
    )
    {
        $this->productRepository = $productRepository;
        $this->productStreamRepository = $productStreamRepository;
        $this->productDefinition = $productDefinition;
        $this->systemConfigService = $systemConfigService;
    }

    public function getCrossSellingProducts(SalesChannelContext $context, array $productIds): ?EntityCollection
    {
        $criteria = $this->createProductCriteria($context, $productIds);
        $this->addCrossSellingProducts($criteria, $context, $productIds);
        $result = $this->productRepository->search($criteria, $context);

        if ($result->first() === null) {
            $criteria = $this->createProductCriteria($context, $productIds);
            $this->addProductStreamFilters($criteria, $productIds, $context->getContext());
            $result = $this->productRepository->search($criteria, $context);
        }

        if ($result->first() === null) {
            // getCrossSellingSiblings
            $criteria = $this->createProductCriteria($context, $productIds);
            $criteria->addFilter(new EqualsFilter('crossSellingAssignedProducts.crossSelling.active', true));
            $criteria->addFilter(new EqualsAnyFilter('crossSellingAssignedProducts.crossSelling.productId', $productIds));
            $result = $this->productRepository->search($criteria, $context);
        }

        if ($result->first() === null) {
            return null;
        }

        return $result->getEntities();
    }

    private function addCrossSellingProducts(Criteria $criteria, SalesChannelContext $context, array $productIds): void
    {
        $search = new Criteria();
        $search->addAssociation('crossSellings.assignedProducts');
        $search->addFilter(new EqualsFilter('crossSellings.active', true));
        $search->addFilter(new EqualsAnyFilter('id', $productIds));
        $result = $this->productRepository->search($search, $context);

        // getCrossSellingSAssignedProductIds
        $ids = array_merge(...array_values($result->fmap(
            static function (ProductEntity $entity) {
                return array_merge(...array_values($entity->getCrossSellings()->fmap(
                    static function (ProductCrossSellingEntity $entity) {
                        return $entity->getAssignedProducts()->getIds();
                    }
                )));
            }
        )));

        $criteria->addFilter(new EqualsAnyFilter('id', $ids));
    }

    private function createProductCriteria(SalesChannelContext $context, array $excludeIds): Criteria
    {
        $criteria = new Criteria();
        $criteria->setLimit(100);
        $criteria->addAssociation('manufacturer')
            ->addAssociation('options.group');

        if ($this->systemConfigService->getBool(
            'core.listing.hideCloseoutProductsWhenOutOfStock',
            $context->getSalesChannelId()
        )) {
            $criteria->addFilter(new ProductCloseoutFilter());
        }
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsAnyFilter('id', $excludeIds)
        ]));
        $criteria->addSorting(
            new FieldSorting('markAsTopseller', FieldSorting::DESCENDING),
        );
        if (class_exists(CountSorting::class)) {
            $criteria->addSorting(
                new CountSorting('id', FieldSorting::DESCENDING)
            );
        }
        return $criteria;
    }

    private function addProductStreamFilters(Criteria $criteria, array $productIds, Context $context): void
    {
        $streamCriteria = new Criteria();
        $streamCriteria->addFilter(new EqualsFilter('productCrossSellings.active', true));
        $streamCriteria->addFilter(new EqualsAnyFilter('productCrossSellings.productId', $productIds));
        $result = $this->productStreamRepository->search($streamCriteria, $context);
        if ($result->first() === null) {
            return;
        }
        $filters = [];
        foreach ($result->getEntities() as $stream) {
            if (empty($stream->getApiFilter()[0]['type'])) {
                continue;
            }
            $filters[] = QueryStringParser::fromArray(
                $this->productDefinition,
                $stream->getApiFilter()[0],
                new SearchRequestException
            );
        }
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, $filters));
    }

    private static function addCustomerOrdersFilters(Criteria $criteria, SalesChannelContext $context): void
    {
        $customerId = $context->getCustomer()->getId();
        $criteria->addFilter(new EqualsFilter('orderLineItems.order.orderCustomer.customerId', $customerId));
    }

    public function getBuyAgainProducts(SalesChannelContext $context, array $productIds): ?EntityCollection
    {
        if ($context->getCustomer() === null) {
            return null;
        }
        $criteria = $this->createProductCriteria($context, $productIds);
        $this->addCustomerOrdersFilters($criteria, $context);
        $result = $this->productRepository->search($criteria, $context);
        if ($result->first() === null) {
            return null;
        }
        return $result->getEntities();
    }
}
