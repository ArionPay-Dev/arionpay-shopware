<?php declare(strict_types=1);

namespace ArionPay\Shopware\Service;

use ArionPay\ApiException;
use ArionPay\Client;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Talks to ArionPay and records paid invoices on Shopware order transactions.
 *
 * The invoice of a transaction is kept in the transaction's custom fields. A transaction becomes paid only from
 * ArionPay itself: the signed webhook, or a signed invoice read. ArionPay alone decides whether an invoice is paid;
 * this plugin never compares crypto amounts.
 */
class ArionPayService
{
    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];
    public const OPEN = ['pending', 'paid_partial', 'claim_pending'];
    private const CONFIG = 'ArionPayPayment.config.';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly EntityRepository $transactions,
        private readonly OrderTransactionStateHandler $states,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function client(?string $salesChannelId = null): Client
    {
        $base = trim((string) $this->config->get(self::CONFIG . 'baseUrl', $salesChannelId));
        return new Client(
            trim((string) $this->config->get(self::CONFIG . 'apiKey', $salesChannelId)),
            trim((string) $this->config->get(self::CONFIG . 'apiSecret', $salesChannelId)),
            ['baseUrl' => $base !== '' ? $base : Client::DEFAULT_BASE_URL, 'appInfo' => 'shopware/6.7 arionpay-shopware/1.0.0']
        );
    }

    public function isTest(?string $salesChannelId = null): bool
    {
        return (bool) ($this->config->get(self::CONFIG . 'testMode', $salesChannelId) ?? true);
    }

    public function webhookSecret(?string $salesChannelId = null): string
    {
        return trim((string) $this->config->get(self::CONFIG . 'webhookSecret', $salesChannelId));
    }

    /** Random id of this shop (made once), so two shops on one ArionPay store never touch each other's orders. */
    public function siteId(): string
    {
        $id = (string) $this->config->get(self::CONFIG . 'siteId');
        if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
            $id = bin2hex(random_bytes(6));
            $this->config->set(self::CONFIG . 'siteId', $id);
        }
        return $id;
    }

    public function loadTransaction(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('stateMachineState');
        return $this->transactions->search($criteria, $context)->first();
    }

    /**
     * The ArionPay page for this transaction: the open invoice when it still fits, otherwise a new one.
     *
     * @return string|null payment page address, or null when ArionPay already has it as paid (then recorded)
     */
    public function paymentPageUrl(OrderTransactionEntity $tx, string $returnUrl, Context $context): ?string
    {
        $order = $tx->getOrder();
        $salesChannelId = $order?->getSalesChannelId();
        $currency = strtoupper((string) $order?->getCurrency()?->getIsoCode());
        if (!\in_array($currency, self::CURRENCIES, true)) {
            throw new \RuntimeException(\sprintf('ArionPay does not take %s.', $currency));
        }
        $amount = number_format($tx->getAmount()->getTotalPrice(), 2, '.', '');
        $test = $this->isTest($salesChannelId);
        $data = (array) (($tx->getCustomFields() ?? [])['arionpay'] ?? []);
        $attempt = max(1, (int) ($data['attempt'] ?? 1));
        $client = $this->client($salesChannelId);

        if (!empty($data['invoiceId'])) {
            try {
                $read = $client->getInvoice((string) $data['invoiceId']);
            } catch (ApiException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
                $read = ['status' => 'missing', 'raw' => []];
            }
            if ($read['status'] === 'paid') {
                $this->recordPaid($tx->getId(), $read, 'signed read', $context);
                return null;
            }
            $expires = isset($read['raw']['expiresAt']) ? strtotime((string) $read['raw']['expiresAt']) : 0;
            $open = \in_array($read['status'], self::OPEN, true) || ($read['status'] === 'new' && ($expires === 0 || $expires > time() + 300));
            if ($open && ($data['amount'] ?? '') === $amount && ($data['currency'] ?? '') === $currency && !empty($data['url'])) {
                $custom = $tx->getCustomFields() ?? [];
                $custom['arionpay']['returnUrl'] = $returnUrl; // every pay() gets a new single-use Shopware token
                $this->transactions->update([['id' => $tx->getId(), 'customFields' => $custom]], $context);
                return (string) $data['url'];
            }
            $attempt++;
        }

        for ($tries = 0; $tries < 20; $tries++, $attempt++) {
            $created = $client->createInvoice([
                'amount' => $amount,
                'currency' => $currency,
                'orderId' => (string) $order?->getOrderNumber(),
                'description' => 'Order ' . $order?->getOrderNumber(),
                // Shopware's return address carries a long token (over ArionPay's 500 characters), so ArionPay gets
                // a short address of this plugin that forwards to it.
                'successUrl' => $this->shortReturnUrl($returnUrl, $tx->getId(), false),
                'cancelUrl' => $this->shortReturnUrl($returnUrl, $tx->getId(), true),
                'callbackUrl' => $this->webhookUrl($returnUrl),
                'metadata' => [
                    'sw_transaction_id' => $tx->getId(),
                    'sw_order_number' => (string) $order?->getOrderNumber(),
                    'sw_amount' => $amount,
                    'sw_currency' => $currency,
                    'sw_site' => $this->siteId(),
                    'platform' => 'shopware',
                ],
                'idempotencyKey' => 'sw-' . $this->siteId() . '-' . $tx->getId() . '-' . str_replace('.', '', $amount) . $currency . ($test ? '-test' : '') . '-' . $attempt,
                'test' => $test,
            ]);
            if ($created['status'] === 'paid') {
                return null;
            }
            $expires = $created['expiresAt'] ? strtotime((string) $created['expiresAt']) : 0;
            if (\in_array($created['status'], self::OPEN, true) || ($created['status'] === 'new' && ($expires === 0 || $expires > time() + 300))) {
                $custom = $tx->getCustomFields() ?? [];
                $custom['arionpay'] = ['invoiceId' => $created['id'], 'url' => $created['invoiceUrl'], 'amount' => $amount, 'currency' => $currency, 'test' => $test, 'attempt' => $attempt, 'returnUrl' => $returnUrl];
                $this->transactions->update([['id' => $tx->getId(), 'customFields' => $custom]], $context);
                return $created['invoiceUrl'];
            }
        }
        throw new \RuntimeException('Too many finished ArionPay invoices for this transaction.');
    }

    /** /arionpay/return/{transaction}?k=...[&cancel=1] on the storefront domain of the return address. */
    public function shortReturnUrl(string $returnUrl, string $transactionId, bool $cancel): string
    {
        $pos = strpos($returnUrl, '/payment/finalize-transaction');
        $base = $pos !== false ? substr($returnUrl, 0, $pos) : rtrim((string) (getenv('APP_URL') ?: ''), '/');
        return $base . '/arionpay/return/' . $transactionId . '?k=' . $this->returnKey($transactionId) . ($cancel ? '&cancel=1' : '');
    }

    /** Keyed hash so only ArionPay's links (not guessed ones) can finish or cancel a transaction's return step. */
    public function returnKey(string $transactionId): string
    {
        $secret = (string) ($_SERVER['APP_SECRET'] ?? getenv('APP_SECRET') ?: '') . '|' . $this->siteId();
        return substr(hash_hmac('sha256', 'return|' . $transactionId, $secret), 0, 32);
    }

    /**
     * The webhook address on the same storefront domain the customer checked out on (taken from Shopware's return
     * address), so it always reaches a sales channel.
     */
    public function webhookUrl(string $returnUrl): string
    {
        $pos = strpos($returnUrl, '/payment/finalize-transaction');
        $base = $pos !== false ? substr($returnUrl, 0, $pos) : rtrim((string) (getenv('APP_URL') ?: ''), '/');
        return $base . '/arionpay/webhook';
    }

    /**
     * Mark the transaction paid for a paid ArionPay invoice. Safe to call many times (under a database lock).
     * Returns paid | duplicate | mismatch | unknown | locked.
     */
    public function recordPaid(string $transactionId, array $invoice, string $source, Context $context): string
    {
        $lock = 'arionpay_sw_' . $transactionId;
        if ((string) $this->db->fetchOne('SELECT GET_LOCK(?, 20)', [$lock]) !== '1') {
            return 'locked';
        }
        try {
            $tx = $this->loadTransaction($transactionId, $context);
            if (!$tx) {
                return 'unknown';
            }
            $data = (array) (($tx->getCustomFields() ?? [])['arionpay'] ?? []);
            $meta = (array) ($invoice['metadata'] ?? []);
            $paid = is_numeric($invoice['amountFiat'] ?? null) ? number_format((float) $invoice['amountFiat'], 2, '.', '') : '';
            if (($meta['sw_transaction_id'] ?? '') !== $transactionId || ($meta['sw_site'] ?? '') !== $this->siteId()
                || $paid === '' || $paid !== (string) ($meta['sw_amount'] ?? '') || strtoupper((string) ($invoice['currency'] ?? '')) !== strtoupper((string) ($meta['sw_currency'] ?? ''))
                || $paid !== number_format($tx->getAmount()->getTotalPrice(), 2, '.', '')) {
                $this->logger->error('ArionPay invoice {i}: amount or currency differs from transaction {t}. NOT marked paid.', ['i' => $invoice['id'] ?? '', 't' => $transactionId]);
                return 'mismatch';
            }
            $state = $tx->getStateMachineState()?->getTechnicalName();
            if ($state === 'paid') {
                return 'duplicate';
            }
            if (\in_array($state, ['cancelled', 'failed'], true)) {
                // Money arrived after the customer left the payment page (or a late payment): open it again first.
                $this->states->reopen($transactionId, $context);
                $this->logger->warning('ArionPay invoice {i} was paid after transaction {t} was {s}: reopened and marked paid. If the customer paid again with another transaction, refund one of them.', ['i' => $invoice['id'] ?? '', 't' => $transactionId, 's' => $state]);
            }
            $this->states->paid($transactionId, $context);
            $custom = $tx->getCustomFields() ?? [];
            $custom['arionpay'] = array_merge($data, [
                'paidInvoice' => (string) ($invoice['id'] ?? ''),
                'paidVia' => $source,
                'late' => !empty($invoice['late']),
                'asset' => (string) ($invoice['asset'] ?? ''),
                'network' => (string) ($invoice['network'] ?? ''),
                'paidCrypto' => (string) ($invoice['paidCrypto'] ?? ''),
            ]);
            $this->transactions->update([['id' => $transactionId, 'customFields' => $custom]], $context);
            $this->logger->info('ArionPay: transaction {t} paid by {test} invoice {i} ({a} {c}) via {s}.', ['t' => $transactionId, 'test' => !empty($invoice['test']) ? 'TEST' : 'live', 'i' => $invoice['id'] ?? '', 'a' => $paid, 'c' => $invoice['currency'] ?? '', 's' => $source]);
            return 'paid';
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }
}
