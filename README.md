FAD
===

FAD - PHP File Archive Database for PHP 5.4+

FAD is a lightweight, single function engine used to store key/value pairs in flat file databases.

Quick Start
===
```php
// include fad file 
require_once './lib/Fad/fad.php'; 

// tell fad where to store databases, and turn on display errors (do not use for production servers) 
fad(['path' => './cache', 'errors' => true]); 

// tell fad to create a database 'default': 
fad(['create' => ['default']]); 

// store a key/value pair (value can be string, int, float or array) 
fad('default.1', 'test value'); // insert into database 'default' with key '1' 

// you can also use auto increment key: 
fad('default', [1, 2, 3]); // returns '2' the auto key, store array 

// retrieve key values: 
echo fad('default.1'); // 'test value' 
print_r( fad('default.2') ); // [1, 2, 3]
```

See more examples at http://www.shayanderson.com/projects/fad.htm
