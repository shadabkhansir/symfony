<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Bridge\Doctrine\Transport;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Synchronizer\SchemaSynchronizer;
use Doctrine\DBAL\Schema\Synchronizer\SingleDatabaseSynchronizer;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal since Symfony 5.1
 *
 * @author Vincent Touzet <vincent.touzet@gmail.com>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class Connection implements ResetInterface
{
    protected const DEFAULT_OPTIONS = [
        'table_name' => 'messenger_messages',
        'queue_name' => 'default',
        'redeliver_timeout' => 3600,
        'auto_setup' => true,
    ];

    /**
     * Configuration of the connection.
     *
     * Available options:
     *
     * * table_name: name of the table
     * * connection: name of the Doctrine's entity manager
     * * queue_name: name of the queue
     * * redeliver_timeout: Timeout before redeliver messages still in handling state (i.e: delivered_at is not null and message is still in table). Default: 3600
     * * auto_setup: Whether the table should be created automatically during send / get. Default: true
     */
    protected $configuration = [];
    protected $driverConnection;
    protected $queueEmptiedAt;
    private $schemaSynchronizer;
    private $autoSetup;

    private static $useDeprecatedConstants;

    public function __construct(array $configuration, DBALConnection $driverConnection, SchemaSynchronizer $schemaSynchronizer = null)
    {
        $this->configuration = array_replace_recursive(static::DEFAULT_OPTIONS, $configuration);
        $this->driverConnection = $driverConnection;
        $this->schemaSynchronizer = $schemaSynchronizer ?? new SingleDatabaseSynchronizer($this->driverConnection);
        $this->autoSetup = $this->configuration['auto_setup'];

        if (null === self::$useDeprecatedConstants) {
            self::$useDeprecatedConstants = !class_exists(Types::class);
        }
    }

    public function reset()
    {
        $this->queueEmptiedAt = null;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public static function buildConfiguration(string $dsn, array $options = []): array
    {
        if (false === $components = parse_url($dsn)) {
            throw new InvalidArgumentException(sprintf('The given Doctrine Messenger DSN "%s" is invalid.', $dsn));
        }

        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        $configuration = ['connection' => $components['host']];
        $configuration += $options + $query + static::DEFAULT_OPTIONS;

        $configuration['auto_setup'] = filter_var($configuration['auto_setup'], FILTER_VALIDATE_BOOLEAN);

        // check for extra keys in options
        $optionsExtraKeys = array_diff(array_keys($options), array_keys(static::DEFAULT_OPTIONS));
        if (0 < \count($optionsExtraKeys)) {
            throw new InvalidArgumentException(sprintf('Unknown option found: [%s]. Allowed options are [%s].', implode(', ', $optionsExtraKeys), implode(', ', array_keys(static::DEFAULT_OPTIONS))));
        }

        // check for extra keys in options
        $queryExtraKeys = array_diff(array_keys($query), array_keys(static::DEFAULT_OPTIONS));
        if (0 < \count($queryExtraKeys)) {
            throw new InvalidArgumentException(sprintf('Unknown option found in DSN: [%s]. Allowed options are [%s].', implode(', ', $queryExtraKeys), implode(', ', array_keys(static::DEFAULT_OPTIONS))));
        }

        return $configuration;
    }

    /**
     * @param int $delay The delay in milliseconds
     *
     * @return string The inserted id
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function send(string $body, array $headers, int $delay = 0): string
    {
        $now = new \DateTime();
        $availableAt = (clone $now)->modify(sprintf('+%d seconds', $delay / 1000));

        $queryBuilder = $this->driverConnection->createQueryBuilder()
            ->insert($this->configuration['table_name'])
            ->values([
                'body' => '?',
                'headers' => '?',
                'queue_name' => '?',
                'created_at' => '?',
                'available_at' => '?',
            ]);

        $this->executeQuery($queryBuilder->getSQL(), [
            $body,
            json_encode($headers),
            $this->configuration['queue_name'],
            $now,
            $availableAt,
        ], self::$useDeprecatedConstants ? [
            null,
            null,
            null,
            Type::DATETIME,
            Type::DATETIME,
        ] : [
            null,
            null,
            null,
            Types::DATETIME_MUTABLE,
            Types::DATETIME_MUTABLE,
        ]);

        return $this->driverConnection->lastInsertId();
    }

    public function get(): ?array
    {
        get:
        $this->driverConnection->beginTransaction();
        try {
            $query = $this->createAvailableMessagesQueryBuilder()
                ->orderBy('available_at', 'ASC')
                ->setMaxResults(1);

            // use SELECT ... FOR UPDATE to lock table
            $doctrineEnvelope = $this->executeQuery(
                $query->getSQL().' '.$this->driverConnection->getDatabasePlatform()->getWriteLockSQL(),
                $query->getParameters(),
                $query->getParameterTypes()
            )->fetch();

            if (false === $doctrineEnvelope) {
                $this->driverConnection->commit();
                $this->queueEmptiedAt = microtime(true) * 1000;

                return null;
            }
            // Postgres can "group" notifications having the same channel and payload
            // We need to be sure to empty the queue before blocking again
            $this->queueEmptiedAt = null;

            $doctrineEnvelope = $this->decodeEnvelopeHeaders($doctrineEnvelope);

            $queryBuilder = $this->driverConnection->createQueryBuilder()
                ->update($this->configuration['table_name'])
                ->set('delivered_at', '?')
                ->where('id = ?');
            $now = new \DateTime();
            $this->executeQuery($queryBuilder->getSQL(), [
                $now,
                $doctrineEnvelope['id'],
            ], [
                self::$useDeprecatedConstants ? Type::DATETIME : Types::DATETIME_MUTABLE,
            ]);

            $this->driverConnection->commit();

            return $doctrineEnvelope;
        } catch (\Throwable $e) {
            $this->driverConnection->rollBack();

            if ($this->autoSetup && $e instanceof TableNotFoundException) {
                $this->setup();
                goto get;
            }

            throw $e;
        }
    }

    public function ack(string $id): bool
    {
        try {
            return $this->driverConnection->delete($this->configuration['table_name'], ['id' => $id]) > 0;
        } catch (DBALException $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function reject(string $id): bool
    {
        try {
            return $this->driverConnection->delete($this->configuration['table_name'], ['id' => $id]) > 0;
        } catch (DBALException $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function setup(): void
    {
        $configuration = $this->driverConnection->getConfiguration();
        // Since Doctrine 2.9 the getFilterSchemaAssetsExpression is deprecated
        $hasFilterCallback = method_exists($configuration, 'getSchemaAssetsFilter');

        if ($hasFilterCallback) {
            $assetFilter = $this->driverConnection->getConfiguration()->getSchemaAssetsFilter();
            $this->driverConnection->getConfiguration()->setSchemaAssetsFilter(null);
        } else {
            $assetFilter = $this->driverConnection->getConfiguration()->getFilterSchemaAssetsExpression();
            $this->driverConnection->getConfiguration()->setFilterSchemaAssetsExpression(null);
        }

        $schema = new Schema([], [], $this->driverConnection->getSchemaManager()->createSchemaConfig());
        $migration = new Migration($this->configuration['table_name']);
        $migration->up($schema, $this->driverConnection);

        $this->schemaSynchronizer->updateSchema($schema, true);

        if ($hasFilterCallback) {
            $this->driverConnection->getConfiguration()->setSchemaAssetsFilter($assetFilter);
        } else {
            $this->driverConnection->getConfiguration()->setFilterSchemaAssetsExpression($assetFilter);
        }

        $this->autoSetup = false;
    }

    public function getMessageCount(): int
    {
        $queryBuilder = $this->createAvailableMessagesQueryBuilder()
            ->select('COUNT(m.id) as message_count')
            ->setMaxResults(1);

        return $this->executeQuery($queryBuilder->getSQL(), $queryBuilder->getParameters(), $queryBuilder->getParameterTypes())->fetchColumn();
    }

    public function findAll(int $limit = null): array
    {
        $queryBuilder = $this->createAvailableMessagesQueryBuilder();
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }

        $data = $this->executeQuery($queryBuilder->getSQL(), $queryBuilder->getParameters(), $queryBuilder->getParameterTypes())->fetchAll();

        return array_map(function ($doctrineEnvelope) {
            return $this->decodeEnvelopeHeaders($doctrineEnvelope);
        }, $data);
    }

    public function find($id): ?array
    {
        $queryBuilder = $this->createQueryBuilder()
            ->where('m.id = ?');

        $data = $this->executeQuery($queryBuilder->getSQL(), [
            $id,
        ])->fetch();

        return false === $data ? null : $this->decodeEnvelopeHeaders($data);
    }

    private function createAvailableMessagesQueryBuilder(): QueryBuilder
    {
        $now = new \DateTime();
        $redeliverLimit = (clone $now)->modify(sprintf('-%d seconds', $this->configuration['redeliver_timeout']));

        return $this->createQueryBuilder()
            ->where('m.delivered_at is null OR m.delivered_at < ?')
            ->andWhere('m.available_at <= ?')
            ->andWhere('m.queue_name = ?')
            ->setParameters([
                $redeliverLimit,
                $now,
                $this->configuration['queue_name'],
            ], self::$useDeprecatedConstants ? [
                Type::DATETIME,
                Type::DATETIME,
            ] : [
                Types::DATETIME_MUTABLE,
                Types::DATETIME_MUTABLE,
            ]);
    }

    private function createQueryBuilder(): QueryBuilder
    {
        return $this->driverConnection->createQueryBuilder()
            ->select('m.*')
            ->from($this->configuration['table_name'], 'm');
    }

    private function executeQuery(string $sql, array $parameters = [], array $types = []): ResultStatement
    {
        try {
            $stmt = $this->driverConnection->executeQuery($sql, $parameters, $types);
        } catch (TableNotFoundException $e) {
            if ($this->driverConnection->isTransactionActive()) {
                throw $e;
            }

            // create table
            if ($this->autoSetup) {
                $this->setup();
            }
            $stmt = $this->driverConnection->executeQuery($sql, $parameters, $types);
        }

        return $stmt;
    }

    private function decodeEnvelopeHeaders(array $doctrineEnvelope): array
    {
        $doctrineEnvelope['headers'] = json_decode($doctrineEnvelope['headers'], true);

        return $doctrineEnvelope;
    }
}
class_alias(Connection::class, \Symfony\Component\Messenger\Transport\Doctrine\Connection::class);
