<?php
/**
 * Pop PHP Framework (https://www.popphp.org/)
 *
 * @link       https://github.com/popphp/popphp-framework
 * @author     Nick Sagona, III <dev@noladev.com>
 * @copyright  Copyright (c) 2009-2025 NOLA Interactive, LLC.
 * @license    https://www.popphp.org/license     New BSD License
 */

/**
 * @namespace
 */
namespace Pop\Db\Adapter;

use Pop\Http\Client;
use Pop\Http\Client\Handler\Curl;
use Pop\Http\Client\Request;

/**
 * SQLite database adapter class
 *
 * @category   Pop
 * @package    Pop\Db
 * @author     Nick Sagona, III <dev@noladev.com>
 * @copyright  Copyright (c) 2009-2025 NOLA Interactive, LLC.
 * @license    https://www.popphp.org/license     New BSD License
 * @version    6.6.5
 */
class Rqlite extends AbstractAdapter
{

    /**
     * SQLite flags
     * @var ?int
     */
    protected ?int $flags = null;

    /**
     * SQLite key
     * @var ?string
     */
    protected ?string $key = null;

    /**
     * Last SQL query
     * @var ?string
     */
    protected ?string $lastSql = null;

    /**
     * Last result
     * @var mixed
     */
    protected mixed $lastResult = null;

    /**
     * Enable transactions
     * @var bool
     */
    private bool $useTrx = false;

    /**
     * List of SQLs
     * @var array
     */
    private mixed $sql = [];

    /**
     * Bind parameters
     * @var array
     */
    private array $params = [];

    /**
     * Constructor
     *
     * Instantiate the SQLite database connection object using SQLite3
     *
     * @param  array $options
     */
    public function __construct(array $options = [])
    {
        if (!empty($options)) {
            $this->connect($options);
        }
    }

    /**
     * Connect to the database
     *
     * @param  array $options
     * @return Sqlite
     */
    public function connect(array $options = []): Rqlite
    {
        if (!empty($options))
            $this->setOptions($options);

        $this->connection = $this->getClient();

        return $this;
    }

    protected function getClient(){

        $curl = new Curl();
        $curl->setOptions([
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);

        $config = array(
            "host"=>$this->options["url"]??"http://localhost:4001",
            "qstring"=>"pretty&timings"
        );

        return new class($curl, $config){

            private $curl;
            private $config;
            private $options;
            private $response;

            public function __construct(Curl $curl, array $config){

                $this->curl = $curl;
                $this->config = $config;
                $this->options = [
                    "method"=>"POST",
                    "type"=>Request::JSON
                ];
            }

            private function toString(mixed $sql){

                if(is_string($sql)){

                    $sql = sprintf("[\"%s\"]", $sql);
                }
                elseif(is_array($sql)){

                    $temp = array_shift($sql);
                    $params = array_shift($sql);
                    $sql = $temp;

                    $sql = sprintf("[[\"%s\", %s]]", $sql, json_encode($params));
                }

                return $sql;
            }

            public function query(mixed $sql){

                $this->options["data"] = $this->toString($sql);

                $uri = sprintf("%s/db/query?%s", $this->config["host"], $this->config["qstring"]);

                $client = new Client($uri, $this->options);
                $this->response = $client->send();

                return $this->format($this->response->json());
            }

            public function execute(mixed $sql){

                $this->options["data"] = $this->toString($sql);

                $uri = sprintf("%s/db/execute?%s", $this->config["host"], $this->config["qstring"]);

                $client = new Client($uri, $this->options);
                $this->response = $client->send();

                return $this->format($this->response->json());
            }

            public function getResponse(){

                return $this->response;
            }

            public function format(array $result){

                $keys = $result["results"][0]["columns"];
                $values = $result["results"][0]["values"];

                $rows = [];
                if(count($values) == count($values, COUNT_RECURSIVE)) //not_multidimentional
                    return array_combine($keys, $values[0]);

                foreach($values as $row)
                    $rows[] = array_combine($keys, $row);

                return $rows;
            }
        };
    }

    /**
     * Set database connection options
     *
     * @param  array $options
     * @return Sqlite
     */
    public function setOptions(array $options): Rqlite
    {
        $this->options = $options;

        if (!$this->hasOptions())
            $this->options["url"] = 'http://localhost:4001';

        return $this;
    }

    /**
     * Has database connection options
     *
     * @return bool
     */
    public function hasOptions(): bool
    {
        return (isset($this->options['url']));
    }

    /**
     * Does the database file exist
     *
     * @return bool
     */
    public function dbFileExists(): bool
    {
        return (isset($this->options['database']) && file_exists($this->options['database']));
    }

    /**
     * Begin a transaction
     *
     * @return Sqlite
     */
    public function beginTransaction(): Rqlite
    {
        $this->useTrx = true;

        return $this;
    }

    /**
     * Commit a transaction

     *
     * @return Sqlite
     */
    public function commit(): Rqlite
    {
        $this->useTrx = false;

        return $this;
    }

    /**
     * Rollback a transaction
     *
     * @return Sqlite
     */
    public function rollback(): Rqlite
    {
        $this->useTrx = false;

        return $this;
    }

    /**
     * Check if transaction is success
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return ((($this->result !== null) && ($this->result !== false)) && (!$this->hasError()));
    }

    /**
     * Execute a SQL query directly
     *
     * @param  mixed $sql
     * @return Sqlite
     */
    public function query(mixed $sql): Rqlite
    {
        $this->result = $this->connection->query($sql);
    
        return $this;
    }

    /**
     * Prepare a SQL query
     *
     * @param  mixed $sql
     * @return Sqlite
     */
    public function prepare(mixed $sql): Rqlite
    {
        $this->sql = $sql;

        return $this;
    }

    /**
     * Bind parameters to a prepared SQL query
     *
     * @param  array $params
     * @return Sqlite
     */
    public function bindParams(array $params): Rqlite
    {
        foreach($params as $param=>$value)
            $this->params[$param] = $value;

        return $this;
    }

    /**
     * Bind a parameter for a prepared SQL query
     *
     * @param  mixed $param
     * @param  mixed $value
     * @param  int   $type
     * @return Sqlite
     */
    public function bindParam(mixed $param, mixed $value, int $type = SQLITE3_BLOB): Rqlite
    {
        $this->params[$param] = $value;

        return $this;
    }

    /**
     * Bind a value for a prepared SQL query
     *
     * @param  mixed $param
     * @param  mixed $value
     * @param  int   $type
     * @return Sqlite
     */
    public function bindValue(mixed $param, mixed $value, int $type = SQLITE3_BLOB): Rqlite
    {
        $this->params[$param] = $value;

        return $this;
    }

    /**
     * Execute a prepared SQL query
     *
     * @return Sqlite
     */
    public function execute(): Rqlite
    {
        foreach($this->params as $param=>$value)
            $this->sql = str_replace($value, $this->escape($value), $this->sql);

        $this->result = $this->connection->query($this->sql);

        return $this;
    }

    /**
     * Fetch and return a row from the result
     *
     * @return mixed
     */
    public function fetch(): mixed
    {
        if ($this->result === null) {
            $this->throwError('Error: The database result resource is not currently set.');
        }

        $row = array_pop($this->result);

        return $row;
    }

    /**
     * Fetch and return all rows from the result
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return $this->result;
    }

    /**
     * Disconnect from the database
     *
     * @return void
     */
    public function disconnect(): void
    {
        //
    }

    /**
     * Escape the value
     *
     * @param  ?string $value
     * @return string
     */
    public function escape(?string $value = null): string
    {
        return sprintf("'%s'", $value);
    }

    /**
     * Return the last ID of the last query
     *
     * @return int
     */
    public function getLastId(): int
    {
        $result = $this->connection->query("SELECT last_insert_rowid() as last_id");

        return $result;
    }

    /**
     * Return the number of rows from the last query
     *
     * @throws Exception
     * @return int
     */
    public function getNumberOfRows(): int
    {
        $count = 0;

        return $count;
    }

    /**
     * Return the number of affected rows from the last query
     *
     * @return int
     */
    public function getNumberOfAffectedRows(): int
    {
        $result = $this->connection->query("PRAGMA count_changes;");

        return $result;
    }

    /**
     * Return the database version
     *
     * @return string
     */
    public function getVersion(): string
    {
        $result = $this->connection->query("SELECT sqlite_version() as version;");

        return $result;
    }

    /**
     * Return the tables in the database
     *
     * @return array
     */
    public function getTables(): array
    {
        $tables = [];
        $sql    = "SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' " .
            "UNION ALL SELECT name FROM sqlite_temp_master WHERE type IN ('table', 'view') ORDER BY 1";

        $this->query($sql);

        foreach($this->result as $row)
            $tables[] = $row["name"];

        return $tables;
    }

}