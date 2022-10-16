<?php declare(strict_types=1);

namespace Frosh\CartCrossSelling\Subscriber;

use Frosh\CartCrossSelling\Service\CrossSellingService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartPageSubscriber implements EventSubscriberInterface
{
    private const CROSS_SELLING_CONFIG = 'FroshCartCrossSelling.config.cartCrossSellingActive';
    private const CROSS_SELLING_OFF_CANVAS_CONFIG = 'FroshCartCrossSelling.config.offCanvasCrossSellingActive';
    private const CROSS_SELLING_BUY_AGAIN_CONFIG = 'FroshCartCrossSelling.config.buyAgainCrossSellingActive';
    private const PAYMENT_ICONS_CONFIG = 'FroshCartCrossSelling.config.paymentIconAlbum';

    private CrossSellingService $crossSellingService;
    private SystemConfigService $systemConfigService;
    private EntityRepositoryInterface $mediaRepository;

    public function __construct(
        CrossSellingService $crossSellingService,
        EntityRepositoryInterface $mediaRepository,
        SystemConfigService $systemConfigService
    )
    {
        $this->crossSellingService = $crossSellingService;
        $this->mediaRepository = $mediaRepository;
        $this->systemConfigService = $systemConfigService;
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

        $page->addExtension('paymentIcons', $this->getPaymentIcons($context));

        $productIds = $this->getProductIds($cart);
        if (empty($productIds)) {
            return;
        }
        if ($this->systemConfigService->getBool(self::CROSS_SELLING_CONFIG, $context->getSalesChannelId())) {
            $crossSelling = $this->crossSellingService->getCrossSellingProducts($context, $productIds);
            $page->addExtension('crossSelling', $crossSelling);
        }
        if ($this->systemConfigService->getBool(self::CROSS_SELLING_BUY_AGAIN_CONFIG, $context->getSalesChannelId())) {
            $buyAgain = $this->crossSellingService->getBuyAgainProducts($context, $productIds);
            $page->addExtension('buyAgain', $buyAgain);
        }
        if (isset($crossSelling) && isset($buyAgain)) {
            $this->dontRepeatOnFirstPage($crossSelling, $buyAgain);
        }
    }

    public function onOffCanvas(OffcanvasCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $cart = $event->getPage()->getCart();

        $productIds = $this->getProductIds($cart);
        if (empty($productIds)) {
            return;
        }
        if ($this->systemConfigService->getBool(self::CROSS_SELLING_OFF_CANVAS_CONFIG, $context->getSalesChannelId())) {
            $crossSelling = $this->crossSellingService->getCrossSellingProducts($context, $productIds);
            $event->getPage()->addExtension('crossSelling', $crossSelling);
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

    private static function getProductIds(Cart $cart): array
    {
        $products = $cart->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        return array_map(
            fn(LineItem $product) => $product->getReferencedId(),
            $products
        );
    }

    private static function dontRepeatOnFirstPage(EntityCollection $first, EntityCollection $second)
    {
        $firstPageIds = $first->slice(0, 5)->getIds();
        $secondFirstPageIds = $second->slice(0, 5)->getIds();
        foreach ($secondFirstPageIds as $id) {
            if (in_array($id, $firstPageIds, true)) {
                $entity = $second->get($id);
                $second->remove($id);
                $second->add($entity);
            }
        }
    }
}
