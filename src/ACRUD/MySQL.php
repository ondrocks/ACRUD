<?php
/**
 * Database abstraction for the MySQL database. Assumes version 5.x or higher.
 * The ACRUD/Instance factory will initialize this class if needed by the PDO object.
 *
 * @package    ACRUD
 * @author     David Pennington
 * @license    MIT License
 * @copyright  2013
 * @link       http://github.com/Xeoncross/ACRUD
 * @link       http://davidpennington.me
 */
namespace ACRUD;

class MySQL extends Instance
{

	public function getTables()
	{
		return $this->fetch("SHOW TABLES", null, 0);
	}

	public function getForeignKeys()
	{
		if($this->foreign_keys) {
			return $this->foreign_keys;
		}

		$sql = "SELECT * FROM information_schema.KEY_COLUMN_USAGE"
			//. " WHERE table_schema = DATABASE() AND CONSTRAINT_NAME != 'PRIMARY'" // If you wanted indexes/uniques
			. " WHERE table_schema = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL"
			. " ORDER BY table_name, ordinal_position";

		$result = $this->fetch($sql);

		$tables = array();
		foreach($result as $row) {

			if(empty($tables[$row->TABLE_NAME])) {
				$tables[$row->TABLE_NAME] = array();
			}

			$tables[$row->TABLE_NAME][$row->COLUMN_NAME] = array(
				'table' => $row->REFERENCED_TABLE_NAME,
				'column' => $row->REFERENCED_COLUMN_NAME
			);
		}

		return $this->foreign_keys = $tables;
	}

	public function getRelations()
	{
		if($this->relations) {
			return $this->relations;
		}

		$sql = "SELECT * FROM information_schema.KEY_COLUMN_USAGE"
			//. " WHERE table_schema = DATABASE() AND CONSTRAINT_NAME != 'PRIMARY'" // If you wanted indexes/uniques
			. " WHERE table_schema = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL"
			. " ORDER BY table_name, ordinal_position";

		$result = $this->fetch($sql);

		$relations = array();
		foreach($result as $row) {

			if(empty($relations[$row->REFERENCED_TABLE_NAME])) {
				$relations[$row->REFERENCED_TABLE_NAME] = array();
			}

			// This method is simpler, has-many-through relationships get overwritten
			// $relations[$row->REFERENCED_TABLE_NAME][$row->TABLE_NAME . '.' . $row->COLUMN_NAME] = $row->REFERENCED_COLUMN_NAME;
			// $relations[$row->REFERENCED_TABLE_NAME][$row->TABLE_NAME] = $row->COLUMN_NAME;

			if(empty($relations[$row->REFERENCED_TABLE_NAME][$row->TABLE_NAME])) {
				$relations[$row->REFERENCED_TABLE_NAME][$row->TABLE_NAME] = array();
			}

			$relations[$row->REFERENCED_TABLE_NAME][$row->TABLE_NAME][$row->COLUMN_NAME] = $row->REFERENCED_COLUMN_NAME;
		}

		return $this->relations = $relations;
	}

	/*
	public function getRelationSQLMap() {

		$results = $this->getRelations();

		$relations = array();
		foreach($results as $table => $row) {

			if(empty($relations[$table])) {
				$relations[$table] = array();
			}

			foreach ($row as $foreign_table => $meta) {
				// Has many through relationship
				if(count($meta) == 2) {

					list($fk1, $fk2) = array_keys($meta);
					list($pk1, $pk2) = array_values($meta);

					// It can be either column
					// $column_sql = "$foreign_table." . join(" = ? OR $foreign_table.", array_keys($meta)) . ' = ?';

					$relations[$table][$table] = array(
						'sql' => 'SELECT * FROM ' . $table
							. ' LEFT JOIN ' . $foreign_table . ' ON ' . $foreign_table . '.' . $fk2
							. ' = ' . $table . '.' . $pk2
							. ' WHERE '
							// . $column_sql;
							. $foreign_table . '.' . $fk1. ' = ?',
						'key' => $pk1
					);

					continue;
				}

				$relations[$table][$foreign_table] = array(
					'sql' => 'SELECT * FROM ' . $foreign_table
						. ' WHERE ' . key($meta) . ' = ?',
					'key' => current($meta)
				);

			}
		}

		return $relations;
	}
	*/


	/**
	 * Guess at the best text columns to represent a "name" for the table
	 * For example, like "username" for a user.
	 *
	 * @todo look at the table.column->comment instead?
	 */
	public function nameColumnsForTable($table, $alias = null) {

		$columns = $this->getColumns();
		$alias = $alias ? $alias : $table;
		$fields = [];

		$format = function($field) use(&$alias) {
			return "`$alias`.`$field`";
		};

		// Certain text fields contain the most important information for a row.
		$name_fields = array(
      'firstname', 'first_name',
      'lastname', 'last_name',
	    'username',
      'email',
		);

		// if(isset($columns[$table]['firstname']) AND isset($columns[$table]['lastname'])) {
		// 	$fields[] = 'firstname';
		// }

		// User fields
		foreach($columns[$table] as $field => $meta) {
      if($meta['type'] == 'text' AND in_array($field, $name_fields)) {
        $fields[$field] = $format($field);
      }
    }

		if(count($fields) >= 2) {
			return $fields;
		}


		// Posts, Groups, Forums, etc...
		$name_fields = array(
			'title', 'name'
		);

		foreach($columns[$table] as $field => $meta) {
      if($meta['type'] == 'text' AND in_array($field, $name_fields)) {
        $fields[$field] = $format($field);
      }
    }

		if(count($fields) >= 2) {
			return $fields;
		}

		$x = 0;
		foreach($columns[$table] as $field => $data) {
			if($data['type'] == "text" AND $data['length'] <= 255) {
				$fields[$field] = $format($field);
				if($x++ > 2) break;
			}
		}

		if(count($fields) >= 2) {
			return $fields;
		}

		$x = 0;
		foreach($columns[$table] as $field => $data) {
			if($data['type'] == "text") {
				$fields[$field] = $format($field);
				if($x++ > 2) break;
			}
		}

		if(count($fields) >= 2) {
			return $fields;
		}

		// Fine, just use anything to give us an idea of the contents of this parent record
		$x = 0;
		foreach($columns[$table] as $field => $data) {
			if($field === 'id') continue;
			$fields[$field] = $format($field);
			if($x++ > 2) break;
		}

		return $fields;

	}

	/**
	 * Write the SQL to load a record and it's belongsTo relations
	 * has-many-through relations are created elsewhere
	 * This method should only be used to generate the first part of the
	 * query as it is up to the calling code to add a LIMIT or WHERE id = ?
	 */
	public function loadRecordWithBelongsSQL($table, array $ignore_tables = null, $length = 50) {

		// var_dump(func_get_args());

		$columns = $this->getColumns();
		$fk = $this->getForeignKeys();

		if(empty($columns[$table])) {
			return array('error' => 'Invalid Table');
		}

		$select = ["`$table`.*"];
		$joins = [];
		$x = 0;

		if(isset($fk[$table])) {
			foreach($fk[$table] as $column => $meta) {

				if($ignore_tables AND in_array($meta['table'], $ignore_tables)) {
					// print "Skipping " . $meta['table'] . "\n";die();
					continue;
				}
				// var_dump($ignore_tables);die();

				$x++;

				$alias = "t$x";
				$joins[] = 'LEFT JOIN `' . $meta['table'] . "` $alias ON $alias." . $meta['column'] . " = `$table`.$column";
				// $joins[] = 'LEFT JOIN `' . $meta['table'] . '` ON `' . $meta['table'] . '`.' . $meta['column'] . " = `$table`.$column";

				$fields = $this->nameColumnsForTable($meta['table'], $alias);

				$size = floor($length / count($fields));

				// @todo handle null
				$select[] = 'CONCAT_WS(" - ", SUBSTRING(' . join(", 1, $size), SUBSTRING(", $fields) . ", 1, $size)) as " . $column . '_TEXT';

				// $select[] = 'CONCAT(SUBSTRING(COALESCE(' . join(", ''), 1, $size), ' - ', SUBSTRING(COALESCE(", $fields) . ", ''), 1, $size)) as " . $column . '_TEXT';
			}

		}

		$sql = 'SELECT ' . join(', ', $select) . " FROM `$table`";
		if($joins) {
			$sql .= " " . join(" ", $joins);
		}

		return $sql;
	}

	public function getColumns()
	{
		if($this->columns) {
			return $this->columns;
		}

		$foreign_keys = $this->getForeignKeys();

		$sql = "SELECT * FROM information_schema.columns"
			. " WHERE table_schema = DATABASE()"
			. " ORDER BY table_name, ordinal_position";

		$result = $this->fetch($sql);

		$columns = array();
		foreach($result as $column) {

			if($column) {

				$type = $this->mapType($column->DATA_TYPE);

				// MySQL boolean == tinyint(1)
				if($column->COLUMN_TYPE == 'tinyint(1)') {
					$type = 'boolean';
				}

				$columns[$column->TABLE_NAME][$column->COLUMN_NAME] = array(
					'default' => $column->COLUMN_DEFAULT ?: null,
					'nullable' => $column->IS_NULLABLE === 'YES',
					'type' => $type,
					'length' => $column->CHARACTER_MAXIMUM_LENGTH ?: null,
					'precision' => $column->NUMERIC_PRECISION ?: null,
					'scale' => $column->NUMERIC_SCALE ?: null,
					'comment' => $column->COLUMN_COMMENT ?: null,
					// PRI, MUL, etc...
					'index' => $column->COLUMN_KEY ? true : null,
					'primary' => $column->COLUMN_KEY === 'PRI',
					'unique' => $column->COLUMN_KEY === 'UNI',
				);

				if(isset($foreign_keys[$column->TABLE_NAME][$column->COLUMN_NAME])) {

					$fk = $foreign_keys[$column->TABLE_NAME][$column->COLUMN_NAME];
					$columns[$column->TABLE_NAME][$column->COLUMN_NAME] += $fk;

				}

			}
		}

		return $this->columns = $columns;
	}


	public function mapType($type)
	{
		$types = array(
			'int'		=> 'integer',
			'tinyint'	=> 'integer',
			'smallint'	=> 'integer',
			'mediumint'	=> 'integer',
			'bigint'	=> 'integer',
			'bit'		=> 'integer',

			'double'	=> 'decimal',
			'float'		=> 'decimal',
			'decimal'	=> 'decimal',
			'numeric'	=> 'decimal',

			'boolean'	=> 'boolean',

			'date'		=> 'datetime',
			'time'		=> 'datetime',
			'datetime'	=> 'datetime',
			'timestamp'	=> 'datetime',
			'year'		=> 'datetime',

			'tinytext'	=> 'text',
			'text'		=> 'text',
			'longtext'	=> 'text',
			'mediumtext'=> 'text',
			'blob'		=> 'text',
			'varchar'	=> 'text',
			'char'		=> 'text',

			// Others like enum, polygon, etc...
		);

		$type = strtolower($type);

		if(empty($types[$type])) {
			throw new \Exception("Column type $type not supported");
		}

		return $types[$type];
	}

}
