# ArionPay for Shopware 6

Crypto payments (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) for Shopware through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

For Shopware 6.7, PHP 8.2+. Tested on Shopware 6.7.16 with PHP 8.3. The tests covered guest checkout, coming back before, during and after payment, a lost webhook, a late payment after cancelling, payment tolerance, Payment Radar and webhook checks.

## Install

1. Shopware admin: *Extensions > My extensions > Upload extension*, choose `ArionPayPayment-1.0.0.zip`, then **Install** and **Activate**. The payment method **Crypto (Bitcoin, USDT and more)** is created and switched on for your storefronts.
2. Open the extension's configuration and fill in:

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |
| Test mode | On while you try it (no real money); off for real payments | |
| ArionPay address | leave empty | |

Nothing to paste into ArionPay: each invoice tells ArionPay where to send its payment updates (`/arionpay/webhook` on your storefront domain).

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

From the command line instead: copy the `ArionPayPayment` folder to `custom/plugins/`, then `bin/console plugin:refresh && bin/console plugin:install --activate ArionPayPayment && bin/console cache:clear`.

## How payments change

- At checkout the order is placed with payment status **Open** and the customer goes to the ArionPay page.
- When ArionPay confirms the full payment, the payment status becomes **Paid**. Shopware's usual flows and emails run.
- When the customer comes back, the plugin asks ArionPay directly:
  - **paid**: the payment is marked paid (this also covers a lost webhook);
  - **payment seen, waiting for network confirmations**: **In progress**, until ArionPay confirms it;
  - **not paid**: **Cancelled**, and Shopware lets the customer choose a payment method and pay again.
- If an invoice is paid after its payment was cancelled (a late payment), the payment is reopened and marked paid. If the customer also paid again, refund one of the two from your wallet.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically; anything else you decide. If you accept it, the payment is marked paid here too.
- A payment is marked paid only by the invoice made for it: same amount and currency, and test or live like the setting.
- The invoice, coin and network are kept on the payment (custom field `arionpay`).

## Good to know

- ArionPay takes USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK, ZAR.
- Refunds are made from your own wallet; then set the payment to Refunded by hand.
- The plugin sends ArionPay the order number, amount and currency. It does not send the customer's name or email.
- Logs: `var/log/` (channel `app`, messages starting with "ArionPay"). Keys are never logged.
