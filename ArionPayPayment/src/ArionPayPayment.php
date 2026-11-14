<?php declare(strict_types=1);

namespace ArionPay\Shopware;

use ArionPay\Shopware\Handler\ArionPayHandler;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class ArionPayPayment extends Plugin
{
    public const TECHNICAL_NAME = 'payment_arionpay';

    public function install(InstallContext $installContext): void
    {
        $context = $installContext->getContext();
        $pluginId = $this->container->get(PluginIdProvider::class)->getPluginIdByBaseClass(static::class, $context);
        $repo = $this->paymentRepository();
        $data = [
            'handlerIdentifier' => ArionPayHandler::class,
            'technicalName' => self::TECHNICAL_NAME,
            'name' => 'Crypto (Bitcoin, USDT and more)',
            'description' => 'Pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more on the secure ArionPay page.',
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true,
            'active' => false,
            'translations' => [
                'de-DE' => ['name' => 'Krypto (Bitcoin, USDT und mehr)', 'description' => 'Bezahlen Sie mit Bitcoin, USDT, Litecoin, Ethereum, TRON und mehr auf der sicheren ArionPay-Seite.'],
                'en-GB' => ['name' => 'Crypto (Bitcoin, USDT and more)', 'description' => 'Pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more on the secure ArionPay page.'],
            ],
        ];
        if ($id = $this->paymentMethodId($context)) {
            $data['id'] = $id;
        }
        $repo->upsert([$data], $context);
    }

    public function activate(ActivateContext $activateContext): void
    {
        $context = $activateContext->getContext();
        $id = $this->paymentMethodId($context);
        if (!$id) {
            return;
        }
        $this->paymentRepository()->update([['id' => $id, 'active' => true]], $context);
        // Offer it in every storefront that doesn't have it yet (merchants can remove it per sales channel).
        /** @var EntityRepository $salesChannels */
        $salesChannels = $this->container->get('sales_channel.repository');
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $updates = [];
        foreach ($salesChannels->searchIds($criteria, $context)->getIds() as $salesChannelId) {
            $updates[] = ['id' => $salesChannelId, 'paymentMethods' => [['id' => $id]]];
        }
        if ($updates) {
            $salesChannels->update($updates, $context);
        }
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->setActive(false, $deactivateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        // Orders keep their payment method, so it is only switched off.
        $this->setActive(false, $uninstallContext->getContext());
    }

    private function setActive(bool $active, Context $context): void
    {
        if ($id = $this->paymentMethodId($context)) {
            $this->paymentRepository()->update([['id' => $id, 'active' => $active]], $context);
        }
    }

    private function paymentMethodId(Context $context): ?string
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('technicalName', self::TECHNICAL_NAME));
        return $this->paymentRepository()->searchIds($criteria, $context)->firstId();
    }

    private function paymentRepository(): EntityRepository
    {
        return $this->container->get('payment_method.repository');
    }
}
