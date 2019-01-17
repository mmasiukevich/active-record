<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation) active record component
 * The simplest implementation of the "ActiveRecord" pattern
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\ActiveRecord;

use function Amp\call;
use Amp\Promise;
use Desperado\ServiceBus\Cache\CacheAdapter;
use Desperado\ServiceBus\Cache\InMemory\InMemoryCacheAdapter;
use function Desperado\ServiceBus\Storage\equalsCriteria;
use function Desperado\ServiceBus\Storage\fetchAll;
use Desperado\ServiceBus\Storage\QueryExecutor;
use function Desperado\ServiceBus\Storage\selectQuery;

/**
 * @internal
 */
final class MetadataLoader
{
    /**
     * @var QueryExecutor
     */
    private $queryExecutor;

    /**
     * @var CacheAdapter
     */
    private $cacheAdapter;

    /**
     * @param QueryExecutor     $queryExecutor
     * @param CacheAdapter|null $cacheAdapter
     */
    public function __construct(QueryExecutor $queryExecutor, ?CacheAdapter $cacheAdapter = null)
    {
        $this->queryExecutor = $queryExecutor;
        $this->cacheAdapter  = $cacheAdapter ?? new InMemoryCacheAdapter();
    }

    /**
     * Load table columns
     *
     * [
     *    'id' => 'uuid'',
     *    ...
     * ]
     *
     * @psalm-return \Amp\Promise
     *
     * @param string $table
     *
     * @return Promise<array<string, string>>
     *
     * @throws \Desperado\ServiceBus\Storage\Exceptions\StorageInteractingFailed Basic type of interaction errors
     * @throws \Desperado\ServiceBus\Storage\Exceptions\ConnectionFailed Could not connect to database
     */
    public function columns(string $table): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(string $table): \Generator
            {
                $cacheKey = \sha1($table . '_metadata_columns');

                /** @var array|null $columns */
                $columns = yield $this->cacheAdapter->get($cacheKey);

                if(null !== $columns)
                {
                    return $columns;
                }

                /** @var array<string, string>|null $columns */
                $columns = yield from $this->loadColumns($table);

                yield $this->cacheAdapter->save($cacheKey, $columns);

                return $columns;
            },
            $table
        );
    }

    /**
     * @psalm-return \Generator
     *
     * @param string $table
     *
     * @return \Generator<array<string, string>>
     *
     * @throws \Desperado\ServiceBus\Storage\Exceptions\StorageInteractingFailed Basic type of interaction errors
     * @throws \Desperado\ServiceBus\Storage\Exceptions\ConnectionFailed Could not connect to database
     */
    private function loadColumns(string $table): \Generator
    {
        /** @var array<string, string> $result */
        $result = [];

        $queryBuilder = selectQuery('information_schema.columns', 'column_name', 'data_type')
            ->where(equalsCriteria('table_name', $table));

        $compiledQuery = $queryBuilder->compile();

        /** @var \Desperado\ServiceBus\Storage\ResultSet $resultSet */
        $resultSet = yield $this->queryExecutor->execute($compiledQuery->sql(), $compiledQuery->params());

        /** @var array<int, array<string, string>> $columns */
        $columns = yield fetchAll($resultSet);

        /** @var array{column_name:string, data_type:string} $columnData */
        foreach($columns as $columnData)
        {
            $result[$columnData['column_name']] = $columnData['data_type'];
        }

        return $result;
    }
}
