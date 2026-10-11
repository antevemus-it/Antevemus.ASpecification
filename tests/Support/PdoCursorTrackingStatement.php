<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use PDO;
use PDOStatement;

/**
 * Statement class of PdoCursorTrackingPdo (forward 032, D3 tests): reports when its result set opens
 * and closes.
 *
 * @version 1.14.0
 */
class PdoCursorTrackingStatement extends PDOStatement
{
    protected function __construct(private readonly PdoCursorTrackingPdo $tracker)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->tracker->beforeExecute(spl_object_id($this), $this->queryString);
        $result = parent::execute($params);
        if ($this->columnCount() > 0) {
            $this->tracker->opened(spl_object_id($this), $this->queryString);
        }

        return $result;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if ($row === false) {
            $this->tracker->closed(spl_object_id($this));
        }

        return $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $this->tracker->closed(spl_object_id($this));

        return $rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $value = parent::fetchColumn($column);
        if ($value === false) {
            $this->tracker->closed(spl_object_id($this));
        }

        return $value;
    }

    public function closeCursor(): bool
    {
        $this->tracker->closed(spl_object_id($this));

        return parent::closeCursor();
    }

    public function __destruct()
    {
        $this->tracker->closed(spl_object_id($this));
    }
}
