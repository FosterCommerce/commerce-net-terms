# Release Notes for Net Terms

## Unreleased

### Added
- Added the “Invoice number prefix” and “First invoice number” settings.
- Added the ability to send more than one reminder before and after a due date.
- Added the ability to edit invoice-billing emails in the control panel, and the `net-terms-manageEmails` permission.
- Added the ability to choose the Commerce email each order-billing reminder sends.
- Added invoice PDFs, their settings, and `craft.netTerms.getInvoicePdfUrl()`.
- Added draft invoices, invoice notes, and the ability to choose an invoice’s due date.
- Added the “Description” and “Billing email” account settings.
- Added the “Account (Net Terms)” field type, which links an element such as a company entry to an account. In GraphQL, it returns the account’s ID.
- Added the ability for a buyer on more than one account to choose the account in the payment form. The gateway’s payment form model is now `fostercommerce\netterms\models\payments\NetTermsPaymentForm`.
- Added `craft.netTerms.getAccounts()`, `Accounts::getBuyersForOrder()`, `Accounts::canView()` and `DefineAccountAccessEvent::$canView`.
- Added partial payment authorizations for Foster Checkout’s “Use as a partial payment step” setting. An authorization reserves its amount from the account’s available credit, and Foster Checkout captures it when the order completes.
- Added a Status column to an account’s ledger, showing Posted, Authorized, Captured or Released.
- Added `NetTerms::partialPayment()`, `withPartialPaymentLock()`, `withPartialRefundLock()`, `authorizePartial()`, `getPartialAuthorization()`, `resizeAuthorization()`, `resizePartialAuthorization()` and `releaseAuthorization()`, `Ledger::authorize()`, `getAuthorization()`, `getReservedByBuyers()`, `resizeAuthorization()`, `releaseAuthorization()` and `captureAuthorization()`, `CreditOrders::hasPartialPaymentHistory()`, the `EntryStatus` enum, and `Entry::$status` and `Entry::$authorizationHash`.

### Changed
- Invoices are now issued from a “New invoice” page, and `net-terms/invoices/issue` skips accounts with a draft.
- New invoice numbers now start with `INV-`. To keep `CL-`, set “Invoice number prefix” to `CL-`.
- Net Terms’ settings are now in **Net Terms -> Settings**.
- `net-terms/reminders/send` now sends only the reminders whose moment falls on the day it runs.
- Lowering a completed order’s total now takes the change off its invoice line when the line’s balance covers it.
- The `net-terms/storefront/save-buyer` action now refuses to add a buyer unless an `Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY` handler allows the user.
- Renamed every `getOwed…()` method to `getBalance…()`, in `craft.netTerms`, `Accounts`, `Ledger` and `CreditOrders`, and `Accounts::getAccountFigures()` now returns the account’s balance under `balance` instead of `owed`. The upgrade guide lists each one.
- `Accounts::EVENT_DEFINE_ACCOUNT_ACCESS` now also fires when `craft.netTerms` checks whether a user can see an account.
- Accounts now have a title in place of a holder user.
- An order can now charge any account its customer is an active buyer on, including an order placed in the control panel, and `ResolveBuyerEvent::$userId` defaults to the order’s customer. On the storefront, the customer has to be signed in.
- No one can manage an account from the storefront by default. An `Accounts::EVENT_DEFINE_ACCOUNT_ACCESS` handler can allow it.
- Invoice emails and invoice-billing reminders now go to the account’s billing email, or to the invoice’s buyers when it has no billing email, instead of the account holder.
- Editing the total of a completed order with a partial payment no longer runs the credit check or adjusts the ledger.

### Removed
- Removed the `dueSoonReminderDays`, `overdueReminderDays`, “Invoice email template” and “Storefront order path” settings, the due-soon and overdue system messages, and `Reminders::getSystemMessages()`.
- Removed `Accounts::EVENT_RESOLVE_HOLDER`, `ResolveHolderEvent`, `Account::$holderId`, `Accounts::getAccountForOrder()`, `Accounts::getBuyerForOrder()`, `Accounts::getAccountByHolder()`, `Account::getHolder()`, `ResolveBuyerEvent::$account` and `craft.netTerms.getAccountByHolderId()`. The upgrade guide lists each one.

### Fixed
- Fixed a bug where the payment summary showed amounts 100 times too large in some locales.
- Fixed an error that could occur when capturing, refunding or editing an order paid in a payment currency with a conversion rate below 0.0001.
- Fixed a bug where `net-terms/invoices/issue` stopped issuing invoices for the remaining accounts when an invoice number was locked.
- Fixed an error that could occur when removing a buyer while another change to the account was in progress.
- Fixed an error that could occur when a payment’s transaction couldn’t be saved in order billing.
- Fixed a bug where applying or reversing a payment, or editing an order’s total, saved the order twice.

## 1.0.0 - 2026-10-09

### Added
- Added accounts, each with a credit limit or unlimited credit, payment terms and a status.
- Added an “Over the credit limit” amount to the account pages.
- Added buyers, with an optional sublimit that works as a ceiling or a reserved amount.
- Added the Net Terms gateway, which charges an order to the buyer's account.
- Added invoices with one line per buyer, and the `net-terms/invoices/issue` command.
- Added the invoice email.
- Added payments, applied to invoice lines or orders.
- Added order billing.
- Added billing for staff edits to the total of a completed order charged to an account, in invoice billing.
- Added the ability to change one invoice's payment terms.
- Added the “Payment terms (Net Terms)” field type, which gives an order its own payment terms in order billing.
- Added due-soon and overdue reminder emails, and the `net-terms/reminders/send` command.
- Added storefront actions for managing buyers and their sublimits.
- Added adjustments to an account's ledger.
- Added protection against deleting an order that is on an issued invoice.
- Added protection against deleting a user who holds an account or has ledger history.
- Added the `net-terms-manageAccounts` and `net-terms-managePayments` permissions.
- Added `craft.netTerms` for storefront templates.
- Added `Accounts::EVENT_RESOLVE_HOLDER`, `Accounts::EVENT_RESOLVE_BUYER`, `Accounts::EVENT_DEFINE_ACCOUNT_ACCESS`, `Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY`, `Ledger::EVENT_AFTER_ADD_ENTRY` and `Invoices::EVENT_AFTER_ISSUE_INVOICE`.
- Added `CreditOrders::REVERSAL_TRANSACTION_CODE`.
