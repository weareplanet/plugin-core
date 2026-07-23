<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Domain object representing a Transaction Comment.
 */
class TransactionComment
{
    use JsonStringableTrait;

    /**
     * @var string The comment content.
     */
    public string $content;

    /**
     * @var \DateTimeImmutable|null The creation date.
     */
    public ?\DateTimeImmutable $createdOn = null;

    /**
     * @var int The comment ID.
     */
    public int $id;
}
