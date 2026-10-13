# Storefront templates

Show a customer their account and its balance, let the people who manage it set sublimits, and in order billing take payment of an order's balance. The plugin does not ship storefront pages. You build them with `craft.netTerms` and the plugin's storefront actions.

`craft.netTerms` returns only accounts the signed-in user can see: the holder's, one they are an active buyer on, one they can manage, or one an `Accounts::EVENT_DEFINE_ACCOUNT_ACCESS` handler lets them see. To change who can see or manage an account, see [company accounts](./company-accounts.md).

`getAccount()` returns only the account the user holds or buys on. For a user who can see or manage an account without buying on it, fetch the account with `getAccountByHolderId(holderId)`.

## Example

1. Fetch the signed-in user's account.
2. Show its balance and what the user can still charge.
3. List its invoices with a link to each.

```twig
{% set account = craft.netTerms.getAccount() %}

{% if account %}
	{% set buyer = craft.netTerms.getCurrentBuyer(account) %}

	<p>Balance: {{ craft.netTerms.getBalance(account)|money }}</p>
	{% if buyer and buyer.active %}
		{% set available = craft.netTerms.getAvailableCredit(buyer) %}
		<p>Available to you: {{ available ? available|money : 'Unlimited' }}</p>
	{% endif %}

	{% for invoice in craft.netTerms.getInvoices(account) %}
		<a href="{{ siteUrl('account/invoice', { number: invoice.number }) }}">
			{{ invoice.number }}, due {{ invoice.dateDue|date('medium') }},
			{{ craft.netTerms.getInvoiceBalance(invoice)|money }}
		</a>
	{% endfor %}
{% endif %}
```

Amounts are [Money](https://www.moneyphp.org/) objects. Format them with Craft's `|money` filter.

## Managing buyers

To set a buyer's sublimit, post to `net-terms/storefront/save-buyer`. To add a user as a buyer, send `userId` instead of `buyerId`. A blank `sublimit` means no sublimit. The action refuses to add a user unless an `Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY` handler allows them; see [events](../reference/events.md#accountsevent_define_buyer_eligibility).

```twig
{% if craft.netTerms.canManage(account) %}
	{% for buyer in craft.netTerms.getBuyers(account) %}
		{% if buyer.active %}
			<form method="post">
				{{ csrfInput() }}
				{{ actionInput('net-terms/storefront/save-buyer') }}
				{{ redirectInput('account/net-terms') }}
				{{ hiddenInput('accountId', account.id) }}
				{{ hiddenInput('buyerId', buyer.id) }}
				<label>{{ buyer.getName() }}
					<input type="text" name="sublimit" value="{{ buyer.getSublimitNumber() }}">
				</label>
				<button type="submit">Save</button>
			</form>
		{% endif %}
	{% endfor %}
{% endif %}
```

To remove a buyer, post `accountId` and `buyerId` to `net-terms/storefront/remove-buyer`. The action deactivates a buyer with ledger entries or orders instead of removing them. Adding that user again reactivates them.

Both actions return a 403 for a user who can't manage the account. When a sublimit doesn't save, the action flashes the reason, such as a sublimit below the buyer's balance.

## Order billing

In [order billing](../user-guide/order-billing.md) there are no invoices. List the account's unpaid orders instead, each with its balance and due date. Check `craft.netTerms.billing.value` to build one template for either mode.

```twig
{% for order in craft.netTerms.getOrders(account, true) %}
	<a href="{{ siteUrl('account/net-terms-pay', { number: order.number }) }}">{{ order.reference }}</a>
	due {{ craft.netTerms.getOrderDateDue(order)|date('medium') }},
	{{ craft.netTerms.getOrderBalance(order)|money }}
{% endfor %}
```

### Paying an order's balance

Pay a completed order's balance with Commerce's own `commerce/payments/pay` action, posting the order's `number` and `email`, because it isn't the session cart. List the gateways Commerce makes available for the order. The Net Terms gateway is never available for a completed order. To choose which other gateways are, see [billing by order](../user-guide/order-billing.md#paying-an-orders-balance-online).

```twig
{% set order = craft.orders.number(craft.app.request.getQueryParam('number')).one() %}
{% set balance = order ? craft.netTerms.getOrderBalance(order) : null %}

{% if balance and balance.isPositive() %}
	{% for gateway in craft.commerce.gateways.getAllCustomerEnabledGatewaysAndAvailableForUseWithOrder(order) %}
		<form method="post">
			{{ csrfInput() }}
			{{ actionInput('commerce/payments/pay') }}
			{{ redirectInput('account/net-terms') }}
			{{ hiddenInput('number', order.number) }}
			{{ hiddenInput('email', order.email) }}
			{{ hiddenInput('gatewayId', gateway.id) }}

			{% namespace gateway.handle|commercePaymentFormNamespace %}
				{{ gateway.getPaymentFormHtml({ order: order })|raw }}
			{% endnamespace %}
		</form>
	{% endfor %}
{% endif %}
```

This form charges the order's whole balance. The payment is a Commerce transaction on the order, and the payment lowers the order's balance and the buyer's balance by the amount paid. Net Terms does not record it as a payment.

To link order reminders to this page, link to it from the reminder's Commerce email template; see [order billing emails](../user-guide/reminders.md#order-billing-emails).

## `craft.netTerms`

| Method | Returns |
|---|---|
| `getAccount()` | The account the user holds or buys on in the current store. For a buyer on more than one account, the first one opened. |
| `getAccountByHolderId(holderId)` | The account a holder's user ID has, when the user can see it. |
| `getCurrentBuyer(account)` | The user's buyer record on the account. |
| `getBuyers(account)` | Every buyer on the account, including deactivated ones. Check `buyer.active`. |
| `getAvailableCredit(buyer)` | What the buyer can still charge, or `null` when it is unlimited. |
| `getBalance(account)` | The account's balance. |
| `getBalanceByBuyer(buyer)` | The buyer's balance. |
| `getUnappliedCredit(account)` | Money received and not yet applied. |
| `canManage(account)` | Whether the user can manage buyers and sublimits. |
| `getInvoices(account)` | The account's issued invoices, newest first. Drafts are left out. |
| `getInvoiceByNumber(number)` | One issued invoice. |
| `getInvoicePdfUrl(invoice)` | A link that opens the invoice PDF for a signed-in user, or `null` when they can't view the invoice. |
| `getOpenLines(account)` | Invoice lines with a balance, oldest due first. |
| `getInvoiceTotal(invoice)`, `getInvoiceBalance(invoice)`, `getInvoiceStatus(invoice)` | Invoice figures. |
| `getLineAmount(line)`, `getLineBalance(line)` | Invoice line figures. |
| `getBilling()` | `invoices` or `orders`, as a `Billing` enum. |
| `getOrders(account, openOnly)` | In order billing, the orders charged to the account, newest first, or with `true` only those with a balance, oldest first. |
| `getOrderBalance(order)`, `getOrderDateDue(order)`, `getIsOrderOverdue(order)` | Order figures, in order billing. |
| `toNumber(money)` | An amount as a localized number, for a form input. |

For an account the user can't see, methods return `null`, `false`, or an empty array. Because `getAvailableCredit()` also returns `null` for unlimited credit, check that the user can see the account first, such as with `buyer and buyer.active`.
