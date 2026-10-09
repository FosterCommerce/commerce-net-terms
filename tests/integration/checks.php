<?php

declare(strict_types=1);

/**
 * Net Terms integration checks.
 *
 * Run inside a site's web container, from the site root:
 *
 *     php /path/to/commerce-net-terms/tests/integration/checks.php
 *
 * The run completes real orders and runs every job in the site's queue, including the site's own,
 * so point any external sync at a test service first. Mail goes only to the run's own users. The
 * gateway's payment type and the plugin settings change in memory only. Every account, order and
 * user the run creates is deleted again in a `finally`. The site needs a Net Terms gateway with
 * the handle `netTerms`.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require $root . '/vendor/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\User;
use craft\helpers\Db;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\AccountStatus;
use fostercommerce\netterms\enums\EntryType;
use fostercommerce\netterms\enums\InvoiceStatus;
use fostercommerce\netterms\enums\PaymentMethod;
use fostercommerce\netterms\enums\SublimitMode;
use fostercommerce\netterms\errors\InvalidAmountException;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\events\EntryEvent;
use fostercommerce\netterms\events\ResolveBuyerEvent;
use fostercommerce\netterms\gateways\NetTerms;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\models\InvoiceLine;
use fostercommerce\netterms\models\Payment;
use fostercommerce\netterms\models\Settings;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\services\Accounts;
use fostercommerce\netterms\services\CreditOrders;
use fostercommerce\netterms\services\Ledger;
use Money\Currency;
use Money\Money;
use yii\base\Event;

/** @var int $passed */
$passed = 0;
/** @var int $failed */
$failed = 0;

function check(string $label, callable $test): void
{
	global $passed, $failed;
	/** @var int $passed */
	/** @var int $failed */

	try {
		$result = $test();

		if ($result === true) {
			$passed++;
			echo "  ✓ {$label}\n";

			return;
		}

		$failed++;
		echo "  ✗ {$label}\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
	} catch (Throwable $throwable) {
		$failed++;
		echo "  ✗ {$label}\n    " . $throwable::class . ': ' . $throwable->getMessage() . "\n    " . $throwable->getFile() . ':' . $throwable->getLine() . "\n";
	}
}

/**
 * Narrow a value the checks rely on, failing the run when it's missing.
 *
 * @template TObject of object
 * @param class-string<TObject> $class
 * @return TObject
 */
function expect(mixed $value, string $class): object
{
	if (! $value instanceof $class) {
		throw new RuntimeException('Expected ' . $class . ', got ' . get_debug_type($value) . '.');
	}

	return $value;
}

function commerce(): Commerce
{
	return expect(Commerce::getInstance(), Commerce::class);
}

function section(string $title): void
{
	echo "\n{$title}\n";
}

$plugin = Plugin::getInstance();
// Start in invoice billing whatever the site has saved, and switch to order billing only for its own section
expect(commerce()->getGateways()->getGatewayById(netTermsGatewayId()), NetTerms::class)->paymentType = TransactionRecord::TYPE_PURCHASE;
$store = expect(commerce()->getStores()->getPrimaryStore(), craft\commerce\models\Store::class);
$storeId = (int) $store->id;
$currency = expect($store->getCurrency(), Currency::class);
$accounts = $plugin->getAccounts();
$ledger = $plugin->getLedger();
$invoices = $plugin->getInvoices();
$payments = $plugin->getPayments();
$tag = 'cl' . substr(md5((string) microtime(true)), 0, 6);

/** @var \craft\elements\User[] $createdUsers */
$createdUsers = [];
/** @var \craft\commerce\elements\Order[] $createdOrders */
$createdOrders = [];

// The user a test order is paid by, standing in for the signed-in user a console request lacks
$payingUserId = null;
Event::on(Accounts::class, Accounts::EVENT_RESOLVE_BUYER, static function (ResolveBuyerEvent $event) use (&$payingUserId): void {
	$event->userId = $payingUserId;
});

function money(string $decimal): Money
{
	global $currency;
	/** @var Currency $currency */

	return Amounts::toMoney($decimal, $currency);
}

function same(?Money $actual, string $expected): bool|string
{
	if (! $actual instanceof Money) {
		return 'expected ' . $expected . ', got unlimited';
	}

	return $actual->equals(money($expected)) ? true : 'expected ' . $expected . ', got ' . Amounts::toDecimal($actual);
}

function makeUser(string $key): User
{
	global $createdUsers, $tag;
	/** @var string $tag */

	$user = new User();
	$user->username = $tag . '-' . $key;
	$user->email = $tag . '-' . $key . '@net-terms.example';
	$user->active = true;

	if (! Craft::$app->getElements()->saveElement($user)) {
		throw new RuntimeException('Could not save fixture user: ' . json_encode($user->getErrors()));
	}

	$createdUsers[] = $user;

	return $user;
}

function makeAccount(User $holder, string $limit, ?SublimitMode $mode = null): Account
{
	global $storeId, $accounts;
	/** @var int $storeId */
	/** @var Accounts $accounts */

	$account = new Account([
		'storeId' => $storeId,
		'holderId' => $holder->id,
		'creditLimit' => money($limit),
		'sublimitMode' => $mode,
	]);

	if (! $accounts->saveAccount($account)) {
		throw new RuntimeException('Could not save account: ' . json_encode($account->getErrors()));
	}

	return $account;
}

function makeBuyer(Account $account, User $user, ?string $sublimit): Buyer
{
	global $accounts;
	/** @var Accounts $accounts */

	$buyer = new Buyer([
		'accountId' => $account->id,
		'userId' => $user->id,
		'sublimit' => $sublimit !== null ? money($sublimit) : null,
	]);

	if (! $accounts->saveBuyer($buyer)) {
		throw new RuntimeException('Could not save buyer: ' . json_encode($buyer->getErrors()));
	}

	return $buyer;
}

/**
 * An order for an existing variant, since Commerce's refund flow needs a purchasable on every line item.
 */
function makeOrder(User $customer, Variant $variant, int $qty, int $gatewayId): Order
{
	global $createdOrders, $storeId;
	/** @var int $storeId */

	$order = new Order([
		'storeId' => $storeId,
	]);
	$order->number = commerce()->getCarts()->generateCartNumber();
	$order->setCustomer($customer);
	$order->gatewayId = $gatewayId;

	if (! Craft::$app->getElements()->saveElement($order, false)) {
		throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
	}

	$createdOrders[] = $order;

	$order->setLineItems([
		commerce()->getLineItems()->create($order, [
			'purchasableId' => $variant->id,
			'qty' => $qty,
		]),
	]);

	if (! Craft::$app->getElements()->saveElement($order, false)) {
		throw new RuntimeException('Could not save order line items: ' . json_encode($order->getErrors()));
	}

	return $order;
}

function fixtureVariant(): Variant
{
	/** @var Variant[] $variants */
	$variants = Variant::find()->status('enabled')->limit(50)->all();

	foreach ($variants as $variant) {
		if ($variant->getPrice() >= 10 && $variant->getIsAvailable()) {
			return $variant;
		}
	}

	throw new RuntimeException('The site has no available variant priced at 10 or more to order.');
}

function pay(Order $order): ?string
{
	$redirect = null;
	$transaction = null;

	try {
		commerce()->getPayments()->processPayment($order, new OffsitePaymentForm(), $redirect, $transaction);
	} catch (Throwable $throwable) {
		return $throwable->getMessage();
	}

	return null;
}

function netTermsGatewayId(): int
{
	$gateway = commerce()->getGateways()->getGatewayByHandle('netTerms');

	// Leave creating the gateway to the site, so the run leaves project config untouched
	if (! $gateway instanceof NetTerms) {
		throw new RuntimeException('Add a Net Terms gateway with the handle “netTerms” in Commerce → Settings → Gateways first.');
	}

	return (int) $gateway->id;
}

echo "Net Terms checks ({$tag})\n";

try {
	$holder = makeUser('holder');
	$alice = makeUser('alice');
	$bob = makeUser('bob');
	$outsider = makeUser('outsider');

	$account = makeAccount($holder, '1000.00', SublimitMode::Ceiling);
	$aliceBuyer = makeBuyer($account, $alice, '300.00');
	$bobBuyer = makeBuyer($account, $bob, null);

	section('Available credit, ceiling mode');

	check('a buyer with a sublimit starts at the sublimit', fn (): bool|string => same($accounts->getAvailableCredit($aliceBuyer), '300.00'));
	check('a buyer without one starts at the credit limit', fn (): bool|string => same($accounts->getAvailableCredit($bobBuyer), '1000.00'));

	$ledger->adjust($account, $aliceBuyer, money('200.00'), 'Opening balance');
	check('owing lowers the buyer’s sublimit room', fn (): bool|string => same($accounts->getAvailableCredit($aliceBuyer), '100.00'));
	check('owing lowers everyone else’s account room', fn (): bool|string => same($accounts->getAvailableCredit($bobBuyer), '800.00'));

	$ledger->adjust($account, $bobBuyer, money('750.00'), 'Opening balance');
	check('one buyer can use up the shared room in ceiling mode', fn (): bool|string => same($accounts->getAvailableCredit($bobBuyer), '50.00'));
	check('a sublimit never exceeds the account’s room', fn (): bool|string => same($accounts->getAvailableCredit($aliceBuyer), '50.00'));
	check('what the account owes is the sum of its entries', fn (): bool|string => same($accounts->getOwedByAccount($account), '950.00'));

	section('Unlimited credit');

	check('an account can have unlimited credit', function () use ($accounts): bool|string {
		$unlimitedHolder = makeUser('unlimited');
		$unlimitedAccount = makeAccount($unlimitedHolder, '0.00');
		$unlimitedAccount->unlimited = true;
		$unlimitedAccount->creditLimit = null;

		if (! $accounts->saveAccount($unlimitedAccount)) {
			return json_encode($unlimitedAccount->getErrors());
		}

		$reloaded = expect($accounts->getFreshAccountById((int) $unlimitedAccount->id), Account::class);
		$holderBuyer = expect($accounts->getBuyer($reloaded, (int) $unlimitedHolder->id), Buyer::class);

		if ($accounts->getAvailableCredit($holderBuyer) !== null || ! $accounts->canCharge($holderBuyer, money('1000000.00'))) {
			return 'limited';
		}

		$holderBuyer->sublimit = money('50.00');
		$accounts->saveBuyer($holderBuyer);

		return same($accounts->getAvailableCredit($holderBuyer), '50.00');
	});
	check('a limited account needs a credit limit', function () use ($accounts): bool|string {
		$limitless = new Account([
			'storeId' => commerce()->getStores()->getPrimaryStore()?->id,
			'holderId' => makeUser('nolimit')->id,
		]);

		return ! $accounts->saveAccount($limitless) && $limitless->hasErrors('creditLimit') ? true : 'saved';
	});

	section('Sublimit rules');

	check('a sublimit can’t go below what the buyer owes', function () use ($aliceBuyer, $accounts): bool|string {
		$aliceBuyer->sublimit = money('150.00');
		$saved = $accounts->saveBuyer($aliceBuyer);
		$aliceBuyer->sublimit = money('300.00');

		return ! $saved && $aliceBuyer->hasErrors('sublimit') ? true : 'saved a sublimit below what was owed';
	});

	check('ceiling mode allows sublimits to add up past the limit', function () use ($bobBuyer, $accounts): bool|string {
		$bobBuyer->sublimit = money('900.00');
		$saved = $accounts->saveBuyer($bobBuyer);
		$bobBuyer->sublimit = null;
		$accounts->saveBuyer($bobBuyer);

		return $saved ? true : json_encode($bobBuyer->getErrors());
	});

	check('reserved can’t become the default while an account on the default is over-reserved', function () use ($account, $bobBuyer, $accounts, $holder): bool|string {
		$account->sublimitMode = null;
		$accounts->saveAccount($account);
		$bobBuyer->sublimit = money('900.00');
		$accounts->saveBuyer($bobBuyer);
		$overReservedSettings = new Settings([
			'defaultSublimitMode' => 'reserved',
		]);
		$overReservedSettings->validate();

		$bobBuyer->sublimit = null;
		$accounts->saveBuyer($bobBuyer);
		$fittingSettings = new Settings([
			'defaultSublimitMode' => 'reserved',
		]);
		$fittingSettings->validate();

		$account->sublimitMode = SublimitMode::Ceiling;
		$accounts->saveAccount($account);

		$refusedError = (string) $overReservedSettings->getFirstError('defaultSublimitMode');
		$fittingError = (string) $fittingSettings->getFirstError('defaultSublimitMode');

		return str_contains($refusedError, $holder->getName()) && ! str_contains($fittingError, $holder->getName()) ? true : 'refused: ' . $refusedError . ' / fitting: ' . $fittingError;
	});

	section('Available credit, reserved mode');

	$account->sublimitMode = SublimitMode::Reserved;
	check('switching to reserved works while sublimits fit the limit', fn () => $accounts->saveAccount($account) ? true : json_encode($account->getErrors()));
	check('a buyer without a sublimit can’t touch reserved room', fn (): bool|string => same($accounts->getAvailableCredit($bobBuyer), '0.00'));
	check('a reserving buyer keeps the room the account has left', fn (): bool|string => same($accounts->getAvailableCredit($aliceBuyer), '50.00'));

	check('reserved sublimits can’t add up past the limit', function () use ($bobBuyer, $accounts): bool|string {
		$bobBuyer->sublimit = money('800.00');
		$saved = $accounts->saveBuyer($bobBuyer);
		$bobBuyer->sublimit = null;

		return ! $saved && $bobBuyer->hasErrors('sublimit') ? true : 'saved sublimits over the limit';
	});

	check('lowering the limit under the reserved total is refused', function () use ($account, $accounts): bool|string {
		$account->creditLimit = money('250.00');
		$saved = $accounts->saveAccount($account);
		$account->creditLimit = money('1000.00');

		return ! $saved && $account->hasErrors('creditLimit') ? true : 'saved a limit under the reserved total';
	});

	$account->creditLimit = money('2000.00');
	$accounts->saveAccount($account);
	check('unreserved room is the limit less what’s owed and every unspent reserve', fn (): bool|string => same($accounts->getAvailableCredit($bobBuyer), '950.00'));

	$account->status = AccountStatus::Suspended;
	$accounts->saveAccount($account);
	check('a suspended account has no available credit', fn (): bool|string => same($accounts->getAvailableCredit($aliceBuyer), '0.00'));
	$account->status = AccountStatus::Active;
	$account->creditLimit = money('1000.00');
	$account->sublimitMode = SublimitMode::Ceiling;
	$accounts->saveAccount($account);

	section('Invoices');

	$firstInvoice = expect($invoices->issueInvoice($account), Invoice::class);
	check('the invoice has one line per buyer', fn () => count($firstInvoice->getLines()) === 2 ? true : count($firstInvoice->getLines()) . ' lines');
	check('the invoice total is what was charged', fn (): bool|string => same($invoices->getTotal($firstInvoice), '950.00'));
	check('the invoice is due after the payment terms', function () use ($firstInvoice): bool|string {
		// Compare in UTC so a daylight saving change between the two dates doesn’t shorten the gap
		$utc = new DateTimeZone('UTC');
		$dateIssued = expect($firstInvoice->dateIssued, DateTime::class);
		$dateDue = expect($firstInvoice->dateDue, DateTime::class);
		$days = (clone $dateIssued)->setTimezone($utc)->diff((clone $dateDue)->setTimezone($utc))->days;

		return $days === 30 ? true : 'due after ' . $days . ' days';
	});
	check('an invoice’s payment terms can change, moving its due date', function () use ($invoices, $firstInvoice): bool|string {
		$invoices->setPaymentTerms($firstInvoice, 45);
		$reloaded = expect($invoices->getInvoicesByIds([(int) $firstInvoice->id])[(int) $firstInvoice->id] ?? null, Invoice::class);
		$invoices->setPaymentTerms($firstInvoice, 30);

		return $reloaded->getPaymentTerms() === 45 ? true : 'terms ' . $reloaded->getPaymentTerms();
	});
	check('saving an invoice’s terms across a daylight saving change keeps the day count', function () use ($invoices, $firstInvoice): bool|string {
		$original = clone $firstInvoice;
		$firstInvoice->dateIssued = new DateTime('2026-03-01 15:00:00', new DateTimeZone('UTC'));
		$firstInvoice->dateIssued->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

		$invoices->setPaymentTerms($firstInvoice, 30);
		$invoices->setPaymentTerms($firstInvoice, $firstInvoice->getPaymentTerms());

		$terms = $firstInvoice->getPaymentTerms();
		$firstInvoice->dateIssued = $original->dateIssued;
		$invoices->setPaymentTerms($firstInvoice, 30);

		return $terms === 30 ? true : 'terms ' . $terms . ' in ' . Craft::$app->getTimeZone();
	});
	check('issuing again with nothing new creates no invoice', fn (): true|string => $invoices->issueInvoice($account) === null ? true : 'issued an empty invoice');

	$aliceLine = null;
	$bobLine = null;
	foreach ($firstInvoice->getLines() as $line) {
		if ($line->buyerId === $aliceBuyer->id) {
			$aliceLine = $line;
		} elseif ($line->buyerId === $bobBuyer->id) {
			$bobLine = $line;
		}
	}

	$aliceLine = expect($aliceLine, InvoiceLine::class);
	$bobLine = expect($bobLine, InvoiceLine::class);

	section('Payments');

	$check = new Payment([
		'accountId' => $account->id,
		'amount' => money('500.00'),
		'method' => PaymentMethod::Check,
		'reference' => 'Check 1042',
		'dateReceived' => new DateTime(),
	]);
	check('a payment records', fn () => $payments->recordPayment($check) ? true : json_encode($check->getErrors()));
	check('an unapplied payment changes no buyer’s balance', fn (): bool|string => same($accounts->getOwedByBuyer($aliceBuyer), '200.00'));
	check('an unapplied payment changes no available credit', fn (): bool|string => same($accounts->getAvailableCredit($aliceBuyer), '50.00'));
	check('the whole payment starts unapplied', fn (): bool|string => same($payments->getUnapplied($check), '500.00'));

	check('applying more than the line’s balance is refused', function () use ($payments, $check, $aliceLine): bool|string {
		try {
			$payments->applyPayment($check, $aliceLine, money('250.00'));
		} catch (LedgerException) {
			return true;
		}

		return 'applied over the balance';
	});

	$aliceApplication = $payments->applyPayment($check, $aliceLine, money('200.00'));
	check('applying lowers what the line’s buyer owes', fn (): bool|string => same($accounts->getOwedByBuyer($aliceBuyer), '0.00'));
	check('applying reopens the buyer’s sublimit', fn (): bool|string => same($accounts->getAvailableCredit($aliceBuyer), '250.00'));
	check('applying leaves other buyers’ balances alone', fn (): bool|string => same($accounts->getOwedByBuyer($bobBuyer), '750.00'));
	check('the payment’s unapplied credit drops', fn (): bool|string => same($payments->getUnapplied($check), '300.00'));

	check('applying more than the unapplied credit is refused', function () use ($payments, $check, $bobLine): bool|string {
		try {
			$payments->applyPayment($check, $bobLine, money('400.00'));
		} catch (LedgerException) {
			return true;
		}

		return 'applied more than was unapplied';
	});

	$payments->applyPayment($check, $bobLine, money('300.00'));
	check('a partly paid invoice shows as partially paid', fn () => $invoices->getStatus($firstInvoice) === InvoiceStatus::PartiallyPaid ? true : $invoices->getStatus($firstInvoice)->value);

	check('voiding an invoice with applications is refused', fn (): true|string => $invoices->voidInvoice($firstInvoice) === false ? true : 'voided');

	$payments->reverseApplication($aliceApplication);
	check('reversing an application puts the amount back on the buyer', fn (): bool|string => same($accounts->getOwedByBuyer($aliceBuyer), '200.00'));
	check('reversing an application returns it to unapplied credit', fn (): bool|string => same($payments->getUnapplied($check), '200.00'));
	check('the ledger keeps both the payment and its reversal', function () use ($ledger, $account): bool|string {
		$types = array_map(static fn ($entry): \fostercommerce\netterms\enums\EntryType => $entry->type, $ledger->getEntriesByAccountId((int) $account->id));

		return in_array(EntryType::Payment, $types, true) && in_array(EntryType::Reversal, $types, true) ? true : 'missing entries';
	});

	foreach ($payments->getApplicationsByPaymentId((int) $check->id, $check->amount->getCurrency()) as $application) {
		if ($application->getIsActive()) {
			$payments->reverseApplication($application);
		}
	}

	check('an invoice without applications can be voided', fn (): true|string => $invoices->voidInvoice($firstInvoice) ? true : 'not voided');
	check('a voided invoice’s entries go on the next invoice', function () use ($invoices, $account): bool|string {
		$reissued = $invoices->issueInvoice($account);

		return $reissued instanceof Invoice && $invoices->getTotal($reissued)->equals(money('950.00')) ? true : 'reissued total wrong';
	});

	section('Recording a payment with allocations');

	$reissuedLines = $invoices->getOpenLinesByAccountId((int) $account->id);

	check('a payment allocated past its amount is refused and nothing is saved', function () use ($payments, $account, $reissuedLines): bool|string {
		$paymentCount = count($payments->getPaymentsByAccountId((int) $account->id));
		$overAllocated = new Payment([
			'accountId' => $account->id,
			'amount' => money('100.00'),
			'method' => PaymentMethod::Check,
			'dateReceived' => new DateTime(),
		]);

		try {
			$payments->recordPayment($overAllocated, [
				(int) $reissuedLines[0]->id => money('80.00'),
				(int) $reissuedLines[1]->id => money('80.00'),
			]);
		} catch (LedgerException) {
			return count($payments->getPaymentsByAccountId((int) $account->id)) === $paymentCount ? true : 'a payment was saved';
		}

		return 'accepted';
	});

	check('a payment can be split across invoice lines as it is recorded', function () use ($payments, $account, $reissuedLines, $invoices): bool|string {
		$split = new Payment([
			'accountId' => $account->id,
			'amount' => money('100.00'),
			'method' => PaymentMethod::Wire,
			'dateReceived' => new DateTime(),
		]);
		$balanceBefore = $invoices->getLineBalance($reissuedLines[0]);
		$payments->recordPayment($split, [
			(int) $reissuedLines[0]->id => money('60.00'),
			(int) $reissuedLines[1]->id => money('30.00'),
		]);

		if (! $invoices->getLineBalance($reissuedLines[0])->equals($balanceBefore->subtract(money('60.00')))) {
			return 'first line not paid down';
		}

		return same($payments->getUnapplied($split), '10.00');
	});

	section('Gateway');

	$gatewayId = netTermsGatewayId();

	$variant = fixtureVariant();
	$unitPrice = Amounts::toMoney((string) $variant->getPrice(), $currency);
	$gatewayAccount = makeAccount($outsider, Amounts::toDecimal($unitPrice->multiply('5')->divide('2')));
	$outsiderBuyer = expect($accounts->getBuyer($gatewayAccount, (int) $outsider->id), Buyer::class);
	check('a new account has its holder as a buyer with no sublimit', fn (): true|string => $outsiderBuyer->active && $outsiderBuyer->sublimit === null ? true : 'inactive or capped');
	$order = makeOrder($outsider, $variant, 2, $gatewayId);
	$gateway = expect(commerce()->getGateways()->getGatewayById($gatewayId), NetTerms::class);
	$gateway->paymentType = TransactionRecord::TYPE_PURCHASE;

	$payingUserId = null;
	check('the gateway is hidden from someone who isn’t a buyer', fn (): true|string => $gateway->availableForUseWithOrder($order) === false ? true : 'offered');

	$payingUserId = $outsider->id;
	check('the gateway is offered when the order fits', fn (): true|string => $gateway->availableForUseWithOrder($order) ? true : 'hidden');
	check('paying with the gateway succeeds', fn (): string|true => pay($order) ?? true);
	check('the order is paid in full', function () use ($order): bool|string {
		$paidOrder = expect(Order::find()->id($order->id)->one(), Order::class);

		return $paidOrder->getPaidStatus() === Order::PAID_STATUS_PAID ? true : (string) $paidOrder->getPaidStatus();
	});
	check('the charge is on the ledger for the order total', function () use ($order, $accounts, $outsiderBuyer, $currency): bool|string {
		$totalPaid = Amounts::toMoney((string) expect(Order::find()->id($order->id)->one(), Order::class)->getTotalPaid(), $currency);

		return same($accounts->getOwedByBuyer($outsiderBuyer), Amounts::toDecimal($totalPaid));
	});

	$bigOrder = makeOrder($outsider, $variant, 1, $gatewayId);
	check('the gateway is still offered to a buyer short of credit, to say why', fn (): true|string => $gateway->availableForUseWithOrder($bigOrder) ? true : 'hidden');
	check('its payment form says there isn’t enough credit', fn (): true|string => str_contains((string) $gateway->getPaymentFormHtml([
		'order' => $bigOrder,
	]), 'enough available credit') ? true : 'no notice');
	$owedBeforeDecline = Amounts::toDecimal($accounts->getOwedByBuyer($outsiderBuyer));
	check('a charge over available credit is declined', fn (): true|string => pay($bigOrder) !== null ? true : 'paid');
	check('a declined charge writes no entry', fn (): bool|string => same($accounts->getOwedByBuyer($outsiderBuyer), $owedBeforeDecline));

	$outsiderInvoice = expect($invoices->issueInvoice($gatewayAccount), Invoice::class);
	$outsiderLine = $outsiderInvoice->getLines()[0];

	check('a Commerce refund writes a refund entry', function () use ($order, $accounts, $outsiderBuyer): bool|string {
		$paidOrder = expect(Order::find()->id($order->id)->one(), Order::class);
		$purchase = null;
		foreach ($paidOrder->getTransactions() as $transaction) {
			if ($transaction->type === TransactionRecord::TYPE_PURCHASE && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
				$purchase = $transaction;
			}
		}

		$owedBefore = $accounts->getOwedByBuyer($outsiderBuyer);
		$refund = commerce()->getPayments()->refundTransaction(expect($purchase, Transaction::class), 5.0);

		if ($refund->status !== TransactionRecord::STATUS_SUCCESS) {
			return 'refund ' . $refund->status . ': ' . $refund->message;
		}

		return $owedBefore->subtract(money('5.00'))->equals($accounts->getOwedByBuyer($outsiderBuyer)) ? true : 'owed ' . Amounts::toDecimal($accounts->getOwedByBuyer($outsiderBuyer));
	});

	check('a refund of an invoiced charge lowers that invoice line', fn () => $invoices->getLineAmount($outsiderLine)->equals($accounts->getOwedByBuyer($outsiderBuyer)) ? true : 'line ' . Amounts::toDecimal($invoices->getLineAmount($outsiderLine)));

	check('a buyer’s own order charges the account they buy on', function () use ($accounts, $variant, $gatewayId, $account): bool|string {
		// Stand in for a buyer who doesn't hold the account, signed in and paying for their own order
		$bobOnly = makeUser('bob-only');
		$bobBuyer = makeBuyer($account, $bobOnly, null);
		$bobsOrder = makeOrder($bobOnly, $variant, 1, $gatewayId);
		Craft::$app->getUser()->setIdentity($bobOnly);
		$resolved = $accounts->getAccountForOrder($bobsOrder);
		Craft::$app->getUser()->setIdentity(null);

		return $resolved?->id === $account->id ? true : 'resolved to ' . var_export($resolved?->id, true) . ' for buyer ' . $bobBuyer->id;
	});

	section('Card payments recorded by staff');

	check('a card payment taken by phone is recorded and applied like a check', function () use ($payments, $invoices, $gatewayAccount, $outsiderLine, $accounts, $outsiderBuyer): bool|string {
		$lineBalance = $invoices->getLineBalance($outsiderLine);
		$cardPayment = new Payment([
			'accountId' => $gatewayAccount->id,
			'amount' => $lineBalance,
			'method' => PaymentMethod::Card,
			'reference' => 'Phone',
			'dateReceived' => new DateTime(),
		]);

		if (! $payments->recordPayment($cardPayment, [
			(int) $outsiderLine->id => $lineBalance,
		])) {
			return json_encode($cardPayment->getErrors());
		}

		return same($accounts->getOwedByBuyer($outsiderBuyer), '0.00');
	});

	check('the invoice is paid', fn () => $invoices->getStatus($outsiderInvoice) === InvoiceStatus::Paid ? true : $invoices->getStatus($outsiderInvoice)->value);

	check('a refund the paid line can’t absorb waits for the next invoice', function () use ($order, $invoices, $outsiderLine, $accounts, $outsiderBuyer): bool|string {
		$lineAmountBefore = $invoices->getLineAmount($outsiderLine);
		$paidOrder = expect(Order::find()->id($order->id)->one(), Order::class);
		$purchase = null;
		foreach ($paidOrder->getTransactions() as $transaction) {
			if ($transaction->type === TransactionRecord::TYPE_PURCHASE && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
				$purchase = $transaction;
			}
		}

		commerce()->getPayments()->refundTransaction(expect($purchase, Transaction::class), 5.0);

		if (! $invoices->getLineAmount($outsiderLine)->equals($lineAmountBefore)) {
			return 'the paid line changed';
		}

		return same($accounts->getOwedByBuyer($outsiderBuyer), '-5.00');
	});

	section('Typed amounts');

	check('a blank amount reads as no amount', fn (): true|string => Amounts::fromInput('  ', $currency, 'en-US') instanceof \Money\Money ? 'not null' : true);
	check('an amount that isn’t a number is refused', function () use ($currency): bool|string {
		try {
			Amounts::fromInput('12abc', $currency, 'en-US');
		} catch (InvalidAmountException) {
			return true;
		}

		return 'accepted';
	});
	check('a zero sublimit shows as 0 in its input', fn (): string|true => Amounts::toNumber(money('0.00')) === '0' ? true : var_export(Amounts::toNumber(money('0.00')), true));

	section('Deleting');

	check('an account with payments can’t be deleted', fn (): true|string => $accounts->deleteAccount($account) === false ? true : 'deleted');
	check('an account with no history can be deleted', function () use ($accounts): bool|string {
		$emptyAccount = makeAccount(makeUser('empty'), '100.00');

		return $accounts->deleteAccount($emptyAccount) ? true : 'not deleted';
	});
	check('a buyer with history is deactivated rather than removed', function () use ($accounts, $aliceBuyer): bool|string {
		$accounts->removeBuyer($aliceBuyer);
		$reloaded = $accounts->getBuyerById((int) $aliceBuyer->id);

		return $reloaded instanceof Buyer && ! $reloaded->active ? true : 'removed or still active';
	});
	check('an inactive buyer has no available credit', fn (): bool|string => same($accounts->getAvailableCredit(expect($accounts->getBuyerById((int) $aliceBuyer->id), Buyer::class)), '0.00'));
	check('adding a deactivated buyer again reactivates their row', function () use ($accounts, $account, $alice, $aliceBuyer): bool|string {
		$readdedBuyer = new Buyer([
			'accountId' => $account->id,
			'userId' => $alice->id,
		]);
		$saved = $accounts->saveBuyer($readdedBuyer);
		$reloaded = $accounts->getBuyerById((int) $aliceBuyer->id);

		return $saved && $readdedBuyer->id === $aliceBuyer->id && $reloaded instanceof Buyer && $reloaded->active ? true : json_encode($readdedBuyer->getErrors());
	});
	check('adding an active buyer again is refused', function () use ($accounts, $account, $alice): bool|string {
		$duplicateBuyer = new Buyer([
			'accountId' => $account->id,
			'userId' => $alice->id,
		]);

		return ! $accounts->saveBuyer($duplicateBuyer) && $duplicateBuyer->hasErrors('userId') ? true : 'saved';
	});
	check('a user who holds an account can’t be deleted', fn (): true|string => Craft::$app->getElements()->deleteElement($holder) === false ? true : 'deleted');

	section('Order billing');

	$gateway->paymentType = TransactionRecord::TYPE_AUTHORIZE;
	$creditOrders = $plugin->getCreditOrders();

	try {
		$orderHolder = makeUser('orderholder');
		$orderAccount = makeAccount($orderHolder, Amounts::toDecimal($unitPrice->multiply('20')));
		$orderBuyer = expect($accounts->getBuyer($orderAccount, (int) $orderHolder->id), Buyer::class);
		$payingUserId = $orderHolder->id;
		$reload = static fn (Order $order): Order => expect(Order::find()->id($order->id)->one(), Order::class);
		$outstanding = static fn (Order $order): Money => Amounts::toMoney((string) $reload($order)->getOutstandingBalance(), $currency);

		$firstOrder = makeOrder($orderHolder, $variant, 1, $gatewayId);
		$secondOrder = makeOrder($orderHolder, $variant, 2, $gatewayId);
		check('an order charged in order billing is authorized, not paid', function () use ($firstOrder, $reload): bool|string {
			if (($error = pay($firstOrder)) !== null) {
				return $error;
			}

			$charged = $reload($firstOrder);

			return $charged->isCompleted && $charged->getPaidStatus() === Order::PAID_STATUS_UNPAID ? true : $charged->getPaidStatus();
		});
		check('without an order terms field, an order is due after the account’s terms', function () use ($creditOrders, $firstOrder, $orderAccount, $reload): bool|string {
			$order = $reload($firstOrder);
			$dateDue = expect($creditOrders->getDateDue($order, $orderAccount), DateTime::class);
			$days = DateTime::createFromInterface(expect($order->dateOrdered, DateTimeInterface::class))->diff($dateDue)->days;

			return $creditOrders->getPaymentTerms($order, $orderAccount) === $orderAccount->getEffectivePaymentTerms() && $days === $orderAccount->getEffectivePaymentTerms() ? true : 'due after ' . $days . ' days';
		});
		check('a second order charges the same way', fn (): string|true => pay($secondOrder) ?? true);
		check('order billing writes no ledger entries', fn (): bool|string => $ledger->getEntriesByAccountId((int) $orderAccount->id) === [] ? true : 'entries written');
		// What is owed is the sum of Commerce's balances on the orders
		$owedMatchesOrders = static fn (): bool|string => same($accounts->getOwedByBuyer($orderBuyer), Amounts::toDecimal($outstanding($firstOrder)->add($outstanding($secondOrder))));
		check('what a buyer owes is their orders’ balances', $owedMatchesOrders);
		check('both orders are open, oldest first', function () use ($creditOrders, $orderAccount, $firstOrder, $secondOrder): bool|string {
			$openIds = array_map(static fn (Order $openOrder): int => (int) $openOrder->id, $creditOrders->getOpenOrders($orderAccount));

			return $openIds === [(int) $firstOrder->id, (int) $secondOrder->id] ? true : json_encode($openIds);
		});
		check('the gateway isn’t offered to pay what a completed order owes', fn (): string|true => $gateway->availableForUseWithOrder($reload($firstOrder)) === false ? true : 'offered');

		$firstTotal = $outstanding($firstOrder);
		[$secondHalf] = $outstanding($secondOrder)->allocateTo(2);
		$check = new Payment([
			'accountId' => $orderAccount->id,
			'amount' => $firstTotal->add($secondHalf)->add(money('10.00')),
			'method' => PaymentMethod::Check,
			'reference' => 'Check 2001',
			'dateReceived' => new DateTime(),
		]);

		check('one check can pay one order in full and part of another', function () use ($payments, $check, $firstOrder, $secondOrder, $firstTotal, $secondHalf, $reload): bool|string {
			$payments->recordPayment($check, [
				(int) $firstOrder->id => $firstTotal,
				(int) $secondOrder->id => $secondHalf,
			]);

			return $reload($firstOrder)->getPaidStatus() === Order::PAID_STATUS_PAID && $reload($secondOrder)->getPaidStatus() === Order::PAID_STATUS_PARTIAL
				? true
				: $reload($firstOrder)->getPaidStatus() . ' / ' . $reload($secondOrder)->getPaidStatus();
		});
		check('each amount is a capture on its order with the check’s reference', function () use ($reload, $firstOrder): bool|string {
			foreach ($reload($firstOrder)->getTransactions() as $transaction) {
				if ($transaction->type === TransactionRecord::TYPE_CAPTURE && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
					return $transaction->reference === 'Check 2001' ? true : (string) $transaction->reference;
				}
			}

			return 'no capture';
		});
		check('what is owed follows the payments', $owedMatchesOrders);
		check('the rest of the check stays unapplied', fn (): bool|string => same($payments->getUnapplied($check), '10.00'));
		check('applying more than an order’s balance is refused', function () use ($creditOrders, $check, $secondOrder, $outstanding): bool|string {
			try {
				$creditOrders->applyToOrders($check, [
					(int) $secondOrder->id => $outstanding($secondOrder)->add(money('1.00')),
				]);
			} catch (LedgerException) {
				return true;
			}

			return 'applied';
		});

		check('reversing an application reopens the order and returns the amount to the check', function () use ($payments, $check, $secondOrder, $outstanding, $secondHalf): bool|string {
			$balanceBefore = $outstanding($secondOrder);
			foreach ($payments->getApplicationsByPaymentId((int) $check->id, $check->amount->getCurrency()) as $application) {
				if ($application->orderId === $secondOrder->id) {
					$payments->reverseApplication($application);
				}
			}

			if (! $outstanding($secondOrder)->equals($balanceBefore->add($secondHalf))) {
				return 'balance ' . Amounts::toDecimal($outstanding($secondOrder));
			}

			return same($payments->getUnapplied($check), Amounts::toDecimal($secondHalf->add(money('10.00'))));
		});
		check('a reversal is a refund marked as one', function () use ($reload, $secondOrder): bool|string {
			foreach ($reload($secondOrder)->getTransactions() as $transaction) {
				if ($transaction->type === TransactionRecord::TYPE_REFUND) {
					return $transaction->code === CreditOrders::REVERSAL_TRANSACTION_CODE ? true : (string) $transaction->code;
				}
			}

			return 'no refund';
		});
		check('a reversed application can’t be reversed again', function () use ($payments, $check, $secondOrder): bool|string {
			foreach ($payments->getApplicationsByPaymentId((int) $check->id, $check->amount->getCurrency()) as $application) {
				if ($application->orderId === $secondOrder->id) {
					try {
						$payments->reverseApplication($application);
					} catch (LedgerException) {
						return true;
					}

					return 'reversed again';
				}
			}

			return 'no application';
		});

		check('a payment made online lowers what is owed with nothing recorded by Net Terms', function () use ($secondOrder, $reload, $payments, $orderAccount, $owedMatchesOrders): bool|string {
			$onlineOrder = $reload($secondOrder);
			$onlineOrder->gatewayId = (int) commerce()->getGateways()->getGatewayByHandle('process')?->id;
			$transaction = commerce()->getTransactions()->createTransaction($onlineOrder, null, TransactionRecord::TYPE_PURCHASE);
			$transaction->amount = 10.0;
			$transaction->paymentAmount = 10.0;
			$transaction->status = TransactionRecord::STATUS_SUCCESS;
			commerce()->getTransactions()->saveTransaction($transaction);
			$onlineOrder->updateOrderPaidInformation();

			if (count($payments->getPaymentsByAccountId((int) $orderAccount->id)) !== 1) {
				return 'a payment was recorded';
			}

			return $owedMatchesOrders();
		});

		check('a Commerce refund of a check’s capture reopens the order and counts as refunded', function () use ($firstOrder, $reload, $check, $payments, $outstanding): bool|string {
			$unappliedBefore = $payments->getUnapplied($check);
			$capture = null;
			foreach ($reload($firstOrder)->getTransactions() as $transaction) {
				if ($transaction->type === TransactionRecord::TYPE_CAPTURE && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
					$capture = $transaction;
				}
			}

			commerce()->getPayments()->refundTransaction(expect($capture, Transaction::class), 5.0);

			if (! $outstanding($firstOrder)->equals(money('5.00'))) {
				return 'balance ' . Amounts::toDecimal($outstanding($firstOrder));
			}

			if (! $payments->getUnapplied($check)->equals($unappliedBefore)) {
				return 'unapplied changed';
			}

			return same($payments->getRefunded($check), '5.00');
		});
		check('what is owed follows the refund', $owedMatchesOrders);

		check('an edited order total changes what is owed with no ledger entry', function () use ($firstOrder, $reload, $variant, $ledger, $orderAccount, $owedMatchesOrders): bool|string {
			$editedOrder = $reload($firstOrder);
			$editedOrder->setRecalculationMode(Order::RECALCULATION_MODE_ALL);
			$editedOrder->setLineItems([
				commerce()->getLineItems()->create($editedOrder, [
					'purchasableId' => $variant->id,
					'qty' => 3,
				]),
			]);
			Craft::$app->getElements()->saveElement($editedOrder, false);

			if ($ledger->getEntriesByAccountId((int) $orderAccount->id) !== []) {
				return 'entries written';
			}

			return $owedMatchesOrders();
		});

		check('an account that owes money is unsettled', fn (): bool|string => in_array($orderAccount->id, array_map(static fn (Account $unsettled): ?int => $unsettled->id, $accounts->getUnsettledAccounts()), true) ? true : 'settled');
		check('the payment type can’t change while an account is unsettled', function () use ($gateway): bool|string {
			$changed = clone $gateway;
			$changed->paymentType = TransactionRecord::TYPE_PURCHASE;

			return ! $changed->validate(['paymentType']) && $changed->hasErrors('paymentType') ? true : 'changed';
		});
	} finally {
		$gateway->paymentType = TransactionRecord::TYPE_PURCHASE;
		$payingUserId = null;
	}

	section('Order edits in invoice billing');

	try {
		$editHolder = makeUser('editholder');
		$editAccount = makeAccount($editHolder, Amounts::toDecimal($unitPrice->multiply('20')));
		$editBuyer = expect($accounts->getBuyer($editAccount, (int) $editHolder->id), Buyer::class);
		$payingUserId = $editHolder->id;
		$editOrder = makeOrder($editHolder, $variant, 2, $gatewayId);
		$runQueue = static function (): void {
			/** @var craft\queue\Queue $queue */
			$queue = Craft::$app->getQueue();
			$queue->run();
		};
		$setQty = static function (int $qty) use ($editOrder, $variant, $runQueue): Order {
			$edited = expect(Order::find()->id($editOrder->id)->one(), Order::class);
			$edited->setRecalculationMode(Order::RECALCULATION_MODE_ALL);
			$edited->setLineItems([
				commerce()->getLineItems()->create($edited, [
					'purchasableId' => $variant->id,
					'qty' => $qty,
				]),
			]);
			Craft::$app->getElements()->saveElement($edited, false);
			$runQueue();

			return expect(Order::find()->id($editOrder->id)->one(), Order::class);
		};
		// The ledger and Commerce both follow the edit: what the buyer owes is the order's total, and the order stays paid
		$followsEdit = static function (Order $edited) use ($accounts, $editBuyer, $currency): bool|string {
			if ($edited->getPaidStatus() !== Order::PAID_STATUS_PAID) {
				return $edited->getPaidStatus();
			}

			return same($accounts->getOwedByBuyer($editBuyer), Amounts::toDecimal(Amounts::toMoney((string) $edited->getTotalPrice(), $currency)));
		};

		check('an order paid on account in invoice billing', fn (): string|true => pay($editOrder) ?? true);
		check('a raised order total is charged and the order stays paid', fn (): bool|string => $followsEdit($setQty(3)));
		check('a lowered order total is taken off and the order stays paid', fn (): bool|string => $followsEdit($setQty(1)));

		$netTermsPurchase = static function (Order $order): Transaction {
			foreach ($order->getTransactions() as $transaction) {
				if ($transaction->type === TransactionRecord::TYPE_PURCHASE && $transaction->status === TransactionRecord::STATUS_SUCCESS && $transaction->getGateway() instanceof NetTerms) {
					return $transaction;
				}
			}

			throw new RuntimeException('No Net Terms purchase');
		};
		check('a refund before an edit isn’t given twice', function () use ($setQty, $unitPrice, $netTermsPurchase, $followsEdit): bool|string {
			$order = $setQty(2);
			commerce()->getPayments()->refundTransaction($netTermsPurchase($order), (float) Amounts::toDecimal($unitPrice), 'Goodwill');

			return $followsEdit($setQty(1));
		});
		check('a raise paid online before the job runs isn’t charged again', function () use ($editOrder, $variant, $runQueue, $accounts, $editBuyer): bool|string {
			$edited = expect(Order::find()->id($editOrder->id)->one(), Order::class);
			$edited->setRecalculationMode(Order::RECALCULATION_MODE_ALL);
			$edited->setLineItems([
				commerce()->getLineItems()->create($edited, [
					'purchasableId' => $variant->id,
					'qty' => 2,
				]),
			]);
			Craft::$app->getElements()->saveElement($edited, false);

			// Pay the new balance through another gateway before the queue runs
			$paid = expect(Order::find()->id($editOrder->id)->one(), Order::class);
			$paid->gatewayId = (int) commerce()->getGateways()->getGatewayByHandle('process')?->id;
			$online = commerce()->getTransactions()->createTransaction($paid, null, TransactionRecord::TYPE_PURCHASE);
			$online->amount = $paid->getOutstandingBalance();
			$online->paymentAmount = $paid->getOutstandingBalance();
			$online->status = TransactionRecord::STATUS_SUCCESS;
			commerce()->getTransactions()->saveTransaction($online);
			$paid->updateOrderPaidInformation();
			$owedBefore = $accounts->getOwedByBuyer($editBuyer);
			$runQueue();

			$settled = expect(Order::find()->id($editOrder->id)->one(), Order::class);

			return $accounts->getOwedByBuyer($editBuyer)->equals($owedBefore) && $settled->getPaidStatus() === Order::PAID_STATUS_PAID
				? true
				: $settled->getPaidStatus() . ', owed ' . Amounts::toDecimal($accounts->getOwedByBuyer($editBuyer));
		});

		$cutHolder = makeUser('cutholder');
		$cutAccount = makeAccount($cutHolder, Amounts::toDecimal($unitPrice->multiply('20')));
		$cutBuyer = expect($accounts->getBuyer($cutAccount, (int) $cutHolder->id), Buyer::class);
		$payingUserId = $cutHolder->id;
		$cutOrder = makeOrder($cutHolder, $variant, 2, $gatewayId);
		pay($cutOrder);
		$setCutQty = static function (int $qty) use ($cutOrder, $variant, $runQueue): Order {
			$edited = expect(Order::find()->id($cutOrder->id)->one(), Order::class);
			$edited->setRecalculationMode(Order::RECALCULATION_MODE_ALL);
			$edited->setLineItems([
				commerce()->getLineItems()->create($edited, [
					'purchasableId' => $variant->id,
					'qty' => $qty,
				]),
			]);
			Craft::$app->getElements()->saveElement($edited, false);
			$runQueue();

			return expect(Order::find()->id($cutOrder->id)->one(), Order::class);
		};
		check('a cut larger than the first purchase is split across purchases', function () use ($setCutQty, $accounts, $cutBuyer, $currency): bool|string {
			$setCutQty(3);
			$cut = $setCutQty(1);

			foreach ($cut->getTransactions() as $transaction) {
				if ($transaction->type === TransactionRecord::TYPE_PURCHASE && commerce()->getTransactions()->refundableAmountForTransaction($transaction) < 0) {
					return 'over-refunded purchase ' . $transaction->id;
				}
			}

			return $cut->getPaidStatus() === Order::PAID_STATUS_PAID
				? same($accounts->getOwedByBuyer($cutBuyer), Amounts::toDecimal(Amounts::toMoney((string) $cut->getTotalPrice(), $currency)))
				: $cut->getPaidStatus();
		});
		check('the order editor shows an error when a raise doesn’t fit available credit', function () use ($cutOrder, $variant): bool|string {
			$edited = expect(Order::find()->id($cutOrder->id)->one(), Order::class);
			$edited->setLineItems([
				commerce()->getLineItems()->create($edited, [
					'purchasableId' => $variant->id,
					'qty' => 30,
				]),
			]);
			$edited->validate();

			return $edited->hasErrors('totalPrice') ? true : 'no error';
		});
		check('a raise that doesn’t fit fails the job with the reason and changes nothing', function () use ($creditOrders, $accounts, $cutOrder, $variant, $runQueue, $cutBuyer): bool|string {
			$owedBefore = $accounts->getOwedByBuyer($cutBuyer);
			$setQtyUnvalidated = static function (int $qty) use ($cutOrder, $variant): Order {
				$edited = expect(Order::find()->id($cutOrder->id)->one(), Order::class);
				$edited->setRecalculationMode(Order::RECALCULATION_MODE_ALL);
				$edited->setLineItems([
					commerce()->getLineItems()->create($edited, [
						'purchasableId' => $variant->id,
						'qty' => $qty,
					]),
				]);
				Craft::$app->getElements()->saveElement($edited, false);

				return $edited;
			};
			// Leave the buyer no room, so a one-unit raise doesn't fit
			$cutBuyer->sublimit = $owedBefore;
			if (! $accounts->saveBuyer($cutBuyer)) {
				return 'sublimit not saved: ' . json_encode($cutBuyer->getErrors());
			}

			$before = expect(Order::find()->id($cutOrder->id)->one(), Order::class);
			$quantityBefore = (int) $before->getTotalQty();
			$raised = $setQtyUnvalidated($quantityBefore + 1);
			$failure = null;
			try {
				$creditOrders->followTotalChange((int) $cutOrder->id, (string) ($raised->getTotalPrice() - $before->getTotalPrice()), 'net-terms-edit:check-over', null);
			} catch (InvalidAmountException $invalidAmountException) {
				$failure = $invalidAmountException->getMessage();
			}

			// Put the order and sublimit back, so the queued jobs find nothing to settle
			$setQtyUnvalidated($quantityBefore);
			$runQueue();
			$cutBuyer->sublimit = null;
			$accounts->saveBuyer($cutBuyer);

			if ($failure === null) {
				return 'no failure';
			}

			return $accounts->getOwedByBuyer($cutBuyer)->equals($owedBefore) ? true : 'owed changed';
		});
		check('a retried edit job settles the edit once', function () use ($creditOrders, $cutOrder, $variant, $unitPrice, $runQueue, $accounts, $cutBuyer): bool|string {
			$owedBefore = $accounts->getOwedByBuyer($cutBuyer);
			$edited = expect(Order::find()->id($cutOrder->id)->one(), Order::class);
			$edited->setRecalculationMode(Order::RECALCULATION_MODE_ALL);
			$edited->setLineItems([
				commerce()->getLineItems()->create($edited, [
					'purchasableId' => $variant->id,
					'qty' => 2,
				]),
			]);
			Craft::$app->getElements()->saveElement($edited, false);

			// Settle the raise twice under one key, as a retry would, before the queued job runs
			$creditOrders->followTotalChange((int) $cutOrder->id, Amounts::toDecimal($unitPrice), 'net-terms-edit:check-retry', null);
			$creditOrders->followTotalChange((int) $cutOrder->id, Amounts::toDecimal($unitPrice), 'net-terms-edit:check-retry', null);
			$runQueue();

			return same($accounts->getOwedByBuyer($cutBuyer)->subtract($owedBefore), Amounts::toDecimal($unitPrice));
		});
	} finally {
		$payingUserId = null;
	}

	section('Reminders');

	$reminderSettings = $plugin->getSettings();
	$reminderSettings->dueSoonReminderDays = 7;
	$reminderSettings->overdueReminderDays = 1;
	/** @var ArrayObject<int, string> $sentTo */
	$sentTo = new ArrayObject();
	$sentHandler = static function (yii\mail\MailEvent $mailEvent) use ($sentTo): void {
		if ($mailEvent->isSuccessful) {
			$sentTo->append((string) array_key_first((array) $mailEvent->message->getTo()));
		}
	};
	Event::on(craft\mail\Mailer::class, craft\mail\Mailer::EVENT_AFTER_SEND, $sentHandler);
	// Block mail to the site's own customers, since the simulated dates make their orders and invoices due too
	$fixturesOnlyHandler = static function (yii\mail\MailEvent $mailEvent) use ($tag): void {
		$recipient = (string) array_key_first((array) $mailEvent->message->getTo());
		$mailEvent->isValid = str_starts_with($recipient, $tag . '-');
	};
	Event::on(craft\mail\Mailer::class, craft\mail\Mailer::EVENT_BEFORE_SEND, $fixturesOnlyHandler);
	$sentToCount = static fn (string $email): int => count(array_filter($sentTo->getArrayCopy(), static fn (mixed $recipient): bool => $recipient === $email));

	$fullAllocation = static function (Invoice $invoice) use ($invoices): array {
		$amountsByLineId = [];
		foreach ($invoice->getLines() as $line) {
			$amountsByLineId[(int) $line->id] = $invoices->getLineBalance($line);
		}

		return $amountsByLineId;
	};

	try {
		$reminderHolder = makeUser('reminderholder');
		$reminderAccount = makeAccount($reminderHolder, Amounts::toDecimal($unitPrice->multiply('20')));
		$payingUserId = $reminderHolder->id;
		$reminderOrder = makeOrder($reminderHolder, $variant, 1, $gatewayId);
		pay($reminderOrder);
		$reminderInvoice = expect($invoices->issueInvoice($reminderAccount), Invoice::class);
		$invoiceDue = expect($reminderInvoice->dateDue, DateTime::class);
		$reminders = $plugin->getReminders();
		// A run at the given moment, as the console command makes one
		$runOn = static fn (DateTime $now): array => $reminders->sendReminders((clone $now)->modify('-2 days'), $now);

		check('a due-soon reminder whose moment came before the invoice existed is skipped', function () use ($runOn, $reminderSettings, $invoiceDue, $sentToCount, $reminderHolder): bool|string {
			$reminderSettings->dueSoonReminderDays = 45;
			try {
				$runOn((clone $invoiceDue)->modify('-45 days'));
			} finally {
				$reminderSettings->dueSoonReminderDays = 7;
			}

			return $sentToCount((string) $reminderHolder->email) === 0 ? true : 'sent';
		});
		check('no reminder goes out before the due-soon moment', function () use ($runOn, $invoiceDue, $sentToCount, $reminderHolder): bool|string {
			$runOn((clone $invoiceDue)->modify('-10 days'));

			return $sentToCount((string) $reminderHolder->email) === 0 ? true : 'sent';
		});
		check('the due-soon reminder goes to the account holder on the run after its moment', function () use ($runOn, $invoiceDue, $sentToCount, $reminderHolder): bool|string {
			$runOn((clone $invoiceDue)->modify('-7 days')->modify('+1 hour'));

			return $sentToCount((string) $reminderHolder->email) === 1 ? true : $sentToCount((string) $reminderHolder->email) . ' sent';
		});
		check('a reminder goes out once', function () use ($reminders, $invoiceDue, $sentToCount, $reminderHolder): bool|string {
			$reminders->sendReminders((clone $invoiceDue)->modify('-8 days'), (clone $invoiceDue)->modify('-6 days'));

			return $sentToCount((string) $reminderHolder->email) === 1 ? true : $sentToCount((string) $reminderHolder->email) . ' sent';
		});
		check('the overdue reminder goes out after the overdue days', function () use ($runOn, $invoiceDue, $sentToCount, $reminderHolder): bool|string {
			$runOn((clone $invoiceDue)->modify('+1 day')->modify('+1 hour'));

			return $sentToCount((string) $reminderHolder->email) === 2 ? true : $sentToCount((string) $reminderHolder->email) . ' sent';
		});
		check('the reminder uses the system message', function () use ($reminderInvoice, $tag): bool|string {
			$message = Craft::$app->getMailer()->composeFromKey(fostercommerce\netterms\enums\ReminderType::Overdue->messageKey(), [
				'recipientName' => 'Pat',
				'label' => 'Invoice ' . $reminderInvoice->number,
				'amountDue' => '$10.00',
				'dateDue' => 'today',
				'link' => null,
			]);
			$message->setTo($tag . '-nobody@net-terms.example');
			Craft::$app->getMailer()->send($message);

			return $message->getSubject() === 'Invoice ' . $reminderInvoice->number . ' is overdue' ? true : (string) $message->getSubject();
		});

		$reminderPayment = new Payment([
			'accountId' => $reminderAccount->id,
			'amount' => $invoices->getBalance($reminderInvoice),
			'method' => PaymentMethod::Check,
			'reference' => 'Check 3001',
			'dateReceived' => new DateTime(),
		]);
		$payments->recordPayment($reminderPayment, $fullAllocation($reminderInvoice));
		$paidHolder = makeUser('paidholder');
		$paidAccount = makeAccount($paidHolder, Amounts::toDecimal($unitPrice->multiply('20')));
		$payingUserId = $paidHolder->id;
		pay(makeOrder($paidHolder, $variant, 1, $gatewayId));
		$paidInvoice = expect($invoices->issueInvoice($paidAccount), Invoice::class);
		$payments->recordPayment(new Payment([
			'accountId' => $paidAccount->id,
			'amount' => $invoices->getBalance($paidInvoice),
			'method' => PaymentMethod::Check,
			'reference' => 'Check 3002',
			'dateReceived' => new DateTime(),
		]), $fullAllocation($paidInvoice));
		check('a paid invoice gets no reminder', function () use ($runOn, $paidInvoice, $sentToCount, $paidHolder): bool|string {
			$runOn((clone expect($paidInvoice->dateDue, DateTime::class))->modify('+1 day')->modify('+1 hour'));

			return $sentToCount((string) $paidHolder->email) === 0 ? true : 'sent';
		});

		$gateway->paymentType = TransactionRecord::TYPE_AUTHORIZE;
		$creditOrderHolder = makeUser('reminderorderholder');
		makeAccount($creditOrderHolder, Amounts::toDecimal($unitPrice->multiply('20')));
		$payingUserId = $creditOrderHolder->id;
		$creditOrder = makeOrder($creditOrderHolder, $variant, 1, $gatewayId);
		pay($creditOrder);
		$overdueDate = static function () use ($creditOrder): DateTime {
			$completed = expect(Order::find()->id($creditOrder->id)->one(), Order::class);

			return DateTime::createFromInterface(expect($completed->dateOrdered, DateTimeInterface::class))->modify('+31 days')->modify('+1 hour');
		};
		check('a reminder whose moment is older than the lookback is skipped', function () use ($runOn, $creditOrder, $sentToCount, $overdueDate): bool|string {
			$completed = expect(Order::find()->id($creditOrder->id)->one(), Order::class);
			$runOn($overdueDate()->modify('+5 days'));

			return $sentToCount((string) $completed->email) === 0 ? true : 'sent';
		});
		check('a reminder that fails to render is retried on the next run', function () use ($runOn, $creditOrder, $overdueDate): bool|string {
			$breakRender = static function (): void {
				throw new RuntimeException('Broken system message');
			};
			Event::on(craft\mail\Mailer::class, craft\mail\Mailer::EVENT_BEFORE_PREP, $breakRender);
			try {
				$counts = $runOn($overdueDate());
			} finally {
				Event::off(craft\mail\Mailer::class, craft\mail\Mailer::EVENT_BEFORE_PREP, $breakRender);
			}

			$rowCount = (new craft\db\Query())->from(Table::REMINDERS)->where([
				'orderId' => $creditOrder->id,
			])->count();

			return $counts['failed'] > 0 && (int) $rowCount === 0 ? true : json_encode($counts) . ', ' . $rowCount . ' rows';
		});
		check('in order billing the overdue reminder goes to the order’s email', function () use ($runOn, $creditOrder, $sentToCount, $overdueDate): bool|string {
			$completed = expect(Order::find()->id($creditOrder->id)->one(), Order::class);
			$runOn($overdueDate());

			return $sentToCount((string) $completed->email) === 1 ? true : $sentToCount((string) $completed->email) . ' sent';
		});
	} finally {
		Event::off(craft\mail\Mailer::class, craft\mail\Mailer::EVENT_AFTER_SEND, $sentHandler);
		Event::off(craft\mail\Mailer::class, craft\mail\Mailer::EVENT_BEFORE_SEND, $fixturesOnlyHandler);
		$gateway->paymentType = TransactionRecord::TYPE_PURCHASE;
		$reminderSettings->dueSoonReminderDays = null;
		$reminderSettings->overdueReminderDays = null;
		$payingUserId = null;
	}

	section('Switching billing');

	try {
		$switchHolder = makeUser('switchholder');
		$switchAccount = makeAccount($switchHolder, Amounts::toDecimal($unitPrice->multiply('20')));
		$switchBuyer = expect($accounts->getBuyer($switchAccount, (int) $switchHolder->id), Buyer::class);
		$payingUserId = $switchHolder->id;
		pay(makeOrder($switchHolder, $variant, 1, $gatewayId));
		$switchInvoice = expect($invoices->issueInvoice($switchAccount), Invoice::class);
		// Credit the account outside the invoice, so it owes nothing overall while the invoice stays unpaid
		$ledger->adjust($switchAccount, $switchBuyer, $invoices->getTotal($switchInvoice)->negative(), 'Credit');

		check('an unpaid invoice blocks a payment type change even when the account owes $0', function () use ($accounts, $switchAccount): bool|string {
			$isUnsettled = in_array($switchAccount->id, array_map(static fn (Account $unsettled): ?int => $unsettled->id, $accounts->getUnsettledAccounts()), true);

			return $accounts->getOwedByAccount($switchAccount)->isZero() && $isUnsettled ? true : 'owed ' . Amounts::toDecimal($accounts->getOwedByAccount($switchAccount)) . ', unsettled ' . json_encode($isUnsettled);
		});
		check('voiding the invoice settles the account', function () use ($accounts, $invoices, $switchAccount, $switchInvoice): bool|string {
			$invoices->voidInvoice($switchInvoice);
			$isUnsettled = in_array($switchAccount->id, array_map(static fn (Account $unsettled): ?int => $unsettled->id, $accounts->getUnsettledAccounts()), true);

			return $isUnsettled ? 'still unsettled' : true;
		});
	} finally {
		$payingUserId = null;
	}

	section('Deleting orders');

	try {
		$deleteHolder = makeUser('deleteholder');
		$deleteAccount = makeAccount($deleteHolder, Amounts::toDecimal($unitPrice->multiply('20')));
		$payingUserId = $deleteHolder->id;
		$elements = Craft::$app->getElements();
		$invoicedOrder = makeOrder($deleteHolder, $variant, 1, $gatewayId);
		pay($invoicedOrder);
		expect($invoices->issueInvoice($deleteAccount), Invoice::class);

		check('an order on an issued invoice can’t be deleted', function () use ($elements, $invoicedOrder): bool|string {
			$deleted = $elements->deleteElementById((int) $invoicedOrder->id, Order::class);

			return ! $deleted && Order::find()->id($invoicedOrder->id)->exists() ? true : 'deleted';
		});

		$owedWithoutLooseOrder = $accounts->getOwedByAccount($deleteAccount);
		$looseOrder = makeOrder($deleteHolder, $variant, 1, $gatewayId);
		pay($looseOrder);
		$owedWithOrder = $accounts->getOwedByAccount($deleteAccount);

		check('a trashed order stops counting toward what is owed', function () use ($elements, $looseOrder, $accounts, $deleteAccount, $owedWithoutLooseOrder): bool|string {
			$elements->deleteElementById((int) $looseOrder->id, Order::class);

			return same($accounts->getOwedByAccount($deleteAccount), Amounts::toDecimal($owedWithoutLooseOrder));
		});
		check('a trashed order blocks a payment type change', fn (): bool|string => $accounts->hasTrashedOrders($deleteAccount) ? true : 'not blocked');
		check('a trashed order isn’t invoiced', fn (): bool|string => $invoices->issueInvoice($deleteAccount) === null ? true : 'invoiced');
		check('restoring the order counts it again and the next invoice bills it', function () use ($elements, $looseOrder, $accounts, $deleteAccount, $owedWithOrder, $invoices): bool|string {
			$trashed = expect(Order::find()->id($looseOrder->id)->trashed()->one(), Order::class);
			$elements->restoreElement($trashed);
			$invoice = $invoices->issueInvoice($deleteAccount);

			if (! $invoice instanceof Invoice) {
				return 'not invoiced';
			}

			$voided = $invoices->voidInvoice($invoice);

			return $voided ? same($accounts->getOwedByAccount($deleteAccount), Amounts::toDecimal($owedWithOrder)) : 'not voided';
		});
		check('deleting the order for good removes its ledger entries', function () use ($elements, $looseOrder): bool|string {
			$entryIds = (new craft\db\Query())->select(['id'])->from(Table::ENTRIES)->where([
				'orderId' => $looseOrder->id,
			])->column();
			$elements->deleteElementById((int) $looseOrder->id, Order::class, null, true);
			$remaining = (new craft\db\Query())->from(Table::ENTRIES)->where([
				'id' => $entryIds,
			])->count();

			return $entryIds !== [] && (int) $remaining === 0 ? true : count($entryIds) . ' entries before, ' . $remaining . ' left';
		});
	} finally {
		$payingUserId = null;
	}

	section('Over the limit');

	$overHolder = makeUser('overholder');
	$overAccount = makeAccount($overHolder, '100.00');
	$overBuyer = expect($accounts->getBuyer($overAccount, (int) $overHolder->id), Buyer::class);
	$overBuyer->sublimit = money('50.00');
	$accounts->saveBuyer($overBuyer);
	// Adjust past both limits, since adjustments aren't checked against credit
	$ledger->adjust($overAccount, $overBuyer, money('130.00'), 'Raise past the limit');

	check('an account over its credit limit shows by how much', fn (): bool|string => same($accounts->getOverLimit($overAccount), '30.00'));
	check('a buyer over their sublimit shows by how much', fn (): bool|string => same($accounts->getOverSublimitByBuyerId($overAccount)[(int) $overBuyer->id] ?? null, '80.00'));
	check('an account within its limit shows nothing over', fn (): bool|string => $accounts->getOverLimit($account) === null ? true : 'over');
	check('a buyer over their sublimit can still be saved unchanged', fn (): bool|string => $accounts->saveBuyer($overBuyer) ? true : json_encode($overBuyer->getErrors()));
	check('lowering a sublimit below what is owed is still refused', function () use ($accounts, $overBuyer): bool|string {
		$overBuyer->sublimit = money('40.00');
		$saved = $accounts->saveBuyer($overBuyer);
		$overBuyer->sublimit = money('50.00');

		return $saved ? 'saved' : true;
	});

	section('Payment terms field');

	$termsField = new fostercommerce\netterms\fields\PaymentTerms([
		'handle' => 'paymentTerms',
	]);
	check('the field keeps whole days and reads blank as no value', function () use ($termsField): bool|string {
		$values = [$termsField->normalizeValue('15', null), $termsField->normalizeValue('', null), $termsField->normalizeValue(null, null)];

		return $values === [15, null, null] ? true : json_encode($values);
	});
	check('input that isn’t a whole number is kept as typed for validation to refuse', fn (): bool|string => $termsField->normalizeValue('7.5', null) === '7.5' ? true : json_encode($termsField->normalizeValue('7.5', null)));
	check('a value posted by someone who can’t edit orders is ignored', fn (): bool|string => $termsField->normalizeValueFromRequest('365', null) === null ? true : 'accepted');

	section('Locking and events');

	check('a write fails while the account lock is held', function () use ($ledger, $account, $bobBuyer): bool|string {
		$mutex = Craft::$app->getMutex();
		$mutex->acquire('net-terms:account:' . $account->id);

		try {
			$ledger->adjust($account, $bobBuyer, money('1.00'), 'Locked out');
		} catch (LedgerException) {
			return true;
		} finally {
			$mutex->release('net-terms:account:' . $account->id);
		}

		return 'wrote while locked';
	});

	check('entry events fire after commit and never for rolled-back entries', function () use ($ledger, $account, $bobBuyer): bool|string {
		$entryEvents = new ArrayObject();
		$handler = static function (EntryEvent $event) use ($entryEvents): void {
			$entryEvents->append($event->entry);
		};
		Event::on(Ledger::class, Ledger::EVENT_AFTER_ADD_ENTRY, $handler);

		try {
			$ledger->withAccountLock((int) $account->id, static function () use ($ledger, $account, $bobBuyer): void {
				$ledger->adjust($account, $bobBuyer, money('1.00'), 'Rolled back');

				throw new RuntimeException('Roll back');
			});
		} catch (RuntimeException) {
		}

		$afterRollback = $entryEvents->count();
		$ledger->adjust($account, $bobBuyer, money('1.00'), 'Committed');
		Event::off(Ledger::class, Ledger::EVENT_AFTER_ADD_ENTRY, $handler);

		return $afterRollback === 0 && $entryEvents->count() === 1 ? true : $afterRollback . ' after rollback, ' . $entryEvents->count() . ' in all';
	});
} finally {
	$elements = Craft::$app->getElements();

	// Delete the accounts first, since an invoice stops its orders being deleted and an account holder can't be deleted
	Db::delete(Table::ACCOUNTS, [
		'holderId' => array_map(static fn (User $createdUser): int => (int) $createdUser->id, $createdUsers),
	]);

	foreach ($createdOrders as $createdOrder) {
		$elements->deleteElementById((int) $createdOrder->id, Order::class, null, true);
	}

	foreach ($createdUsers as $createdUser) {
		$elements->deleteElement($createdUser, true);
	}

	echo "\n{$passed} passed, {$failed} failed\n";
}

exit($failed > 0 ? 1 : 0);
