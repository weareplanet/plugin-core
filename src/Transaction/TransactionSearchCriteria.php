<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Data Transfer Object for transaction search criteria.
 */
class TransactionSearchCriteria
{
    use JsonStringableTrait;

    /**
     * @param int|null $limit The maximum number of results to return.
     * @param string|null $sortField The field to sort by.
     * @param string|null $sortOrder The sort order ('ASC' or 'DESC').
     * @param array<string, string|int|float|bool|null> $filters Key-value pairs for filtering (e.g., ['state' => 'FULFILLED']).
     */
    public function __construct(
        public ?int $limit = null,
        public ?string $sortField = 'id',
        public ?string $sortOrder = 'DESC',
        /** @var array<string, string|int|float|bool|null> */
        public array $filters = [],
    ) {
    }
}
