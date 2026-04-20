<?php

declare(strict_types=1);

namespace TimeFrontiers\Database;

use TimeFrontiers\Helper\Pagination;
use TimeFrontiers\SQLDatabase;

/**
 * Query builder for Multiform.
 *
 * Similar to QueryBuilder but returns Multiform instances
 * instead of static entity classes.
 */
class MultiformQuery {

  // Pagination::offset() (computed getter) is aliased to avoid conflict with
  // this class's own offset(int $offset):self fluent setter.
  use Pagination {
    Pagination::offset as paginationOffset;
  }

  private SQLDatabase $_conn;
  private string $_database;
  private string $_table;
  private string $_primary_key;

  private array $_select = ['*'];
  private array $_where = [];
  private array $_order_by = [];
  private ?int $_limit = null;
  private ?int $_offset = null;

  public function __construct(
    SQLDatabase $conn,
    string $database,
    string $table,
    string $primary_key = 'id'
  ) {
    $this->_conn = $conn;
    $this->_database = $database;
    $this->_table = $table;
    $this->_primary_key = $primary_key;
  }

  /**
   * Set columns to select.
   */
  public function select(string|array $columns):self {
    $this->_select = \is_array($columns) ? $columns : \func_get_args();
    return $this;
  }

  /**
   * Add a WHERE condition.
   */
  public function where(string $column, mixed $operator, mixed $value = null):self {
    if ($value === null) {
      $value = $operator;
      $operator = '=';
    }

    $this->_where[] = [
      'column' => $column,
      'operator' => \strtoupper($operator),
      'value' => $value,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add an OR WHERE condition.
   */
  public function orWhere(string $column, mixed $operator, mixed $value = null):self {
    if ($value === null) {
      $value = $operator;
      $operator = '=';
    }

    $this->_where[] = [
      'column' => $column,
      'operator' => \strtoupper($operator),
      'value' => $value,
      'boolean' => 'OR',
    ];

    return $this;
  }

  /**
   * Add a WHERE IN condition.
   */
  public function whereIn(string $column, array $values):self {
    $this->_where[] = [
      'column' => $column,
      'operator' => 'IN',
      'value' => $values,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add a WHERE NOT IN condition.
   */
  public function whereNotIn(string $column, array $values):self {
    $this->_where[] = [
      'column' => $column,
      'operator' => 'NOT IN',
      'value' => $values,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add a WHERE NULL condition.
   */
  public function whereNull(string $column):self {
    $this->_where[] = [
      'column' => $column,
      'operator' => 'IS NULL',
      'value' => null,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add a WHERE NOT NULL condition.
   */
  public function whereNotNull(string $column):self {
    $this->_where[] = [
      'column' => $column,
      'operator' => 'IS NOT NULL',
      'value' => null,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add ORDER BY clause.
   */
  public function orderBy(string $column, string $direction = 'ASC'):self {
    $this->_order_by[] = [
      'column' => $column,
      'direction' => \strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC',
    ];

    return $this;
  }

  /**
   * Add ORDER BY DESC.
   */
  public function orderByDesc(string $column):self {
    return $this->orderBy($column, 'DESC');
  }

  /**
   * Set LIMIT.
   */
  public function limit(int $limit):self {
    $this->_limit = $limit;
    return $this;
  }

  /**
   * Set OFFSET.
   */
  public function offset(int $offset):self {
    $this->_offset = $offset;
    return $this;
  }

  /**
   * Shorthand for limit + offset.
   */
  public function take(int $limit, int $offset = 0):self {
    $this->_limit = $limit;
    $this->_offset = $offset;
    return $this;
  }

  /**
   * Execute and get all results.
   *
   * @return array<Multiform>
   */
  public function get():array {
    [$sql, $params] = $this->_buildSelect();

    $rows = $this->_conn->fetchAll($sql, $params);

    if (empty($rows)) {
      return [];
    }

    return $this->_hydrateMany($rows);
  }

  /**
   * Execute and get first result.
   */
  public function first():Multiform|false {
    $this->_limit = 1;
    $results = $this->get();

    return $results[0] ?? false;
  }

  /**
   * Get count of matching records.
   */
  public function count():int {
    $this->_select = ['COUNT(*) AS cnt'];
    [$sql, $params] = $this->_buildSelect();

    $row = $this->_conn->fetchOne($sql, $params);

    return (int) ($row['cnt'] ?? 0);
  }

  /**
   * Check if any records exist.
   */
  public function exists():bool {
    return $this->count() > 0;
  }

  /**
   * Execute a paginated query.
   *
   * Counts matching rows, applies LIMIT/OFFSET from the Pagination trait,
   * and returns hydrated results alongside pagination metadata.
   *
   * When $page or $per_page are null the values are read from the request
   * ($_GET first, then $_POST) using the supplied parameter key names,
   * mirroring the behaviour of Pagination::fromRequest().
   *
   * @param int|null $page        Page number (1-indexed). Null = read from request.
   * @param int|null $per_page    Items per page. Null = read from request.
   * @param string   $page_key    Request key for the page number (default 'page').
   * @param string   $per_page_key Request key for per-page (default 'per_page').
   *
   * @return array{data: array<Multiform>, meta: array} Paginated result set.
   *
   * @example
   * ```php
   * // Explicit page/per-page
   * $result = Multiform::from('mydb', 'users')
   *   ->query()
   *   ->where('status', 'active')
   *   ->paginate(page: 2, per_page: 25);
   *
   * foreach ($result['data'] as $user) { ... }
   * $meta = $result['meta'];  // current_page, total_pages, has_more, etc.
   *
   * // Read from $_GET automatically (?page=3&per_page=10)
   * $result = Multiform::from('mydb', 'orders')->query()->paginate();
   *
   * // API response
   * return ['data' => $result['data'], 'pagination' => $result['meta']];
   * ```
   */
  public function paginate(
    ?int $page = null,
    ?int $per_page = null,
    string $page_key = 'page',
    string $per_page_key = 'per_page'
  ):array {
    // Resolve page and per_page from request when not supplied explicitly
    $page     ??= (int)($_GET[$page_key]     ?? $_POST[$page_key]     ?? 1);
    $per_page ??= (int)($_GET[$per_page_key] ?? $_POST[$per_page_key] ?? 20);

    $this->setPage($page);
    $this->setPerPage($per_page);

    // Run COUNT before modifying limit/offset
    $total = $this->count();
    $this->setTotalCount($total);

    // Restore select and apply pagination limit/offset
    $this->_select  = ['*'];
    $this->_limit   = $this->perPage();
    $this->_offset  = $this->paginationOffset();

    return [
      'data' => $this->get(),
      'meta' => $this->paginationToArray(),
    ];
  }

  /**
   * Get raw rows (not hydrated).
   */
  public function getRaw():array {
    [$sql, $params] = $this->_buildSelect();
    return $this->_conn->fetchAll($sql, $params) ?: [];
  }

  /**
   * Get the generated SQL (for debugging).
   */
  public function toSql():array {
    return $this->_buildSelect();
  }

  // =========================================================================
  // Private Methods
  // =========================================================================

  private function _buildSelect():array {
    $params = [];

    // SELECT
    $columns = \implode(', ', \array_map(fn($c) => $c === '*' ? '*' : "`{$c}`", $this->_select));
    $sql = "SELECT {$columns} FROM `{$this->_database}`.`{$this->_table}`";

    // WHERE
    if (!empty($this->_where)) {
      $sql .= ' WHERE ';
      $conditions = [];

      foreach ($this->_where as $i => $clause) {
        $condition = '';

        if ($i > 0) {
          $condition .= $clause['boolean'] . ' ';
        }

        $column = "`{$clause['column']}`";

        switch ($clause['operator']) {
          case 'IN':
          case 'NOT IN':
            $placeholders = \implode(', ', \array_fill(0, \count($clause['value']), '?'));
            $condition .= "{$column} {$clause['operator']} ({$placeholders})";
            $params = \array_merge($params, $clause['value']);
            break;

          case 'IS NULL':
          case 'IS NOT NULL':
            $condition .= "{$column} {$clause['operator']}";
            break;

          default:
            $condition .= "{$column} {$clause['operator']} ?";
            $params[] = $clause['value'];
            break;
        }

        $conditions[] = $condition;
      }

      $sql .= \implode(' ', $conditions);
    }

    // ORDER BY
    if (!empty($this->_order_by)) {
      $orders = \array_map(
        fn($o) => "`{$o['column']}` {$o['direction']}",
        $this->_order_by
      );
      $sql .= ' ORDER BY ' . \implode(', ', $orders);
    }

    // LIMIT
    if ($this->_limit !== null) {
      $sql .= " LIMIT {$this->_limit}";

      if ($this->_offset !== null) {
        $sql .= " OFFSET {$this->_offset}";
      }
    }

    return [$sql, $params];
  }

  private function _hydrateMany(array $rows):array {
    $entities = [];

    foreach ($rows as $row) {
      $instance = new Multiform(
        $this->_database,
        $this->_table,
        $this->_primary_key,
        $this->_conn
      );

      foreach ($row as $key => $value) {
        if (\is_int($key)) continue;
        // Uses __set which handles _created, _updated, _author
        $instance->$key = $value;
      }

      $entities[] = $instance;
    }

    return $entities;
  }
}
