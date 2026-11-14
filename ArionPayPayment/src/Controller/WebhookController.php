<?php declare(strict_types=1);

namespace ArionPay\Shopware\Controller;

use ArionPay\SignatureException;
use ArionPay\Shopware\Service\ArionPayService;
use ArionPay\Webhook;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /arionpay/webhook (on a storefront domain). Trusted only by the ArionPay-Signature over the exact body.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhookController extends AbstractController
{
    private const MAX_BYTES = 262144;

    public function __construct(private readonly ArionPayService $service)
    {
    }

    /** Short return address given to ArionPay: forwards the customer to Shopware's own (long) return address. */
    #[Route(path: '/arionpay/return/{transactionId}', name: 'frontend.arionpay.return', requirements: ['transactionId' => '[0-9a-f]{32}'], defaults: ['_httpCache' => false], methods: ['GET'])]
    public function returnPage(string $transactionId, Request $request): RedirectResponse|JsonResponse
    {
        if (!hash_equals($this->service->returnKey($transactionId), (string) $request->query->get('k', ''))) {
            return new JsonResponse(['error' => 'invalid link'], 403);
        }
        $tx = $this->service->loadTransaction($transactionId, Context::createDefaultContext());
        $url = (string) ((($tx?->getCustomFields() ?? [])['arionpay'] ?? [])['returnUrl'] ?? '');
        if ($url === '') {
            return new JsonResponse(['error' => 'unknown payment'], 404);
        }
        if ($request->query->get('cancel')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'arionpay_cancel=1';
        }
        return new RedirectResponse($url);
    }

    #[Route(path: '/arionpay/webhook', name: 'frontend.arionpay.webhook', defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false, '_httpCache' => false], methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $raw = (string) $request->getContent();
        if (\strlen($raw) > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'too large'], 413);
        }
        $secret = $this->service->webhookSecret();
        if ($secret === '') {
            return new JsonResponse(['error' => 'webhook secret not set'], 503);
        }
        try {
            $event = Webhook::verify($raw, $request->headers->get('ArionPay-Signature'), $secret);
        } catch (SignatureException $e) {
            $this->service->logger()->warning('ArionPay webhook refused: {m}', ['m' => $e->getMessage()]);
            return new JsonResponse(['error' => 'invalid signature'], 401);
        }
        if (Webhook::isTestEvent($event)) {
            $this->service->logger()->info('ArionPay test webhook received ({t}). Nothing changed.', ['t' => $event['type']]);
            return new JsonResponse(['received' => true, 'test' => true]);
        }
        $invoice = $event['data']['invoice'];
        $meta = (array) ($invoice['metadata'] ?? []);
        $transactionId = (string) ($meta['sw_transaction_id'] ?? '');
        if ($transactionId === '' || ($meta['sw_site'] ?? '') !== $this->service->siteId()) {
            return new JsonResponse(['received' => true, 'ignored' => 'not an order of this shop']);
        }
        $context = Context::createDefaultContext();
        $tx = $this->service->loadTransaction($transactionId, $context);
        if (!$tx) {
            return new JsonResponse(['received' => true, 'ignored' => 'unknown transaction']);
        }
        $data = (array) (($tx->getCustomFields() ?? [])['arionpay'] ?? []);
        if ((bool) ($invoice['test'] ?? false) !== (bool) ($data['test'] ?? $this->service->isTest())) {
            return new JsonResponse(['error' => 'test/live mismatch'], 409);
        }
        if ($event['type'] !== 'invoice.paid') {
            $this->service->logger()->info('ArionPay {e} for invoice {i} (transaction {t}).', ['e' => $event['type'], 'i' => $invoice['id'], 't' => $transactionId]);
            return new JsonResponse(['received' => true]);
        }
        $result = $this->service->recordPaid($transactionId, $invoice, 'webhook', $context);
        return new JsonResponse(['received' => true, 'result' => $result], $result === 'locked' ? 503 : 200);
    }
}
