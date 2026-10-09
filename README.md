![Net Terms](resources/img/header.png)

# Net Terms

Give business customers a credit limit to buy now and pay their invoice later on set terms.

Net Terms is a Craft Commerce payment gateway that bills orders on net terms, tracks what each buyer owes, and applies recorded payments to invoices or orders.

## Overview

- Sell to business customers on terms through your normal checkout: open an account with payment terms and a limit (Net 30 on $10,000), or no limit when a customer only needs billing, and offer Net Terms beside your other payment methods.
- Control spending per person: give each buyer a sublimit (an office manager can owe up to $2,000), set by your staff, or by the company's own admins from storefront templates you build.
- Keep receivables in Craft: invoice each account with one line per buyer, or bill by the orders themselves, leaving each one unpaid under a label like "Pay via check/ACH" for the customer to pay later, online or by check.
- Apply each check, ACH, wire or card payment the way the remittance says, split across the invoices or orders it covers.
- Get paid without chasing: Net Terms emails each customer before an invoice or order is due and again once it's overdue, from one daily console command, with the emails editable in Craft's System Messages.
- Add net terms to the company setup you already have: a site module can set which company's account an order charges.

## How it works

You create an account for a customer, set its credit limit, and add the users who can buy on it. The holder is a buyer from the start. At checkout, Commerce offers the Net Terms gateway only to those buyers, and the gateway declines an order that is more than the buyer's available credit. The gateway's payment type in Commerce sets how you bill: on invoices the plugin issues, or by the orders themselves. Money you receive doesn't reopen a buyer's credit until you apply it to their invoice line or order, so you decide which balance a payment clears.

## Requirements

- Craft CMS `^5.10.0`
- Craft Commerce `^5.4.0`
- PHP `^8.2`

## Install

```sh
composer require fostercommerce/commerce-net-terms
./craft plugin/install net-terms
```

## Documentation

- [Getting started](https://www.fostercommerce.com/craft-cms-plugins/net-terms/docs/getting-started), from install to a first invoice for an order charged to an account
- [Accounts and buyers](https://www.fostercommerce.com/craft-cms-plugins/net-terms/docs/user-guide/accounts-and-buyers), credit limits, sublimits, and how available credit is worked out
- [Billing by order](https://www.fostercommerce.com/craft-cms-plugins/net-terms/docs/user-guide/order-billing), leaving orders unpaid and recording payments against them
- [Payments](https://www.fostercommerce.com/craft-cms-plugins/net-terms/docs/user-guide/payments), recording money received and applying it to invoices or orders
- [Storefront templates](https://www.fostercommerce.com/craft-cms-plugins/net-terms/docs/dev-guide/storefront-templates), showing an account and taking payment on your site

## License

Proprietary

---

<a href="https://www.fostercommerce.com" target="_blank"><img src="./resources/img/foster-commerce.svg" alt="Foster Commerce" width="160" height="40"></a>
