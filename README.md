# PHP-PDO-Wrapper

A simple, modern, and lightweight PHP PDO wrapper for MySQL (and compatible databases).
Built with type safety, PSR standards, and inspired by [lincanbin/PHP-PDO-MySQL-Class](https://github.com/lincanbin/PHP-PDO-MySQL-Class).

## Features

- Easy database connections with automatic retry
- Secure query binding (including `IN (?)` arrays support)
- Fluent methods for common operations (select, insert, update, delete)
- Transaction support
- Error handling with automatic reconnection on server timeout
- Simple logging for connection and query errors

## Installation

You can install via Composer:
```
composer require victorvolpe/php-pdo-wrapper
```
Or simply include `DB.php` manually if you prefer not to use Composer.

## Usage

### 1. Instantiate the Database
```php
use VictorVolpe\PhpPdoWrapper\DB;

$db = new DB(
    dsn: 'mysql:host=localhost;dbname=testdb;charset=utf8mb4',
    user: 'your_username',
    pass: 'your_password'
);
```

### 2. Basic Query
```php
$users = $db->query("SELECT * FROM users WHERE status = :status", [
    'status' => 'active'
]);
```

### 3. Fetch a Single Row
```php
$user = $db->row("SELECT * FROM users WHERE id = :id", [
    'id' => 1
]);
```

### 4. Fetch a Single Column
```php
$emails = $db->column("SELECT email FROM users WHERE status = :status", [
    'status' => 'active'
]);
```

### 5. Fetch a Single Value
```php
$email = $db->single("SELECT email FROM users WHERE id = :id", [
    'id' => 1
]);
```

### 6. Insert a New Record
```php
$newUserId = $db->insert('users', [
    'username' => 'newuser',
    'email' => 'newuser@example.com',
    'status' => 'active'
]);
```

### 7. Update Existing Records
```php
$rowsAffected = $db->update('users', [
    'status' => 'inactive'
], "last_login < :date", [
    'date' => '2024-01-01'
]);
```

### 8. Delete Records
```php
$rowsDeleted = $db->delete('users', "status = :status", [
    'status' => 'inactive'
]);
```

### 9. Transactions
```php
$db->begin();

try {
    $db->query("UPDATE accounts SET balance = balance - :amount WHERE id = :id", [
        'amount' => 100,
        'id' => 1
    ]);

    $db->query("UPDATE accounts SET balance = balance + :amount WHERE id = :id", [
        'amount' => 100,
        'id' => 2
    ]);

    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

## Logging

If a connection error or query exception occurs, a log file will automatically be created in:
```
/logs/db-YYYY-MM-DD.log
```

## License

This project is open-sourced under the [MIT License](https://github.com/VictorVolpe/PHP-PDO-Wrapper/blob/master/LICENSE).