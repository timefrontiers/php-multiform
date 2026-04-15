<?php

declare(strict_types=1);

namespace TimeFrontiers\Database;

use TimeFrontiers\Helper\DatabaseObject;
use TimeFrontiers\Helper\HasErrors;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Database\Schema\TableSchema;

/**
 * Dynamic table entity class.
 *
 * Unlike fixed entities that use DatabaseObject trait with static properties,
 * Multiform allows runtime table name resolution. Useful for:
 *
 * - Multi-tenant databases with per-tenant tables
 * - Sharded tables (users_1, users_2, etc.)
 * - Generic CRUD operations on arbitrary tables
 * - Admin panels and data browsers
 *
 * Usage:
 *   // Basic usage
 *   $form = new Multiform('mydb', 'users');
 *   $form->name = 'John';
 *   $form->save();
 *
 *   // Find by ID
 *   $user = Multiform::from('mydb', 'users')->findById(123);
 *
 *   // Query builder
 *   $users = Multiform::from('mydb', 'users')
 *     ->query()
 *     ->where('status', 'active')
 *     ->get();
 */
class Multiform {

  use HasErrors;

  // Instance configuration (overrides static)
  protected string $_db_name;
  protected string $_table_name;
  protected string $_primary_key;

  // Static defaults (used by trait methods that expect static)
  protected static string $__db_name = '';
  protected static string $__table_name = '';
  protected static string $__primary_key = 'id';
  protected static array $__db_fields = [];

  // Connection
  protected ?SQLDatabase $_conn = null;
  private static ?SQLDatabase $_static_conn = null;

  // Schema cache (keyed by db.table)
  private static array $_schemas = [];

  // Data storage
  protected array $_data = [];
  protected array $_original = [];

  // Timestamps
  protected ?string $_created = null;
  protected ?string $_updated = null;
  protected ?string $_author = null;

  // Fields that can be empty
  public array $empty_props = [];

  // =========================================================================
  // Constructor & Factory
  // =========================================================================

  /**
   * Create a new Multiform instance.
   *
   * @param string $database Database name
   * @param string $table Table name
   * @param string $primary_key Primary key column (default: 'id')
   * @param SQLDatabase|null $conn Optional connection
   */
  public function __construct(
    string $database,
    string $table,
    string $primary_key = 'id',
    ?SQLDatabase $conn = null
  ) {
    $this->_db_name = $database;
    $this->_table_name = $table;
    $this->_primary_key = $primary_key;

    if ($conn !== null) {
      $this->_conn = $conn;
    }

    // Sync to static for trait compatibility
    static::$__db_name = $database;
    static::$__table_name = $table;
    static::$__primary_key = $primary_key;
  }

  /**
   * Factory method for fluent API.
   */
  public static function from(
    string $database,
    string $table,
    string $primary_key = 'id',
    ?SQLDatabase $conn = null
  ):static {
    return new static($database, $table, $primary_key, $conn);
  }

  // =========================================================================
  // Connection
  // =========================================================================

  public function setConnection(SQLDatabase $conn):void {
    $this->_conn = $conn;
  }

  public static function useConnection(SQLDatabase $conn):void {
    static::$_static_conn = $conn;
  }

  public function conn():SQLDatabase {
    return $this->_getConnection();
  }

  protected function _getConnection():SQLDatabase {
    if ($this->_conn instanceof SQLDatabase) {
      return $this->_conn;
    }

    if (static::$_static_conn instanceof SQLDatabase) {
      return static::$_static_conn;
    }

    global $database;
    if ($database instanceof SQLDatabase) {
      return $database;
    }

    throw new \RuntimeException('No database connection available.');
  }

  // =========================================================================
  // Schema
  // =========================================================================

  protected function _getSchema():TableSchema {
    $key = "{$this->_db_name}.{$this->_table_name}";

    if (!isset(self::$_schemas[$key])) {
      self::$_schemas[$key] = new TableSchema(
        $this->_getConnection(),
        $this->_db_name,
        $this->_table_name,
        $this->_primary_key
      );
    }

    return self::$_schemas[$key];
  }

  protected function _getFields():array {
    return $this->_getSchema()->getFields();
  }

  // =========================================================================
  // Property Access
  // =========================================================================

  public function __get(string $name):mixed {
    // Check internal properties first
    if ($name === '_created') return $this->_created;
    if ($name === '_updated') return $this->_updated;
    if ($name === '_author') return $this->_author;

    return $this->_data[$name] ?? null;
  }

  public function __set(string $name, mixed $value):void {
    // Handle internal properties
    if ($name === '_created') {
      $this->_created = $value;
      return;
    }
    if ($name === '_updated') {
      $this->_updated = $value;
      return;
    }
    if ($name === '_author') {
      $this->_author = $value;
      return;
    }

    $this->_data[$name] = $value;
  }

  public function __isset(string $name):bool {
    if (\in_array($name, ['_created', '_updated', '_author'], true)) {
      return true;
    }

    return isset($this->_data[$name]);
  }

  public function __unset(string $name):void {
    unset($this->_data[$name]);
  }

  /**
   * Get primary key value.
   */
  public function getId():mixed {
    return $this->_data[$this->_primary_key] ?? null;
  }

  /**
   * Set primary key value.
   */
  public function setId(mixed $value):void {
    $this->_data[$this->_primary_key] = $value;
  }

  /**
   * Get all data as array.
   */
  public function toArray():array {
    return \array_merge($this->_data, [
      '_created' => $this->_created,
      '_updated' => $this->_updated,
      '_author' => $this->_author,
    ]);
  }

  /**
   * Fill from array.
   */
  public function fill(array $data):static {
    foreach ($data as $key => $value) {
      $this->$key = $value;
    }

    return $this;
  }

  // =========================================================================
  // Static Accessors
  // =========================================================================

  public function primaryKey():string {
    return $this->_primary_key;
  }

  public function tableName():string {
    return $this->_table_name;
  }

  public function databaseName():string {
    return $this->_db_name;
  }

  public function tableFields():array {
    return $this->_getFields();
  }

  // =========================================================================
  // Query Methods
  // =========================================================================

  /**
   * Create a query builder for this table.
   */
  public function query():MultiformQuery {
    return new MultiformQuery(
      $this->_getConnection(),
      $this->_db_name,
      $this->_table_name,
      $this->_primary_key
    );
  }

  /**
   * Find by primary key.
   */
  public function findById(int|string $id):static|false {
    $conn = $this->_getConnection();

    $sql = \sprintf(
      "SELECT * FROM `%s`.`%s` WHERE `%s` = ? LIMIT 1",
      $this->_db_name,
      $this->_table_name,
      $this->_primary_key
    );

    $row = $conn->fetchOne($sql, [$id]);

    if (!$row) {
      $this->_userError('findById', "Record not found with {$this->_primary_key} = {$id}");
      return false;
    }

    return $this->_hydrateFromRow($row);
  }

  /**
   * Find all records.
   */
  public function findAll():array {
    return $this->query()->get();
  }

  /**
   * Check if a value exists.
   */
  public function valueExists(string $column, mixed $value):bool {
    return $this->query()
      ->where($column, $value)
      ->exists();
  }

  /**
   * Count all records.
   */
  public function countAll():int {
    return $this->query()->count();
  }

  /**
   * Find by SQL.
   */
  public function findBySql(string $sql, array $params = []):array|false {
    $conn = $this->_getConnection();

    // Replace placeholders
    $sql = \str_replace([':database:', ':db:'], $this->_db_name, $sql);
    $sql = \str_replace([':table:', ':tbl:'], $this->_table_name, $sql);
    $sql = \str_replace([':primary_key:', ':pkey:'], $this->_primary_key, $sql);

    $rows = $conn->fetchAll($sql, $params);

    if ($rows === false) {
      return false;
    }

    return \array_map(fn($row) => $this->_createFromRow($row), $rows);
  }

  // =========================================================================
  // CRUD
  // =========================================================================

  public function save():bool {
    return $this->getId() ? $this->_update() : $this->_create();
  }

  public function delete():bool {
    return $this->_delete();
  }

  protected function _create():bool {
    $conn = $this->_getConnection();

    // Timestamps
    if ($this->_created === null) {
      $this->_created = \date('Y-m-d H:i:s');
    }
    $this->_updated = \date('Y-m-d H:i:s');

    // Author
    if ($this->_author === null) {
      global $session;
      if (isset($session) && \is_object($session)) {
        $this->_author = $session->name ?? ($session->getName() ?? null);
      }
      if ($this->_author === null) {
        $this->_addError('_create', 'Author not set. Provide $session->name or set _author.');
      }
    }

    $attributes = $this->_getSanitizedAttributes();

    if (empty($attributes)) {
      $this->_userError('_create', 'No data to insert');
      return false;
    }

    $columns = \array_keys($attributes);
    $placeholders = \array_fill(0, \count($columns), '?');

    $sql = \sprintf(
      "INSERT INTO `%s`.`%s` (`%s`) VALUES (%s)",
      $this->_db_name,
      $this->_table_name,
      \implode('`, `', $columns),
      \implode(', ', $placeholders)
    );

    try {
      $conn->execute($sql, \array_values($attributes));

      $schema = $this->_getSchema();
      if ($schema->isNumeric($this->_primary_key)) {
        $this->setId($conn->insertId());
      }

      return true;
    } catch (\Exception $e) {
      $this->_systemError('_create', $e->getMessage());
      return false;
    }
  }

  protected function _update():bool {
    $conn = $this->_getConnection();
    $id = $this->getId();

    if (empty($id)) {
      $this->_userError('_update', 'Cannot update: primary key not set');
      return false;
    }

    $this->_updated = \date('Y-m-d H:i:s');

    $attributes = $this->_getSanitizedAttributes();

    if (empty($attributes)) {
      $this->_userError('_update', 'No data to update');
      return false;
    }

    $setPairs = [];
    $params = [];

    foreach ($attributes as $column => $value) {
      if ($value === null) {
        $setPairs[] = "`{$column}` = NULL";
      } else {
        $setPairs[] = "`{$column}` = ?";
        $params[] = $value;
      }
    }

    $params[] = $id;

    $sql = \sprintf(
      "UPDATE `%s`.`%s` SET %s WHERE `%s` = ?",
      $this->_db_name,
      $this->_table_name,
      \implode(', ', $setPairs),
      $this->_primary_key
    );

    try {
      $conn->execute($sql, $params);
      return true;
    } catch (\Exception $e) {
      $this->_systemError('_update', $e->getMessage());
      return false;
    }
  }

  protected function _delete():bool {
    $conn = $this->_getConnection();
    $id = $this->getId();

    if (empty($id)) {
      $this->_userError('_delete', 'Cannot delete: primary key not set');
      return false;
    }

    $sql = \sprintf(
      "DELETE FROM `%s`.`%s` WHERE `%s` = ? LIMIT 1",
      $this->_db_name,
      $this->_table_name,
      $this->_primary_key
    );

    try {
      $conn->execute($sql, [$id]);
      return $conn->affectedRows() === 1;
    } catch (\Exception $e) {
      $this->_systemError('_delete', $e->getMessage());
      return false;
    }
  }

  // =========================================================================
  // Attributes
  // =========================================================================

  protected function _getSanitizedAttributes():array {
    $schema = $this->_getSchema();
    $fields = $schema->getFields();
    $sanitized = [];

    // Include regular data
    foreach ($this->_data as $field => $value) {
      if (!\in_array($field, $fields, true)) {
        continue;
      }

      if ($this->_isEmpty($field, $value) && !\in_array($field, $this->empty_props, true)) {
        continue;
      }

      $sanitized[$field] = $this->_sanitizeValue($field, $value, $schema);
    }

    // Include timestamps if they exist in schema
    if (\in_array('_created', $fields, true) && $this->_created !== null) {
      $sanitized['_created'] = $this->_created;
    }
    if (\in_array('_updated', $fields, true) && $this->_updated !== null) {
      $sanitized['_updated'] = $this->_updated;
    }
    if (\in_array('_author', $fields, true) && $this->_author !== null) {
      $sanitized['_author'] = $this->_author;
    }

    return $sanitized;
  }

  protected function _sanitizeValue(string $field, mixed $value, TableSchema $schema):mixed {
    if ($schema->isBoolean($field)) {
      return $value ? 1 : 0;
    }

    if ($value === null) {
      return null;
    }

    return $value;
  }

  protected function _isEmpty(string $field, mixed $value):bool {
    $schema = $this->_getSchema();

    if ($schema->isNumeric($field)) {
      return \strlen((string) $value) === 0;
    }

    if ($schema->isDateTime($field)) {
      return empty($value) || !\strtotime((string) $value);
    }

    if (\is_bool($value)) {
      return false;
    }

    return empty($value);
  }

  // =========================================================================
  // Hydration
  // =========================================================================

  protected function _hydrateFromRow(array $row):static {
    foreach ($row as $key => $value) {
      if (\is_int($key)) continue;

      if ($key === '_created') {
        $this->_created = $value;
      } elseif ($key === '_updated') {
        $this->_updated = $value;
      } elseif ($key === '_author') {
        $this->_author = $value;
      } else {
        $this->_data[$key] = $value;
      }
    }

    $this->_original = $this->_data;

    return $this;
  }

  protected function _createFromRow(array $row):static {
    $instance = new static(
      $this->_db_name,
      $this->_table_name,
      $this->_primary_key,
      $this->_conn
    );

    return $instance->_hydrateFromRow($row);
  }

  // =========================================================================
  // Timestamp Accessors
  // =========================================================================

  public function created(?string $date = null):?string {
    if ($date !== null && \strtotime($date)) {
      $this->_created = $date;
    }

    return $this->_created;
  }

  public function updated():?string {
    return $this->_updated;
  }

  public function author():?string {
    return $this->_author;
  }

  /**
   * Check if data has changed.
   */
  public function isDirty(?string $field = null):bool {
    if ($field !== null) {
      return ($this->_data[$field] ?? null) !== ($this->_original[$field] ?? null);
    }

    return $this->_data !== $this->_original;
  }

  /**
   * Get changed fields.
   */
  public function getDirty():array {
    $dirty = [];

    foreach ($this->_data as $key => $value) {
      if (($this->_original[$key] ?? null) !== $value) {
        $dirty[$key] = $value;
      }
    }

    return $dirty;
  }
}
