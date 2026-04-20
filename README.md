# TimeFrontiers PHP Multiform

Dynamic table entity class with runtime table resolution.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Installation

```bash
composer require timefrontiers/php-multiform
```

## Requirements

- PHP 8.1+
- `timefrontiers/php-database-object` ^1.0
- `timefrontiers/php-pagination` ^1.0

## When to Use

Unlike the `DatabaseObject` trait which requires static table definitions, Multiform allows runtime table resolution. Use it for:

- Multi-tenant databases with per-tenant tables
- Sharded tables (users_1, users_2, etc.)
- Generic CRUD operations on arbitrary tables
- Admin panels and data browsers
- Dynamic form builders

## Quick Start

```php
use TimeFrontiers\Database\Multiform;

// Create
$form = new Multiform('mydb', 'users');
$form->name = 'John Doe';
$form->email = 'john@example.com';
$form->save();

// Or use factory method
$form = Multiform::from('mydb', 'users')
  ->fill(['name' => 'Jane', 'email' => 'jane@example.com']);
$form->save();

// Find by ID
$user = Multiform::from('mydb', 'users')->findById(123);

// Query builder
$users = Multiform::from('mydb', 'users')
  ->query()
  ->where('status', 'active')
  ->orderBy('name')
  ->get();
```

## Connection Management

```php
// Instance-level
$form = new Multiform('mydb', 'users', 'id', $conn);
// Or
$form->setConnection($conn);

// Class-level (all instances)
Multiform::useConnection($conn);

// Global fallback
global $database;
$database = new SQLDatabase(...);
```

## Property Access

Multiform uses magic methods for dynamic property access:

```php
$form = new Multiform('mydb', 'products');

// Set properties
$form->name = 'Widget';
$form->price = 29.99;
$form->status = 'active';

// Get properties
echo $form->name;     // "Widget"
echo $form->price;    // 29.99
echo $form->getId();  // Primary key value

// Fill from array
$form->fill([
  'name' => 'Gadget',
  'price' => 49.99,
]);

// Convert to array
$data = $form->toArray();
```

## Query Builder

### Basic Queries

```php
$table = Multiform::from('mydb', 'users');

// Find all
$users = $table->findAll();

// Find by ID
$user = $table->findById(123);

// Count
$count = $table->countAll();

// Check existence
$exists = $table->valueExists('email', 'john@example.com');
```

### Fluent Queries

```php
$table = Multiform::from('mydb', 'orders');

// WHERE conditions
$orders = $table->query()
  ->where('status', 'pending')
  ->where('total', '>', 100)
  ->get();

// OR conditions
$orders = $table->query()
  ->where('status', 'shipped')
  ->orWhere('priority', 'high')
  ->get();

// IN / NOT IN
$orders = $table->query()
  ->whereIn('status', ['pending', 'processing'])
  ->whereNotIn('customer_id', [1, 2, 3])
  ->get();

// NULL checks
$orders = $table->query()
  ->whereNull('shipped_at')
  ->whereNotNull('paid_at')
  ->get();

// Ordering & Pagination
$orders = $table->query()
  ->orderBy('created_at', 'DESC')
  ->limit(10)
  ->offset(20)
  ->get();

// First result
$order = $table->query()
  ->where('status', 'pending')
  ->first();

// Count & Exists
$count = $table->query()->where('status', 'pending')->count();
$exists = $table->query()->where('email', 'test@example.com')->exists();
```

### Custom SQL

```php
$table = Multiform::from('mydb', 'products');

$results = $table->findBySql(
  "SELECT * FROM :database:.:table:
   WHERE category = ? AND price < ?
   ORDER BY :primary_key: DESC",
  ['electronics', 500]
);
```

## CRUD Operations

### Create

```php
$product = new Multiform('store', 'products');
$product->name = 'Widget';
$product->price = 29.99;
$product->stock = 100;

if ($product->save()) {
  echo "Created with ID: " . $product->getId();
} else {
  $errors = $product->getErrors();
}
```

### Update

```php
$product = Multiform::from('store', 'products')->findById(123);
$product->price = 34.99;

if (!$product->save()) {
  $errors = $product->getErrors();
}
```

### Delete

```php
$product = Multiform::from('store', 'products')->findById(123);

if (!$product->delete()) {
  $errors = $product->getErrors();
}
```

## Timestamps & Author

Multiform automatically handles these fields if they exist in the table:

| Field | Behavior |
|-------|----------|
| `_created` | Set to current datetime on insert |
| `_updated` | Set to current datetime on insert/update |
| `_author` | Set from `$session->name` on insert |

```php
$form = new Multiform('mydb', 'posts');
$form->title = 'Hello World';
$form->save();

echo $form->created();  // "2024-01-15 10:30:00"
echo $form->updated();  // "2024-01-15 10:30:00"
echo $form->author();   // "john_doe"
```

## Dirty Tracking

Track changes to entity data:

```php
$user = Multiform::from('mydb', 'users')->findById(123);

$user->name = 'New Name';

// Check if anything changed
if ($user->isDirty()) {
  // Get changed fields
  $changes = $user->getDirty();
  // ['name' => 'New Name']
}

// Check specific field
if ($user->isDirty('name')) {
  // name was changed
}
```

## Multi-Tenant Example

```php
class TenantDatabase {

  private string $tenant_id;

  public function __construct(string $tenant_id) {
    $this->tenant_id = $tenant_id;
  }

  public function table(string $table):Multiform {
    // Each tenant has their own database
    $database = "tenant_{$this->tenant_id}";
    return Multiform::from($database, $table);
  }
}

// Usage
$tenant = new TenantDatabase('acme_corp');

$users = $tenant->table('users')
  ->query()
  ->where('status', 'active')
  ->get();

$newUser = $tenant->table('users');
$newUser->name = 'John';
$newUser->save();
```

## Sharded Table Example

```php
function getUserShard(int $user_id):Multiform {
  $shard = $user_id % 4;  // 4 shards
  return Multiform::from('users_db', "users_{$shard}");
}

// Find user across shards
$user = getUserShard(12345)->findById(12345);

// Query specific shard
$users = getUserShard(0)
  ->query()
  ->where('status', 'active')
  ->get();
```

## Admin Panel Example

```php
// Generic data browser
function browseTable(string $database, string $table, array $filters = []):array {
  $query = Multiform::from($database, $table)->query();

  foreach ($filters as $field => $value) {
    $query->where($field, $value);
  }

  return $query->orderByDesc('_created')->take(50)->get();
}

// Generic record editor
function updateRecord(string $database, string $table, int $id, array $data):bool {
  $record = Multiform::from($database, $table)->findById($id);

  if (!$record) {
    return false;
  }

  return $record->fill($data)->save();
}
```

## Pagination

Both `Multiform` and `MultiformQuery` include the `Pagination` trait from `timefrontiers/php-pagination`.

### Via the query builder (recommended)

`MultiformQuery::paginate()` counts matching rows, then fetches the current page in one call. It returns an array with `data` (hydrated `Multiform` instances) and `meta` (pagination metadata).

```php
// Explicit page / per-page
$result = Multiform::from('mydb', 'orders')
  ->query()
  ->where('status', 'pending')
  ->orderBy('_created', 'DESC')
  ->paginate(page: 2, per_page: 25);

foreach ($result['data'] as $order) {
  echo $order->id;
}

// $result['meta'] shape:
// [
//   'current_page'  => 2,
//   'per_page'      => 25,
//   'total'         => 87,
//   'total_pages'   => 4,
//   'from'          => 26,
//   'to'            => 50,
//   'has_more'      => true,
//   'is_first_page' => false,
//   'is_last_page'  => false,
// ]
```

#### Auto-read from request

When `$page` / `$per_page` are omitted, values are read from `$_GET` (then `$_POST`) using the supplied key names — same behaviour as `Pagination::fromRequest()`.

```php
// Reads ?page=3&per_page=10 from the request automatically
$result = Multiform::from('mydb', 'products')->query()->paginate();

// Custom request keys (?p=3&limit=10)
$result = Multiform::from('mydb', 'products')
  ->query()
  ->paginate(page_key: 'p', per_page_key: 'limit');
```

#### API response

```php
$result = Multiform::from('mydb', 'users')
  ->query()
  ->where('active', 1)
  ->paginate();

return json_encode([
  'data'       => array_map(fn($u) => $u->toArray(), $result['data']),
  'pagination' => $result['meta'],
]);
```

### Via the Multiform instance

Because `Multiform` itself uses the `Pagination` trait you can also manage pagination state on the instance and pass `limitClause()` into a custom SQL query.

```php
$table = Multiform::from('mydb', 'users');

// Configure from request or explicitly
$table->fromRequest();
// or: $table->setPage(2)->setPerPage(25);

// Get total count for the full result set
$table->setTotalCount($table->countAll());

// Query with LIMIT/OFFSET baked in
$users = $table->findBySql(
  "SELECT * FROM :database:.:table:
   WHERE active = 1
   ORDER BY name ASC
   {$table->limitClause()}",
);

echo "Page {$table->currentPage()} of {$table->totalPages()}";
echo "Showing {$table->itemStart()}–{$table->itemEnd()} of {$table->totalCount()}";

// Pagination links
foreach ($table->pageRange(2) as $page) {
  if ($page === null) {
    echo '<span>…</span>';
  } else {
    $active = $page === $table->currentPage() ? 'active' : '';
    echo "<a href='{$table->pageUrl($page)}' class='{$active}'>{$page}</a>";
  }
}
```

### Pagination meta helpers

All methods from the `Pagination` trait are available on both the `Multiform` instance and (via the query builder result) indirectly through the `meta` array. Key methods:

| Method | Returns | Description |
|--------|---------|-------------|
| `setPage(int)` | `static` | Set current page |
| `setPerPage(int)` | `static` | Set items per page (1–1000) |
| `setTotalCount(int)` | `static` | Set total item count |
| `fromRequest(...)` | `static` | Load page/per_page from `$_GET` |
| `currentPage()` | `int` | Current page |
| `totalPages()` | `int` | Total pages |
| `offset()` | `int` | SQL OFFSET value |
| `limitClause()` | `string` | `"LIMIT X OFFSET Y"` string |
| `hasPreviousPage()` | `bool` | Previous page exists |
| `hasNextPage()` | `bool` | Next page exists |
| `pageRange(int)` | `array` | Page numbers with `null` for ellipsis |
| `pageUrl(int, ...)` | `string` | URL for a specific page |
| `paginationToArray()` | `array` | Full metadata array |
| `paginationMeta(...)` | `array` | Metadata + prev/next links |

## Error Handling

```php
$form = new Multiform('mydb', 'products');
$form->name = 'Widget';

if (!$form->save()) {
  // Check for errors
  if ($form->hasErrors('_create')) {
    $message = $form->firstError('_create');
  }

  // Get all errors
  $errors = $form->getErrors();

  // Use with InstanceError for rank-based filtering
  $visibleErrors = (new InstanceError($form))->get('_create');
}
```

## License

MIT License
