<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but breaks a business rule, such as removing
 * the last Super Administrator. The API returns it with its own error code.
 */
class BusinessRuleException extends RuntimeException
{
    /** @var string */
    protected $errorCode;

    /** @var int */
    protected $status;

    public function __construct(string $errorCode, string $message, int $status = 422)
    {
        parent::__construct($message);

        $this->errorCode = $errorCode;
        $this->status = $status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
