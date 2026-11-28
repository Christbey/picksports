<?php

namespace App\Services\Sports\Elo;

use App\Exceptions\EloAdmissionRefused;
use Illuminate\Database\Connection;
use JsonSerializable;
use LogicException;
use PDO;
use WeakReference;

/** Process-local read boundary evidence; never a serializable source or authorization receipt. */
final class EloSqlClockToken implements JsonSerializable
{
    private readonly WeakReference $connection;

    private readonly WeakReference $write;

    private readonly WeakReference $read;

    public function __construct(Connection $connection, PDO $write, PDO $read, private readonly array $policy, private readonly string $targetDigest, private readonly bool $checksRead = true)
    {
        $this->connection = WeakReference::create($connection);
        $this->write = WeakReference::create($write);
        $this->read = WeakReference::create($read);
    }

    public function portablePolicy(): array
    {
        return $this->policy;
    }

    public function assertTarget(string $digest): void
    {
        if (! hash_equals($this->targetDigest, $digest)) {
            throw new EloAdmissionRefused('sql_clock_target_changed_requires_fresh_admission', [], []);
        }
    }

    public function checksRead(): bool
    {
        return $this->checksRead;
    }

    public function assertHandles(Connection $connection): void
    {
        if ($this->connection->get() !== $connection || $this->write->get() === null
            || $connection->getRawPdo() !== $this->write->get()
            || ($this->checksRead && ($this->read->get() === null || $connection->getReadPdo() !== $this->read->get()))) {
            throw new EloAdmissionRefused('sql_clock_connection_changed_requires_fresh_admission', [], []);
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('SQL clock admission tokens cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('SQL clock admission tokens cannot be restored.');
    }

    public function jsonSerialize(): mixed
    {
        throw new LogicException('SQL clock admission tokens cannot be encoded.');
    }

    public function __debugInfo(): array
    {
        return ['transient' => true, 'policy' => $this->policy];
    }
}
