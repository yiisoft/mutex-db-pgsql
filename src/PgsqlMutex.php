<?php

declare(strict_types=1);

namespace Yiisoft\Mutex;

/**
 * PgsqlMutex implements mutex "lock" mechanism via PgSQL locks.
 *
 * @see Mutex
 */
class PgsqlMutex extends Mutex
{
    use RetryAcquireTrait;

    /**
     * @var \PDO
     */
    protected $connection;

    public function __construct(\PDO $connection, bool $autoRelease = true)
    {
        $this->connection = $connection;
        $driverName = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driverName !== 'pgsql') {
            throw new \InvalidArgumentException(
                'Connection must be configured to use PgSQL database. Got ' . $driverName . '.'
            );
        }

        parent::__construct($autoRelease);
    }

    /**
     * Converts a string into two 16 bit integer keys using the SHA1 hash function.
     *
     * @param string $name
     *
     * @return array contains two 16 bit integer keys
     */
    private function getKeysFromName(string $name): array
    {
        return array_values(unpack('n2', sha1($name, true)));
    }

    /**
     * Acquires lock by given name.
     *
     * @param string $name    of the lock to be acquired.
     * @param int    $timeout time (in seconds) to wait for lock to become released.
     *
     * @return bool acquiring result.
     *
     * @see http://www.postgresql.org/docs/9.0/static/functions-admin.html
     */
    protected function acquireLock(string $name, int $timeout = 0): bool
    {
        [$key1, $key2] = $this->getKeysFromName($name);

        return $this->retryAcquire($timeout, function () use ($key1, $key2) {
            $statement = $this->connection->prepare('SELECT pg_try_advisory_lock(:key1, :key2)');
            $statement->bindValue(':key1', $key1);
            $statement->bindValue(':key2', $key2);
            $statement->execute();

            return $statement->fetchColumn();
        });
    }

    /**
     * Releases lock by given name.
     *
     * @param string $name of the lock to be released.
     *
     * @return bool release result.
     *
     * @see http://www.postgresql.org/docs/9.0/static/functions-admin.html
     */
    protected function releaseLock(string $name): bool
    {
        [$key1, $key2] = $this->getKeysFromName($name);

        $statement = $this->connection->prepare('SELECT pg_advisory_unlock(:key1, :key2)');
        $statement->bindValue(':key1', $key1);
        $statement->bindValue(':key2', $key2);
        $statement->execute();

        return $statement->fetchColumn();
    }
}
