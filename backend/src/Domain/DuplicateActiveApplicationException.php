<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

use RuntimeException;

/**
 * У клиента уже есть активная заявка (new/decided) — вторую заводить нельзя.
 */
final class DuplicateActiveApplicationException extends RuntimeException
{
    public function __construct(private readonly string $applicantRef)
    {
        parent::__construct('У клиента уже есть активная заявка');
    }

    public function applicantRef(): string
    {
        return $this->applicantRef;
    }
}