## Automattic Create, Read, Update, & Delete

Year after year we build database schema's, CRUD via Models or ORM's, and validation libraries to make sure only valid data is stored in our database. Basically, we write the same code many times over.

What if the computer could just look at the database and figure it out? What if we could simply start passing HTML form data to the backend and trust it to secure everything for us? What if we could ask it for something and get a nice JSON result array back?

Well, this project is a rough stab at that goal.

ACRUD is an attempt to make an automated scaffolding system in PHP using the data that MySQL, SQLite, and PostgreSQL* provide about themselves.

This enables you to design a schema and immediately begin prototyping and building your frontend application. When you get closer to launch and want to add finer controls and validation to your data you can. 

------

## Composer Quickstart

Add the following to your `composer.json` config file and then run `composer install`.

	{
		"require" : {
			"xeoncross/acrud": "dev-master"
		}
	}

Inside your project include the composer autoloader.

	<?php
	require('vendor/autoload.php');

Then simply pass a PDO object to the ACRUD instance factory.

	$pdo = new PDO(
		'sqlite:testdb.db',
		0,
		0,
		array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ
		)
	);

	$acrud = \ACRUD\Instance::factory($pdo);

	$errors = $acrud->validate('user', $_POST['user']);

	if($errors) {
		die(print_r($errors));
	} else {
		$acrud->save('user', $_POST['user']);
	}

If you would like to get your hands dirty with more meta-data, these methods will provide more details about your schema.

	$tables = $acrud->getTables();
	$foreignKeys = $acrud->getForeignKeys();
	$columns = $acrud->getColumns();

------

## Self-Contained ACRUD API Server

I'm assuming you have a website like `http://example.com` (or `http://example.loc` if you know how to use vhosts). Checkout ACRUD into a subfolder like `http://example.com/acrud`.

Setup a rewrite rule to forward all traffic to `acrud.php`. if you use Nginx something like this should work:

	location /acrud {
	    rewrite ^/acrud/(.*)$ /acrud/acrud.php/$1 break;
	}

For all you older Apache people something like this should work.

### .htaccess

	RewriteEngine on
	RewriteCond %{REQUEST_FILENAME} !-f
	RewriteCond %{REQUEST_FILENAME} !-d
	RewriteRule ^/acrud/(.*)$ acrud/acrud.php [L,QSA]


### acrud.php

Create a file in the `/acrud` folder called `acrud.php` and paste the following into it.

	<?php

	// If using Composer
	require("vendor/autoload.php");

	/* Or if you downloaded ACRUD manually and pasted it into that folder
	spl_autoload_register(function ($class) {
	    require __DIR__ . '/acrud/' . str_replace('\\', '/', $class) . '.php';
	});
	*/

	function getACRUD()
	{
		// Create a new PDO connection
		$pdo = new PDO(
			'mysql:dbname=croscon_start;host=localhost',
			'root',
			'',
			array(
				\PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
				\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION
			)
		);

		return \ACRUD\Instance::factory($pdo);
	}

	$router = new \ACRUD\Router('/acrud');

	$router->route('tables(/meta)?', function($app_path, $meta = false)
	{
		$acrud = getACRUD();
		$columns = $acrud->getColumns();

		if($meta) {
			return $columns;
		} else {
			return array_keys($columns);
		}
	});

	$router->route('fields/(\w+)', function($app_path, $table)
	{
		$acrud = getACRUD();
		$columns = $acrud->getColumns();

		if( ! isset($columns[$table])) {
			throw new Exception("Table $table doesn't exist");
		}

		return $columns[$table];
	});

	$router->route('fetch/(\w+)/(\d+)/(\d+)', function($app_path, $table, $limit, $offset)
	{
		$acrud = getACRUD();
		$columns = $acrud->getColumns();

		if( ! isset($columns[$table])) {
			throw new Exception("Table $table doesn't exist");
		}

		return $acrud->fetch("SELECT * FROM $table LIMIT $limit OFFSET $offset");
	});

	$router->route('save', function($app_path)
	{
		if(empty($_POST)) {
			throw new Exception("No data provided");
		}

		$acrud = getACRUD();
		$columns = $acrud->getColumns();

		$validation = array();
		$records = array();

		foreach($_POST as $table => $rows) {

			if( ! isset($columns[$table])) {
				throw new Exception("Table $table doesn't exist");
			}

			$records[$table] = array();

			foreach($rows as $i => $row) {
				
				if($errors = $acrud->validate($table, $row, $columns[$table])) {

					if( ! isset($validation[$table])) {
						$validation[$table] = array();
					}

					$validation[$table][$i] = $errors;

				} else {

					$records[$table][$i] = $acrud->save($table, $row, $columns[$table]);

				}

			}
		}

		if($validation) {
			header('HTTP/1.0 400 Bad Request');
		}

		return array(
			'validation' => $validation,
			'records' => $records,
			'ok' => ! $validation
		);

	});

	$router->run();
	
	// If we made it this far, we didn't find a matching route
	header('HTTP/1.0 404 Not Found');
	echo "<h1>404 Not Found</h1>";
	echo "The page that you have requested could not be found.";
	exit();



## Go for a test spin!

 * http://example.com/acrud/tables
 * http://example.com/acrud/tables/meta
 * http://example.com/acrud/fields/[table]
 * http://example.com/acrud/fetch/[table]/[limit]/[offset]
 * http://example.com/acrud/save (with $_POST data)
