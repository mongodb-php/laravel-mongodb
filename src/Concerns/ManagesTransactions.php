<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Concerns;

use Closure;
use MongoDB\Client;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Session;
use Throwable;

use function MongoDB\with_transaction;

/**
 * @internal
 *
 * @see https://docs.mongodb.com/manual/core/transactions/
 */
trait ManagesTransactions
{
    protected ?Session $session = null;

    protected $transactions = 0;

    abstract public function getClient(): ?Client;

    public function getSession(): ?Session
    {
        return $this->session;
    }

    private function getSessionOrCreate(): Session
    {
        if ($this->session === null) {
            $this->session = $this->getClient()->startSession();
        }

        return $this->session;
    }

    private function getSessionOrThrow(): Session
    {
        $session = $this->getSession();

        if ($session === null) {
            throw new RuntimeException('There is no active session.');
        }

        return $session;
    }

    /**
     * Starts a transaction on the active session. An active session will be created if none exists.
     */
    public function beginTransaction(array $options = []): void
    {
        $this->getSessionOrCreate()->startTransaction($options);
        $this->transactions = 1;
    }

    /**
     * Commit transaction in this session.
     */
    public function commit(): void
    {
        $this->getSessionOrThrow()->commitTransaction();
        $this->transactions = 0;
    }

    /**
     * Abort transaction in this session.
     */
    public function rollBack($toLevel = null): void
    {
        $this->getSessionOrThrow()->abortTransaction();
        $this->transactions = 0;
    }

    /**
     * Static transaction function realize the with_transaction functionality provided by MongoDB.
     *
     * @param  int $attempts
     */
    public function transaction(Closure $callback, $attempts = 1, array $options = []): mixed
    {
        $attemptsLeft   = $attempts;
        $callbackResult = null;
        $throwable      = null;

        $callbackFunction = function (Session $session) use ($callback, &$attemptsLeft, &$callbackResult, &$throwable) {
            $attemptsLeft--;

            if ($attemptsLeft < 0) {
                $session->abortTransaction();

                return;
            }

            // Catch, store, and re-throw any exception thrown during execution
            // of the callable. The last exception is re-thrown if the transaction
            // was aborted because the number of callback attempts has been exceeded.
            try {
                $callbackResult = $callback($this);
            } catch (Throwable $throwable) {
                throw $throwable;
            }
        };

        with_transaction($this->getSessionOrCreate(), $callbackFunction, $options);

        if ($attemptsLeft < 0 && $throwable) {
            throw $throwable;
        }

        return $callbackResult;
    }
}
