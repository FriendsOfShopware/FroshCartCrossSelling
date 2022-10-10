<?php declare(strict_types=1);

namespace Frosh\CartCrossSelling\Product;

use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\Cart\ProductFeatureBuilder;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

class ProductDetailRoute extends AbstractProductDetailRoute
{
    private AbstractProductDetailRoute $decorated;
    private ProductFeatureBuilder $featureBuilder;
    private SystemConfigService $systemConfigService;

    public function __construct(
        AbstractProductDetailRoute $decorated,
        SystemConfigService $systemConfigService,
        ProductFeatureBuilder $featureBuilder

    ) {
        $this->systemConfigService = $systemConfigService;
        $this->featureBuilder = $featureBuilder;
        $this->decorated = $decorated;
    }

    public function getDecorated(): AbstractProductDetailRoute
    {
        return $this->decorated;
    }

    public function load(string $productId, Request $request, SalesChannelContext $context, Criteria $criteria): ProductDetailRouteResponse
    {
        if ($this->systemConfigService->getBool(
            'FroshCartCrossSelling.config.featureSetQuickViewActive',
            $context->getSalesChannelId()
        )) {
            // Load product with features set information
            $criteria->addAssociation('featureSet');
        }
        $response = $this->decorated->load($productId, $request, $context, $criteria);

        // Create line item and add it to the product
        $product = $response->getProduct();
        $lineItem = new LineItem($product->getId(), LineItem::PRODUCT_LINE_ITEM_TYPE, $product->getId());
        $product->addExtension('lineItem', $lineItem);

        // Build product features and add them to the line item
        $this->buildProductFeatures($product, $lineItem, $context);

        if ($this->systemConfigService->getBool(
            'FroshCartCrossSelling.config.optionsQuickViewActive',
            $context->getSalesChannelId()
        )) {
            // Build product options and add them to the line item
            $lineItem->setPayloadValue('options', self::getPayloadOptions($product));
        }

        return $response;
    }

    private function buildProductFeatures(ProductEntity $product, LineItem $lineItem, SalesChannelContext $context): void
    {
        $productKey = ProductDefinition::ENTITY_NAME . '-' . $lineItem->getReferencedId();
        $data = new CartDataCollection([$productKey => $product]);
        $this->featureBuilder->prepare([$lineItem], $data, $context);
        $this->featureBuilder->add([$lineItem], $data, $context);
    }

    private static function getPayloadOptions(ProductEntity $product): array
    {
        $options = [];
        foreach ($product->getOptions() as $option) {
            $options[] = [
                'group' => $option->getGroup()->getName(),
                'option' => $option->getName()
            ];
        }
        return $options;
    }
}
