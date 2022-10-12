<?php declare(strict_types=1);

namespace Frosh\CartCrossSelling\Subscriber;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
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
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartPageSubscriber implements EventSubscriberInterface
{
    private const CROSS_SELLING_CONFIG = 'FroshCartCrossSelling.config.cartCrossSellingActive';
    private const CROSS_SELLING_OFF_CANVAS_CONFIG = 'FroshCartCrossSelling.config.offCanvasCrossSellingActive';
    private const PAYMENT_ICONS_CONFIG = 'FroshCartCrossSelling.config.paymentIconAlbum';

    private SalesChannelRepositoryInterface $productRepository;
    private SystemConfigService $systemConfigService;
    private EntityRepositoryInterface $mediaRepository;
    private EntityRepositoryInterface $productStreamRepository;
    private EntityDefinition $productDefinition;

    public function __construct(
        SalesChannelRepositoryInterface $productRepository,
        EntityRepositoryInterface       $productStreamRepository,
        EntityRepositoryInterface       $mediaRepository,
        EntityDefinition                $productDefinition,
        SystemConfigService             $systemConfigService
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->productRepository = $productRepository;
        $this->productStreamRepository = $productStreamRepository;
        $this->mediaRepository = $mediaRepository;
        $this->productDefinition = $productDefinition;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCartPage',
            OffcanvasCartPageLoadedEvent::class => 'onOffCanvas'
        ];
    }

    public function onCartPage(CheckoutCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $page = $event->getPage();
        $cart = $page->getCart();
        if ($this->systemConfigService->getBool(self::CROSS_SELLING_CONFIG, $context->getSalesChannelId())) {
            $page->addExtension('crossSelling', $this->getCrossSellingProducts($context, $cart));
        }
        $page->addExtension('paymentIcons', $this->getPaymentIcons($context));
    }

    public function onOffCanvas(OffcanvasCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $cart = $event->getPage()->getCart();
        if ($this->systemConfigService->getBool(self::CROSS_SELLING_OFF_CANVAS_CONFIG, $context->getSalesChannelId())) {
            $event->getPage()->addExtension('crossSelling', $this->getCrossSellingProducts($context, $cart));
        }
    }

    private function getPaymentIcons(SalesChannelContext $context): ?EntityCollection
    {
        $folderId = $this->systemConfigService->get(self::PAYMENT_ICONS_CONFIG, $context->getSalesChannelId());
        if (!$folderId) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->setLimit(100);
        $criteria->addFilter(new EqualsFilter('mediaFolderId', $folderId));
        $criteria->addSorting(new FieldSorting('fileName'));
        return $this->mediaRepository->search($criteria, $context->getContext())->getEntities();
    }

    private function getCrossSellingProducts(SalesChannelContext $context, Cart $cart): ?EntityCollection
    {
        $productIds = $this->getProductIds($cart);
        if (empty($productIds)) {
            return null;
        }
        $criteria = $this->createProductCriteria($context, $productIds);
        $criteria->addFilter(new EqualsFilter('crossSellingAssignedProducts.crossSelling.active', true));
        $criteria->addFilter(new EqualsAnyFilter('crossSellingAssignedProducts.crossSelling.productId', $productIds));
        $result = $this->productRepository->search($criteria, $context);

        if ($result->first() === null) {
            $criteria = $this->createProductCriteria($context, $productIds);
            $this->addProductStreamFilters($criteria, $productIds, $context->getContext());
            $result = $this->productRepository->search($criteria, $context);
        }
        if ($result->first() === null) {
            return null;
        }

        return $result->getEntities();
    }

    private function getProductIds(Cart $cart): array
    {
        $products = $cart->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        return array_map(
            fn(LineItem $product) => $product->getReferencedId(),
            $products
        );
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
}
