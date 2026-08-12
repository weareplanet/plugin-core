<?php

namespace WeArePlanet\Example;

/**
 * Error Handling Example
 *
 * Demonstrates the two cross-cutting patterns every other chapter relies on:
 *
 * - State capability predicates: asking what a state *means* instead of comparing
 *   it against a hardcoded list of cases.
 * - isRetryable() on a domain exception: telling a transient failure worth
 *   retrying apart from a terminal one worth reporting.
 *
 * Both are pure domain logic, so this script makes no API call. It needs no
 * credentials, no Space ID, and no transaction — transactions are created in
 * Chapter 2, and this chapter comes first. Run it before anything is configured.
 *
 * USAGE:
 * php error_handling.php
 */

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Refund\Exception\RefundException;
use WeArePlanet\PluginCore\Refund\State as RefundState;
use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;

// 📖 Concept documentation: See docs/1-Getting-Started/ErrorHandling.md

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Part 1: state capability predicates
// ---------------------------------------------------------------------------
//
// Every State enum in PluginCore answers questions about itself. Prefer these
// over `$state === X || $state === Y`, so the code stays correct when a domain
// later gains a new state.

echo "== State predicates ==" . PHP_EOL;

foreach (RefundState::cases() as $state) {
    printf(
        "  Refund %-14s pending=%-5s terminal=%s" . PHP_EOL,
        $state->value,
        $state->isPending() ? 'yes' : 'no',
        $state->isTerminal() ? 'yes' : 'no',
    );
}

echo PHP_EOL;

// Transaction states additionally answer questions specific to their domain.
foreach ([TransactionState::AUTHORIZED, TransactionState::FULFILL, TransactionState::FAILED] as $state) {
    printf(
        "  Transaction %-12s paidLike=%-5s invoiceDownload=%s" . PHP_EOL,
        $state->value,
        $state->isPaidLike() ? 'yes' : 'no',
        $state->isInvoiceDownloadAllowed() ? 'yes' : 'no',
    );
}

// A worked example: this is the shape the check takes in a real plugin.
$state = TransactionState::AUTHORIZED;
if ($state->isPaidLike()) {
    echo PHP_EOL . "  => money is secured, safe to fulfill the order" . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Part 2: isRetryable() on a domain exception
// ---------------------------------------------------------------------------
//
// The *gateway* decides retryability when it wraps a failure: it flags the causes
// it can identify as transient — a connection error, or a version conflict where
// another process updated the record concurrently. Everything else stays terminal,
// which is the safe default.
//
// The two exceptions below are the two shapes a gateway hands you. They are built
// here directly so both branches can be shown without depending on the network
// being down; in your plugin they arrive from a `catch`.

echo PHP_EOL . "== Retryable vs terminal failures ==" . PHP_EOL;

$terminal = new RefundException(
    'Refund rejected: amount exceeds the refundable balance [spaceId=42, transactionId=1234]',
    new LocalizedString('The refund amount is higher than the remaining balance.'),
);

// What a gateway does after catching a ConnectionException.
$transient = (new RefundException(
    'Refund failed: could not reach the API [spaceId=42, transactionId=1234]',
    new LocalizedString('The payment service is temporarily unreachable.'),
))->withRetryable(true);

foreach ([$terminal, $transient] as $exception) {
    describe($exception);
}

/**
 * The branch a plugin actually writes around a domain exception.
 */
function describe(AbstractDomainException $exception): void
{
    echo PHP_EOL . '  ' . $exception::class . PHP_EOL;
    // Two messages: one for the log, one for the shopper.
    echo '    technical: ' . $exception->getMessage() . PHP_EOL;
    echo '    localized: ' . $exception->getLocalizedMessage()->localize('en-US') . PHP_EOL;
    echo '    retryable: ' . ($exception->isRetryable() ? 'yes' : 'no') . PHP_EOL;

    if ($exception->isRetryable()) {
        echo '    => queue a background job and try the same request again' . PHP_EOL;
    } else {
        echo '    => report it to the merchant; retrying will not help' . PHP_EOL;
    }
}
