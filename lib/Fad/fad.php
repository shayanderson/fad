<?php
/**
 * Fad - File Archive Database for PHP 5.4+
 * 
 * @package Fad
 * @version 1.0.b - Feb 01, 2014
 * @copyright 2014 Shay Anderson <http://www.shayanderson.com>
 * @license MIT License <http://www.opensource.org/licenses/mit-license.php>
 * @link <http://www.shayanderson.com/projects/fad.htm>
 */

/**
 * FAD engine function
 *
 * @author Shay Anderson 02.14
 *
 * @staticvar array $conf
 *		array create - create databases in array
 *		boolean errors - display errors
 *		string ext - database file extension
 *		boolean gzip - use database gzip compression
 *		string path - database store path
 * @staticvar array $cache
 * @staticvar array $errors
 * @staticvar boolean $is_init
 * @param array|string $key (array used for configuration settings setter)
 * @param array|float|int|string $data (value to store)
 * @return mixed (returns false on fail/error)
 * @throws \Exception
 *
 * @example
 *		// include fad file
 *		require_once './lib/Fad/fad.php';
 *
 *		// tell fad where to store databases, and turn on display errors
 *		fad(['path' => './cache', 'errors' => true]);
 *
 *		// tell fad to create a database 'default':
 *		fad(['create' => 'default']);
 *
 *		// store a key/value pair (value can be string, int, float or array)
 *		fad('default.1', 'test value'); // insert into database 'default' with key '1'
 *
 *		// you can also use auto increment key:
 *		fad('default', 'another test value'); // returns '2' the auto key
 *
 *		// retrieve key values:
 *		echo fad('default.1');
 *		echo '<br />';
 *		echo fad('default.2');
 */
function fad($key, $data = null)
{
	// conf //////////////////////////////////////////////////////////////////////////
	static $conf = [
		'create' => [],
		'errors' => false,
		'ext' => '.dat',
		'gzip' => false,
		'path' => null
	];

	if(is_array($key)) // conf getter/setter
	{
		foreach($key as $k => $v)
		{
			if(isset($conf[$k]) || array_key_exists($k, $conf)) // add valid conf
			{
				$conf[$k] = $v; // setter
			}
		}

		return $conf; // conf getter
	}

	// init //////////////////////////////////////////////////////////////////////////
	static $cache = []; // read cache

	static $errors = []; // error log
	$fatal = function($error) use (&$conf, &$errors)
	{
		$errors[] = $error; // cache error

		if($conf['errors'])
		{
			throw new \Exception($error);
		}
	};

	$meta = [ // database metadata
		'action' => null,
		'key' => null,
		'path' => null,
		'sep' => ':',
		'tag' => null,
		'tag_key' => null,
		'tmp_path' => null
	];

	static $is_init = false;
	if(!$is_init)
	{
		if($conf['path'] === null)
		{
			$fatal('Empty database storage path (use: ' . __FUNCTION__
				. '([\'path\' => \'./cache\']))');
			return false;
		}

		$conf['path'] = rtrim($conf['path'], '/\\') . DIRECTORY_SEPARATOR; // format dir

		if(!is_dir($conf['path']))
		{
			$fatal('"' . $conf['path'] . '" is not a directory');
			return false;
		}

		if(!is_writable($conf['path']))
		{
			$fatal('"' . $conf['path'] . '" is not writable');
			return false;
		}

		$is_init = true;
	}

	// format key /////////////////////////////////////////////////////////////////////
	if(preg_match('/[^\w\.\:]+/', $key))
	{
		$fatal('Invalid key "' . $key . '" (key allowed characters: "a-zA-Z0-9_:.")');
		return false;
	}

	if(strpos($key, ':') !== false) // match: 'x:action'
	{
		$meta['action'] = substr($key, strpos($key, ':') + 1, strlen($key));
		$key = substr($key, 0, strpos($key, ':'));
	}

	if(strpos($key, '.') === false && empty($meta['action'])) // no database and no action
	{
		$key = [$key, fad($key . ':max') + 1]; // gen auto key
	}
	else // match: 'database.key'
	{
		$key = explode('.', $key);
	}

	$meta['key'] = isset($key[1]) ? $key[1] : null;
	$meta['tag'] = $key[0];
	$meta['tag_key'] = $meta['tag'] . '.' . $meta['key'];
	$meta['path'] = $conf['path'] . $key[0] . $conf['ext'] . ( $conf['gzip'] ? '.gz' : '' );
	$meta['tmp_path'] = $meta['path'] . '.tmp' . $conf['ext'] . ( $conf['gzip'] ? '.gz' : '' );

	// init db ////////////////////////////////////////////////////////////////////////
	if(!is_array($conf['create']) || !in_array($meta['tag'], $conf['create']))
	{
		$fatal('Database "' . $meta['tag']
			. '" has not been created (use: fad([\'create\' => \'database_name\']))');
		return false;
	}

	if(!is_file($meta['path']))
	{
		if(@file_put_contents($meta['path'], null, LOCK_EX) === false)
		{
			$fatal('Failed to create database "' . $meta['path'] . '"');
			return false;
		}
	}

	$func_db_open = function($path, $mode = 'rb', $lock_type = LOCK_SH) use (&$conf, &$fatal)
	{
		if(($db = @fopen(( $conf['gzip'] ? 'compress.zlib://' : '' ) . $path, $mode)) === false)
		{
			$fatal('Failed to read database "' . $path . '"');
			return false;
		}

		@flock($db, $lock_type); // lock

		return $db;
	};

	$func_db_close = function(&$db)
	{
		@flock($db, LOCK_UN); // unlock
		@fclose($db);
		$db = null;
	};

	$func_db_pack_line = function($unpacked_data) use (&$func_db_prep_array, &$fatal)
	{
		if(!is_string($unpacked_data) && !is_int($unpacked_data) && !is_float($unpacked_data)
			&& !is_array($unpacked_data)) // allowed: string, int, float, array
		{
			$fatal('Invalid data type (allowed types: array, float, int, string)');
			return false;
		}

		return base64_encode(serialize($unpacked_data)) . PHP_EOL;
	};

	$func_db_unpack_line = function($packed_data) use (&$func_db_prep_array)
	{
		$packed_data = unserialize(base64_decode($packed_data));

		return $packed_data;
	};

	$func_db_replace = function(&$conf, &$meta, &$data) use (&$func_db_open, &$func_db_close,
		&$func_db_pack_line, &$fatal, &$cache)
	{
		$is_replaced = false;

		if(@file_put_contents($meta['tmp_path'], null, LOCK_EX) === false) // create temp database
		{
			$fatal('Failed to create temp database "' . $meta['tmp_path'] . '"');
			return false;
		}

		unset($cache[$meta['tag_key']]); // clear from cache

		$db = $func_db_open($meta['path']);
		$db_tmp = $func_db_open($meta['tmp_path'], 'wb', LOCK_EX); // create temp DB (write lock)

		while(($ln = fgets($db)) !== false)
		{
			$ln_tmp = explode($meta['sep'], $ln);

			if(strcmp($meta['key'], $ln_tmp[0]) === 0) // key
			{
				$is_replaced = true;

				if($meta['action'] === 'update')
				{
					$ln = $meta['key'] . $meta['sep'] . $func_db_pack_line($data); // update line
				}
				else if($meta['action'] === 'delete')
				{
					continue; // no write
				}
			}

			fwrite($db_tmp, $ln); // write to tmp db
		}

		$func_db_close($db);
		$func_db_close($db_tmp);

		if(!$is_replaced)
		{
			if(!@unlink($meta['tmp_path']))
			{
				$fatal('Failed to drop temp database "' . $meta['tmp_path'] . '"');
				return false;
			}

			$fatal('Failed to ' . $meta['action'] . ' key "' . $meta['tag_key']
				. '" (does not exist)');
			return false;
		}

		if(!@rename($meta['tmp_path'], $meta['path'])) // replace database with temp database
		{
			$fatal('Failed to move temp database "' . $meta['tmp_path'] . '" to "'
				. $meta['path'] . '"');
			return false;
		}

		return true;
	};

	// action /////////////////////////////////////////////////////////////////////////
	if(!empty($meta['action']))
	{
		switch($meta['action'])
		{
			case 'count': // count records in database
				$i = 0;

				$db = $func_db_open($meta['path']);

				while(($ln = fgets($db)) !== false)
				{
					$i++;
				}

				$func_db_close($db);

				return $i;
				break;

			case 'delete': // delete key
			case 'update': // update key
				return $func_db_replace($conf, $meta, $data);
				break;

			case 'drop': // drop databse
				if(@unlink($meta['path']) === false)
				{
					$fatal('Failed to drop datbase "' . $meta['path'] . '"');
					return false;
				}

				$cache = []; // clear cache

				return true;
				break;

			case 'error': // get last error
				return end($errors);
				break;

			case 'errors': // get errors
				return $errors;
				break;

			case 'key': // database has key?
				$is_key = false;

				$db = $func_db_open($meta['path']);

				while(($ln = fgets($db)) !== false)
				{
					$ln = explode($meta['sep'], $ln);

					if(strcmp($meta['key'], $ln[0]) === 0)
					{
						$is_key = true;
						break;
					}
				}

				$func_db_close($db);

				return $is_key;
				break;

			case 'keys': // get all database keys
				$keys = [];

				$db = $func_db_open($meta['path']);

				while(($ln = fgets($db)) !== false)
				{
					$ln = explode($meta['sep'], $ln);

					$keys[] = $ln[0];
				}

				$func_db_close($db);

				return $keys;
				break;

			case 'max': // get max key (if numeric keys)
				$max = 0; // init at 0 for non-numeric keys

				$db = $func_db_open($meta['path']);

				while(($ln = fgets($db)) !== false)
				{
					$ln = explode($meta['sep'], $ln);

					if(!is_numeric($ln[0]))
					{
						continue; // cannot max non-numeric key
					}

					$ln[0] = (int)$ln[0];

					if($ln[0] > $max)
					{
						$max = $ln[0];
					}
				}

				$func_db_close($db);

				return $max;
				break;

			case 'select': // (select, [limit]), (select, [offset, limit])
				$sel = [];

				if($data === null) // select all
				{
					$db = $func_db_open($meta['path']);

					while(($ln = fgets($db)) !== false)
					{
						$ln = explode($meta['sep'], $ln);
						$k = array_shift($ln);
						$sel[$k] = $func_db_unpack_line(implode($meta['sep'], $ln));
					}

					$func_db_close($db);
				}
				else if(is_array($data))
				{
					$offset = isset($data[1]) ? (int)$data[0] : 0;
					$limit = isset($data[1]) ? (int)$data[1] : (int)$data[0];

					$db = $func_db_open($meta['path']);

					$i = $j = 0;
					while(($ln = fgets($db)) !== false)
					{
						if($i >= $offset && $j < $limit)
						{
							$ln = explode($meta['sep'], $ln);
							$k = array_shift($ln);
							$sel[$k] = $func_db_unpack_line(implode($meta['sep'], $ln));

							$j++;
						}

						$i++;
					}

					$func_db_close($db);
				}

				return $sel;
				break;

			default:
				$fatal('Invalid action "' . $meta['action'] . '" (unknown action)');
				return false;
				break;
		}
	}

	// setter /////////////////////////////////////////////////////////////////////////
	if($data !== null)
	{
		if(fad($meta['tag_key'] . ':key')) // key exists
		{
			$fatal('Failed to set key "'  . $meta['tag_key']
				. '", key already exists in database');
			return false;
		}

		$db = $func_db_open($meta['path'], 'ab', LOCK_EX);

		$write = @fwrite($db, $meta['key'] . $meta['sep'] . $func_db_pack_line($data));

		$func_db_close($db);

		if($write === false)
		{
			$fatal('Failed to write to database "' . $meta['path'] . '"');
			return false;
		}

		return $meta['key'];
	}

	// getter /////////////////////////////////////////////////////////////////////////
	if(isset($cache[$meta['tag_key']])) // read from cache
	{
		return $cache[$meta['tag_key']];
	}

	$db = $func_db_open($meta['path']);
	$is_key = false;
	$retval = null;

	while(($ln = fgets($db)) !== false)
	{
		$ln = explode($meta['sep'], $ln);

		if(strcmp($meta['key'], $ln[0]) === 0)
		{
			array_shift($ln);
			$is_key = true;
			$retval = $func_db_unpack_line(implode($meta['sep'], $ln));
			break;
		}
	}

	if(!$is_key)
	{
		$fatal('Database key "' . $meta['tag_key'] . '" does not exist');
		return false;
	}

	$func_db_close($db);

	$cache[$meta['tag_key']] = $retval; // cache

	if(count($cache) > 30) // 30 max caches
	{
		$cache = array_slice($cache, (count($cache) - 30), count($cache));
	}

	return $retval;
}