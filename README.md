James PRO PDO Wrapper
======

## Requirements

* PHP version 8.0 or higher

# Easy Installation

### Install with composer

To install with [Composer](https://getcomposer.org/), simply require the
latest version of this package.

```bash
composer require james-pro/james-db
```

Make sure that the autoload file from Composer is loaded.

## How to use

```php
use JamesPro\JamesDb;

try{
    $db = Db($dsn, $user, $passwd);
}catch(PDOException $exception){
    print_r($exception);
}

$db->select($table,$where,$params,$fields);
// $db = []

```
