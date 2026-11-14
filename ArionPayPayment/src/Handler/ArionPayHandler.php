<?php declare(strict_types=1);

namespace ArionPay\Shopware\Handler;

use ArionPay\Shopware\Service\ArionPayService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * pay(): send the customer to the ArionPay page. finalize(): the customer came back; a signed read decides:
 * paid -> paid, payment seen -> in progress (the webhook marks it paid), not paid -> cancelled (Shopware then lets
 * the customer pay again). A late payment on a cancelled transaction still reopens and pays it (webhook).
 */
class ArionPayHandler extends AbstractPaymentHandler
{
    public function __construct(
        private readonly ArionPayService $service,
        private readonly OrderTransactionStateHandler $states,
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false; // no refunds or recurring charges through Shopware: crypto refunds are made from your wallet
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $id = $transaction->getOrderTransactionId();
        $tx = $this->service->loadTransaction($id, $context);
        if (!$tx || !$transaction->getReturnUrl()) {
            throw PaymentException::asyncProcessInterrupted($id, 'Order transaction not found.');
        }
        try {
            $url = $this->service->paymentPageUrl($tx, $transaction->getReturnUrl(), $context);
        } catch (\Throwable $e) {
            $this->service->logger()->error('ArionPay: could not start the payment for transaction {t}: {m}', ['t' => $id, 'm' => $e->getMessage()]);
            throw PaymentException::asyncProcessInterrupted($id, 'The crypto payment could not be started right now. Please try again in a minute.', $e);
        }
        // Already paid at ArionPay (and recorded): go straight to Shopware's return step.
        return new RedirectResponse($url ?? $transaction->getReturnUrl());
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $id = $transaction->getOrderTransactionId();
        $tx = $this->service->loadTransaction($id, $context);
        $data = (array) (($tx?->getCustomFields() ?? [])['arionpay'] ?? []);
        if (!$tx || empty($data['invoiceId'])) {
            throw PaymentException::asyncProcessInterrupted($id, 'No ArionPay invoice for this transaction.');
        }
        $read = $this->service->client($tx->getOrder()?->getSalesChannelId())->getInvoice((string) $data['invoiceId']);
        if ($read['status'] === 'paid') {
            $result = $this->service->recordPaid($id, $read, 'signed read on return', $context);
            if (\in_array($result, ['paid', 'duplicate'], true)) {
                return;
            }
            throw PaymentException::asyncProcessInterrupted($id, 'The ArionPay payment does not match this order: ' . $result);
        }
        if (\in_array($read['status'], ArionPayService::OPEN, true)) {
            // Seen on the network, waiting for confirmations: shown as "in progress" until the webhook marks it paid.
            if ($tx->getStateMachineState()?->getTechnicalName() === 'open') {
                $this->states->process($id, $context);
            }
            return;
        }
        throw PaymentException::customerCanceled($id, 'The crypto payment was not made.');
    }
}
