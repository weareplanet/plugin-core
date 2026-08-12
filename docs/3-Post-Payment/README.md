# Chapter 3: Post-Payment

What happens once a transaction is authorized: settling the money, and producing the paperwork the merchant and customer expect.

By the end of this chapter you can capture or void an authorization, refund all or part of it without floating-point drift, and fetch the official PDFs the WeArePlanet Portal generates.

## Contents

### Finalizing the Order

- **[Completion](Completion.md)** — Finalizing an authorized transaction, by capturing the funds or voiding it, including line-item-level partial captures.
- **[Invoice](Invoice.md)** — Read-only access to the invoice a capture produces, and why it lives under Completion rather than on its own.

### Reversals

- **[Refund](Refund.md)** — Full refunds, partial refunds, and refunding specific line items without introducing rounding errors.

### Documents

- **[Document](Document.md)** — Retrieving the official PDFs the WeArePlanet Portal generates: invoices, packing slips, and credit notes.

## Examples

Runnable scripts for this chapter live in [`examples/3-Post-Payment/`](../examples/3-Post-Payment/). They act on an existing transaction, so run the [Chapter 2](../2-Checkout-Flow/README.md) checkout scripts first to create one.

---

[← Chapter 2: Checkout Flow](../2-Checkout-Flow/README.md) · [Documentation index](../README.md) · [Chapter 4: Background Tasks →](../4-Background-Tasks/README.md)
