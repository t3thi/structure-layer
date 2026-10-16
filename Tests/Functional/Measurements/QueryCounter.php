<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Measurements;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use TYPO3\CMS\Core\Database\Middleware\UsableForConnectionInterface;

/**
 * Phase 6 measurements (SCALE-1): counts SQL statements. A statement counts as "extension" if
 * code of EXT:structure_layer (not its tests) is on the call stack, i.e. it was issued by a hook,
 * listener or service of the extension, or by Core on its behalf (e.g. a nested DataHandler run).
 */
final class QueryCounter implements UsableForConnectionInterface
{
    /** @var list<array{sql: string, extension: bool}> */
    public static array $statements = [];

    public static bool $enabled = false;

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): ConnectionInterface
            {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        QueryCounter::record($sql);
                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        QueryCounter::record($sql);
                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        QueryCounter::record($sql);
                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    /**
     * @param array<string, mixed> $connectionParams
     */
    public function canBeUsedForConnection(string $identifier, array $connectionParams): bool
    {
        return true;
    }

    public static function record(string $sql): void
    {
        if (!self::$enabled) {
            return;
        }
        $extension = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            if (str_starts_with($class, 'T3thi\\StructureLayer\\') && !str_starts_with($class, 'T3thi\\StructureLayer\\Tests\\')) {
                $extension = true;
                break;
            }
        }
        self::$statements[] = ['sql' => $sql, 'extension' => $extension];
    }

    /**
     * @return array{total: int, extension: int, writes: int, extensionWrites: int}
     */
    public static function measure(callable $operation): array
    {
        self::$statements = [];
        self::$enabled = true;
        try {
            $operation();
        } finally {
            self::$enabled = false;
        }
        $isWrite = static fn(array $statement): bool => (bool)preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $statement['sql']);
        $extension = array_filter(self::$statements, static fn(array $statement): bool => $statement['extension']);
        return [
            'total' => count(self::$statements),
            'extension' => count($extension),
            'writes' => count(array_filter(self::$statements, $isWrite)),
            'extensionWrites' => count(array_filter($extension, $isWrite)),
        ];
    }
}
