<?php
/**
 * Copyright (C) 2019 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <contact@thirtybees.com>
 * @copyright 2019 thirty bees
 * @license   Open Software License (OSL 3.0)
 */
namespace CoreUpdater;
use \Db;
use \ObjectModel;
use \PrestaShopException;
use \PrestaShopDatabaseException;

if (!defined('_TB_VERSION_')) {
    exit;
}
/**
 * Class InformationSchemaBuilder
 *
 * This class is responsible for building DatabaseSchema object based on mysql information schema
 *
 * @since 1.1.0
 */
class InformationSchemaBuilder
{
    /**
     * @var int Database connection name to be used to query information schema
     */
    protected $dbInstance;

    /**
     * @var string database name
     */
    protected $database;

    /** @var DatabaseSchema */
    protected $schema;

    /**
     * InformationSchemaBuilder constructor.
     *
     * @param string $databaseName optional name of database to load schema for. If not provided, information about
     * current database will be returned
     * @param int $dbInstance
     */
    public function __construct($databaseName = null, $dbInstance = _PS_USE_SQL_SLAVE_)
    {
        $this->dbInstance = $dbInstance;
        if (! $databaseName) {
            $this->database = 'database()';
        } else {
            $this->database = pSQL($databaseName);
        }
    }

    /**
     * Builds DatabaseSchema object for database
     *
     * @param bool $force if true, then new schema will be build, otherwise cached version might be returned
     * @return DatabaseSchema
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function getSchema($force = false)
    {
        if ($force || !$this->schema) {
            $this->schema = new DatabaseSchema();
            $this->loadInformationSchema();
        }
        return $this->schema;
    }

    /**
     * Builds DatabaseSchema object
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function loadInformationSchema()
    {
        $connection = Db::getInstance($this->dbInstance);
        $this->loadTables($connection);
        $this->loadColumns($connection);
        $this->loadKeys($connection);
    }

    /**
     * Populates $this->schema with database tables
     *
     * @param Db $connection database connection
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function loadTables($connection)
    {
        $tables = $connection->executeS('
            SELECT t.TABLE_NAME, t.ENGINE, c.CHARACTER_SET_NAME, t.TABLE_COLLATION
            FROM information_schema.TABLES t
            LEFT JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY c ON (c.COLLATION_NAME = t.TABLE_COLLATION)
            WHERE t.TABLE_SCHEMA = ' . $this->database
        );
        foreach ($tables as $row) {
            $table = new TableSchema($row['TABLE_NAME']);
            $table->setEngine($row['ENGINE']);
            $table->setCharset(new DatabaseCharset($row['CHARACTER_SET_NAME'], $row['TABLE_COLLATION']));
            $this->schema->addTable($table);
        }
    }

    /**
     * Populates $this->schema with table columns
     *
     * @param Db $connection
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function loadColumns($connection)
    {
        $columns = $connection->executeS('
            SELECT *
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ' . $this->database
        );
        foreach ($columns as $row) {
            $columnName = $row['COLUMN_NAME'];
            $tableName = $row['TABLE_NAME'];
            $autoIncrement = strpos($row['EXTRA'], 'auto_increment') !== false;
            $isNullable = strtoupper($row['IS_NULLABLE']) === 'YES';
            $defaultValue = $row['COLUMN_DEFAULT'];
            if (is_null($defaultValue) && $isNullable) {
                $defaultValue = ObjectModel::DEFAULT_NULL;
            }
            $column = new ColumnSchema($columnName);;
            $column->setDataType($row['COLUMN_TYPE']);
            $column->setAutoIncrement($autoIncrement);
            $column->setNullable($isNullable);
            $column->setDefaultValue($defaultValue);
            $column->setCharset(new DatabaseCharset($row['CHARACTER_SET_NAME'], $row['COLLATION_NAME']));
            $this->schema->getTable($tableName)->addColumn($column);
        }
    }

    /**
     * Populates $this->schema with keys/indexes
     *
     * @param Db $connection
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function loadKeys($connection)
    {
        $keys = $connection->executeS('
            SELECT s.TABLE_NAME, s.INDEX_NAME, t.CONSTRAINT_TYPE, s.COLUMN_NAME, s.SUB_PART
            FROM information_schema.STATISTICS s
            LEFT JOIN information_schema.TABLE_CONSTRAINTS t ON (t.TABLE_SCHEMA = s.TABLE_SCHEMA AND t.TABLE_NAME = s.TABLE_NAME and t.CONSTRAINT_NAME = s.INDEX_NAME)
            WHERE s.TABLE_SCHEMA = ' . $this->database . '
            ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX'
        );
        foreach ($keys as $row) {
            $tableName = $row['TABLE_NAME'];
            $keyName = $row['INDEX_NAME'];
            $table = $this->schema->getTable($tableName);
            $key = $table->getKey($keyName);
            if (!$key) {
                $key = new TableKey($this->getKeyType($row['CONSTRAINT_TYPE']), $keyName);
                $table->addKey($key);
            }
            $key->addColumn($row['COLUMN_NAME'], $row['SUB_PART']);
        }
    }

    /**
     * Transforms mysql constraint type to TableKey constant
     *
     * @param string $constraintType database constraint type
     * @return int TableKey constant
     */
    protected function getKeyType($constraintType)
    {
        switch ($constraintType) {
            case 'PRIMARY KEY':
                return ObjectModel::PRIMARY_KEY;
            case 'UNIQUE':
                return ObjectModel::UNIQUE_KEY;
            case 'FOREIGN KEY':
                return ObjectModel::FOREIGN_KEY;
            default:
                return ObjectModel::KEY;
        }
    }
}