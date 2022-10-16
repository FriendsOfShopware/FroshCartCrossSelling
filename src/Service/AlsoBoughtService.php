<?php declare(strict_types=1);

namespace Frosh\CartCrossSelling\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class AlsoBoughtService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getAlsoBoughtProductIds(SalesChannelContext $context, array $productIds): array
    {
        // Last three months
        $orderDate = new \DateTime();
        $orderDate->sub(new \DateInterval('P3M'));

        $sql = '
            SELECT DISTINCT LOWER(HEX(olc.product_id))
            FROM order_line_item oli
            JOIN `order` o
             ON o.id = oli.order_id
             AND o.version_id = oli.order_version_id
            JOIN order_line_item olc
             ON olc.order_id = o.id
             AND olc.order_version_id = o.version_id
             AND olc.product_id NOT IN(:productIds)
            WHERE oli.product_id IN(:productIds)
            AND oli.order_version_id = :versionId
            AND o.order_date >= :orderDate
            AND o.sales_channel_id = :salesChannelId
            LIMIT 200
        ';

        // Fix issue with binary and array parameters
        $sql = str_replace(':productIds', implode(', ', array_map(function (string $id) {
            return $this->connection->quote(hex2bin($id));
        }, $productIds)), $sql);

        return $this->connection->fetchFirstColumn($sql, [
            'productIds' => array_map('hex2bin', $productIds),
            'versionId' => hex2bin($context->getVersionId()),
            'orderDate' => $orderDate->format('Y-m-d'),
            'salesChannelId' => hex2bin($context->getSalesChannelId())
        ], [
            'productIds' => Connection::PARAM_STR_ARRAY
        ]);
    }
}
