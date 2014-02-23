<?php

namespace LeanMapperQuery;

use LeanMapper\Reflection\Property;
use LeanMapper\Relationship;
use LeanMapper\IMapper;
use LeanMapper\Fluent;
use LeanMapper\ImplicitFilters;
use LeanMapper\Entity;

use LeanMapperQuery\Exception\InvalidArgumentException;
use LeanMapperQuery\Exception\InvalidRelationshipException;
use LeanMapperQuery\Exception\InvalidStateException;
use LeanMapperQuery\Exception\MemberAccessException;

class Query implements IQuery
{
	private static $defaultPlaceholder = '?';

	private static $placeholders = array(
		'string' => '%s',
		'boolean' => '%b',
		'integer' => '%i',
		'float' => '%f',
		'Datetime' => '%t',
		'Date' => '%d',
	);


	/** @var IQueryable */
	protected $sourceRepository;

	/** @var IMapper */
	protected $mapper;

	/** @var Fluent */
	protected $fluent;

	/** @var array */
	protected $appliedJoins = array();


	public function __construct(IQueryable $sourceRepository, IMapper $mapper)
	{
		$this->sourceRepository = $sourceRepository;
		$this->mapper = $mapper;
		$this->fluent = $sourceRepository->createFluent();
	}

	private	 function getPropertiesByTable($tableName)
	{
		$entityClass = $this->mapper->getEntityClass($tableName);
		$reflection = $entityClass::getReflection($this->mapper);
		$properties = array();
		foreach ($reflection->getEntityProperties() as $property) {
			$properties[$property->getName()] = $property;
		}
		return array($entityClass, $properties);
	}

	private function getTableAlias($currentTable, $targetTable, $viaColumn)
	{
		// Tables can be joined via different columns from the same table,
		// or from different tables via column with the same name.
		$localKey = $targetTable . '_' . $viaColumn;
		$globalKey = $currentTable . '_' . $localKey;
		if (array_key_exists($globalKey, $this->appliedJoins)) {
			return array(TRUE, $this->appliedJoins[$globalKey]);
		}
		// Find the tiniest unique table alias.
		if (!in_array($targetTable, $this->appliedJoins)) {
			$value = $targetTable;
		} elseif (!in_array($localKey, $this->appliedJoins)) {
			$value = $localKey;
		} else {
			$value = $globalKey;
		}
		$this->appliedJoins[$globalKey] = $value;
		return array(FALSE, $value);
	}

	private function joinRelatedTable($currentTable, $referencingColumn, $targetTable, $targetTablePrimaryKey, $filters = array())
	{
		list($alreadyJoined, $alias) = $this->getTableAlias($currentTable, $targetTable, $referencingColumn);
		// Join if not already joined.
		if (!$alreadyJoined) {
			if (empty($filters)) {
				// Do simple join.
				$this->fluent->leftJoin("[$targetTable]" . ($targetTable !== $alias ? " [$alias]" : ''))
					->on("[$currentTable].[$referencingColumn] = [$alias].[$targetTablePrimaryKey]");
			} else {
				// Join sub-query due to applying implicit filters.
				$subFluent = new Fluent($this->fluent->getConnection());
				$subFluent->select('%n.*', $targetTable)->from($targetTable);

				// Apply implicit filters.
				$targetedArgs = array();
				if ($filters instanceof ImplicitFilters) {
					$targetedArgs = $filters->getTargetedArgs();
					$filters = $filters->getFilters();
				}
				foreach ($filters as $filter) {
					$args = array($filter);
					if (is_string($filter) && array_key_exists($filter, $targetedArgs)) {
						$args = array_merge($args, $targetedArgs[$filter]);
					}
					call_user_func_array(array($subFluent, 'applyFilter'), $args);
				}
				$this->fluent->leftJoin($subFluent, "[$alias]")->on("[$currentTable].[$referencingColumn] = [$alias].[$targetTablePrimaryKey]");
			}
			$this->appliedJoins[] = $alias;
		}
		return $alias;
	}

	private function traverseToRelatedEntity($currentTable, $currentTableAlias, Property $property)
	{
		if (!$property->hasRelationship()) {
			throw new InvalidRelationshipException("Property '$propertyName' in entity '$entityClass' doesn't have any relationship.");
		}
		$implicitFilters= array();
		$propertyType = $property->getType();
		if (is_subclass_of($propertyType, 'LeanMapper\\Entity')) {
			$caller = new Caller($this, $property);
			$implicitFilters = $this->mapper->getImplicitFilters($property->getType(), $caller);
		}

		$relationship = $property->getRelationship();
		if ($relationship instanceof Relationship\HasOne) {
			$targetTable = $relationship->getTargetTable();
			$targetTablePrimaryKey = $this->mapper->getPrimaryKey($targetTable);
			$referencingColumn = $relationship->getColumnReferencingTargetTable();
			// Join table.
			$targetTableAlias = $this->joinRelatedTable($currentTableAlias, $referencingColumn, $targetTable, $targetTablePrimaryKey, $implicitFilters);

		} elseif ($relationship instanceof Relationship\BelongsTo) { // BelongsToOne, BelongsToMany
			// TODO: Involve getStrategy()?
			$targetTable = $relationship->getTargetTable();
			$sourceTablePrimaryKey = $this->mapper->getPrimaryKey($currentTable);
			$referencingColumn = $relationship->getColumnReferencingSourceTable();
			// Join table.
			$targetTableAlias = $this->joinRelatedTable($currentTableAlias, $sourceTablePrimaryKey, $targetTable, $referencingColumn, $implicitFilters);

		} elseif ($relationship instanceof Relationship\HasMany) {
			// TODO: Involve getStrategy()?
			$sourceTablePrimaryKey = $this->mapper->getPrimaryKey($currentTable);
			$relationshipTable = $relationship->getRelationshipTable();
			$sourceReferencingColumn = $relationship->getColumnReferencingSourceTable();

			$targetReferencingColumn = $relationship->getColumnReferencingTargetTable();
			$targetTable = $relationship->getTargetTable();
			$targetTablePrimaryKey = $this->mapper->getPrimaryKey($targetTable);
			// Join tables.
			// Don't apply filters on relationship table.
			$relationshipTableAlias = $this->joinRelatedTable($currentTableAlias, $sourceTablePrimaryKey, $relationshipTable, $sourceReferencingColumn);
			$targetTableAlias = $this->joinRelatedTable($relationshipTableAlias, $targetReferencingColumn, $targetTable, $targetTablePrimaryKey, $implicitFilters);

		} else {
			throw new InvalidRelationshipException('Unknown relationship type. ' . get_class($relationship) . ' given.');
		}

		return array_merge(array($targetTable, $targetTableAlias), $this->getPropertiesByTable($targetTable));
	}

	private function replacePlaceholder(Property $property)
	{
		$type = $property->getType();
		if ($property->isBasicType()) {
			if (array_key_exists($type, self::$placeholders)) {
				return self::$placeholders[$type];
			} else {
				return self::$defaultPlaceholder;
			}
		} else {
			if ($type === 'Datetime' || is_subclass_of($type, 'Datetime')) {
				if ($property->hasCustomFlag('type')) {
					$type = $property->getCustomFlagValue('type');
					if (preg_match('#^(DATE|Date|date)$#', $type)) {
						return self::$placeholders['Date'];
					} else {
						return self::$placeholders['Datetime'];
					}
				} else {
					return self::$placeholders['Datetime'];
				}
			} else {
				return self::$defaultPlaceholder;
			}
		}
	}

	private function parseStatement($statement, $replacePlaceholders = FALSE)
	{
		if (!is_string($statement)) {
			throw new InvalidArgumentException('Type of argument $statement is expected to be string. ' . gettype($statement) . ' given.');
		}
		$rootTableName = $this->sourceRepository->getTable();
		list($rootEntityClass, $rootProperties) = $this->getPropertiesByTable($rootTableName);

		$switches = array(
			'@' => FALSE,
			'"' => FALSE,
			"'" => FALSE,
		);
		$output = '';
		$property = NULL;
		for ($i = 0; $i < strlen($statement) + 1; $i++) {
			// Do one more loop due to succesfuly translating
			// properties attached to the end of the statement.
			$ch = @$statement{$i} ?: '';
			if ($switches['@'] === TRUE) {
				if (preg_match('#^[a-zA-Z_]$#', $ch)) {
					$propertyName .= $ch;
				} else {
					if (!array_key_exists($propertyName, $properties)) {
						throw new MemberAccessException("Entity '$entityClass' doesn't have property '$propertyName'.");
					}
					$property = $properties[$propertyName];

					if ($ch === '.') {
						list($tableName, $tableNameAlias, $entityClass, $properties) = $this->traverseToRelatedEntity($tableName, $tableNameAlias, $property);
						$propertyName = '';
					} else {
						if ($property->getColumn() === NULL)
						{
							// If the last property also has relationship replace with primary key field value.
							if ($property->hasRelationship()) {
								list($tableName, , , $properties) = $this->traverseToRelatedEntity($tableName, $tableNameAlias, $property);
								$column = $this->mapper->getPrimaryKey($tableName);
								$property = $properties[$column];
							} else {
								throw new InvalidStateException("Column not specified in property '$propertyName' of entity '$entityClass'");
							}
						} else {
							$column = $property->getColumn();
						}
						$output .= "[$tableNameAlias].[$column]";
						$switches['@'] = FALSE;
						$output .= $ch;
					}
				}
			} elseif ($ch === '@' && $switches["'"] === FALSE && $switches['"'] === FALSE) {
				$switches['@'] = TRUE;
				$propertyName = '';
				$properties = $rootProperties;
				$tableNameAlias = $tableName = $rootTableName;
				$entityClass = $rootEntityClass;

			} elseif ($replacePlaceholders && $ch === self::$defaultPlaceholder && $switches["'"] === FALSE && $switches['"'] === FALSE) {
				if ($property === NULL) {
					$output .= $ch;
				} else {
					// Dumb replacing placeholder.
					// NOTE: Placeholders are replaced by the type of last found property.
					// 	It is stupid as it doesn't work for all kinds of SQL statements.
					$output .= $this->replacePlaceholder($property);
				}
			} else {
				if ($ch === '"' && $switches["'"] === FALSE) {
					$switches['"'] = !$switches['"'];
				} elseif ($ch === "'" && $switches['"'] === FALSE) {
					$switches["'"] = !$switches["'"];
				}
				$output .= $ch;
			}
		}
		return $output;
	}

	public function createQuery()
	{
		return $this->fluent->fetchAll();
	}

	protected function processToFluent($method, array $args = array())
	{
		call_user_func_array(array($this->fluent, $method),	$args);
	}

	public function where($cond)
	{
		if (is_array($cond)) {
			if (func_num_args() > 1) {
				throw new InvalidArgumentException('Number of arguments is limited to 1 if the first argument is array.');
			}
			foreach ($cond as $key => $value) {
				if (is_string($key)) {
					// TODO: use preg_match?
					$this->where($key, $value);
				} else {
					$this->where($value);
				}
			}
		} else {
			$replacePlaceholders = FALSE;
			$args = func_get_args();
			$operators = array('=', '<>', '!=', '<=>', '<', '<=', '>', '>=');
			if (count($args) === 2
				&& preg_match('#^\s*(@[a-zA-Z_.]+)\s*(|'.implode('|', $operators).')\s*$#', $args[0], $matches)) {
				$replacePlaceholders = TRUE;
				$field = &$args[0];
				list(, $field, $operator) = $matches;
				$value = $args[1];

				$placeholder = self::$defaultPlaceholder;
				if (!$operator) {
					if (is_array($value)) {
						$operator = 'IN';
						$placeholder = '%in';
					} else {
						$operator = '=';
					}
				}
				$field .= " $operator $placeholder";
			}
			// Only first argument is parsed. Other arguments will be maintained
			// as parameters.
			$statement = &$args[0];
			$statement = $this->parseStatement($statement, $replacePlaceholders);
			$statement = "($statement)";
			// Replace instances of Entity for its values.
			foreach ($args as &$arg) {
				if ($arg instanceof Entity) {
					$entityTable = $this->mapper->getTable(get_class($arg));
					$idField = $this->mapper->getEntityField($entityTable, $this->mapper->getPrimaryKey($entityTable));
					$arg = $arg->$idField;
				}
			}
			$this->processToFluent('where', $args);
		}
		return $this;
	}

	public function orderBy($field)
	{
		if (is_array($field)) {
			foreach ($field as $key => $value) {
				if (is_string($key)) {
					$this->orderBy($key)->asc($value);
				} else {
					$this->orderBy($value);
				}
			}
		} else {
			$field = $this->parseStatement($field);
			$this->processToFluent('orderBy', array($field));
		}
		return $this;
	}

	public function asc($asc = TRUE)
	{
		if ($asc) {
			$this->processToFluent('asc');
		} else {
			$this->processToFluent('desc');
		}
		return $this;
	}

	public function desc($desc = TRUE)
	{
		$this->asc(!$desc);
		return $this;
	}

	public function limit($limit)
	{
		$this->processToFluent('limit', array($limit));
		return $this;
	}

	public function offset($offset)
	{
		$this->processToFluent('offset', array($offset));
		return $this;
	}

}
