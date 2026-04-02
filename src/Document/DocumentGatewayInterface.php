<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Document;

/**
 * Interface for retrieving rendered documents.
 */
interface DocumentGatewayInterface
{
    /**
     * Retrieves the rendered invoice document.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return RenderedDocument The rendered invoice.
     * @throws \Exception If the document cannot be retrieved.
     */
    public function getInvoice(int $spaceId, int $transactionId): RenderedDocument;

    /**
     * Retrieves the rendered packing slip.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return RenderedDocument The rendered packing slip.
     * @throws \Exception If the document cannot be retrieved.
     */
    public function getPackingSlip(int $spaceId, int $transactionId): RenderedDocument;

    /**
     * Retrieves the rendered refund credit note.
     *
     * @param int $spaceId The space ID.
     * @param int $refundId The refund ID.
     * @return RenderedDocument The rendered refund credit note.
     * @throws \Exception If the document cannot be retrieved.
     */
    public function getRefundCreditNote(int $spaceId, int $refundId): RenderedDocument;
}
