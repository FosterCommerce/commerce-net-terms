# Release Notes for Net Terms

## Unreleased

### Added
- Added accounts, each with a credit limit or unlimited credit, payment terms and a status.
- Added an “Over the credit limit” amount to the account pages.
- Added buyers, with an optional sublimit that works as a ceiling or a reserved amount.
- Added the Net Terms gateway, which charges an order to the buyer's account.
- Added invoices with one line per buyer, and the `net-terms/invoices/issue` command.
- Added the invoice email.
- Added payments, applied to invoice lines or orders.
- Added order billing.
- Added billing for staff edits to a completed credit order's total, in invoice billing.
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
