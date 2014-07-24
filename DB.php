<?php

class DB
{
    static protected $pdo;
	static protected $db;
    static protected $host;
    static protected $name;
    static protected $user;
    static protected $pass;
    static protected $lastQuery;
	static protected $kipDebugLogMethods = array();

    const ONDUPLICATE_IGNORE = 'IGNORE';
    const ONDUPLICATE_UPDATE = 'UPDATE';

    public function __construct($host, $name, $user = false, $pass = false, $options = array())
    {
        self::$host = $host;
        self::$name = $name;
        self::$user = $user;
        self::$pass = $pass;
        if(defined('SKIP_DBLOGS_METHODS'))
            @self::$kipDebugLogMethods = unserialize(SKIP_DBLOGS_METHODS);

        if (!extension_loaded('pdo'))
		{
            throw new PDOException(__CLASS__ . ': The PDO extension is required for this class');
        }

		try
		{
			$dsn       = sprintf('mysql:host=%s;dbname=%s', $host, $name);
			self::$pdo = new PDO($dsn, $user, $pass, $options);
			self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			self::$pdo->exec("SET NAMES 'utf8'");
		}
		catch( Exception $e )
		{
			throw new Exception($e->getMessage());
		}
    }

    public static function init($host,$dbname,$user,$password)
    {
        self::$db = new self($host, $dbname, $user, $password);
    }

	public static function beginTransaction()
	{
		return self::$pdo->beginTransaction();
	}

	public static function commit()
	{
		self::$pdo->commit();
	}

	public static function rollBack()
	{
		self::$pdo->rollBack();
	}

    public static function fetch($table, array $params = null, $limit = false, $order = false, $fetchColumn = false, $fetchMode = PDO::FETCH_ASSOC)
    {
        if($fetchColumn)
            $query = "SELECT $fetchColumn FROM " . $table . "";
        else
            $query = "SELECT * FROM " . $table . "";

        $values = array();
        if (!empty($params))
        {
            $sqlparts = array();
            foreach ($params as $field => $value)
            {
                $sqlparts[] = "`" . $field . "` = ?";
                $values[] = $value;
            }
            $wheres = implode(" AND ", $sqlparts);
        }

        if (isset($wheres))
            $query .= " WHERE " . $wheres . "";

        if (!empty($limit))
            $query .= " LIMIT " . $limit;

        if (!empty($order))
            $query .= " ORDER BY " . $order;

		if( $fetchColumn )
			$returnMethod = "fetchColumn";
		else
			$returnMethod = "fetchAll";

		return self::query($query, $values, $returnMethod, $fetchMode);
    }

    public static function fetchQuery($query, array $values = null, $oneRow = false)
    {
		if( $oneRow )
			$returnMethod = "fetch";
		else
			$returnMethod = "fetchAll";

		return self::query($query, $values, $returnMethod);
    }

    public static function fetchOne($table, array $params = null, $fetchMode = PDO::FETCH_ASSOC)
    {
		$results = self::fetch($table, $params, 1, false, false, $fetchMode);
        if(!$results)
            return false;

        return $results[0];
    }

    public static function store($table, array $input, $onduplicate = false)
    {
        if(empty($input))
            return false;

        $query = "INSERT ". ($onduplicate == self::ONDUPLICATE_IGNORE ? self::ONDUPLICATE_IGNORE." " : "" ). "INTO " . $table . " SET ";

        $sqlparts = array();
        foreach ($input as $field => $value)
        {
            $sqlparts[] = "`". $field . "` = ?";
            $values[] = $value;
        }
        $sets = implode(", ", $sqlparts);
        $query .= $sets;

        if($onduplicate == self::ONDUPLICATE_UPDATE)
        {
            $query .= " ON DUPLICATE KEY UPDATE ". $sets;
            $values = array_merge($values, $values);
        }

		return self::query($query, $values, "lastInsertId");
    }

    public static function update($table, array $input, array $where_params = null)
    {
        if (empty($input))
            return false;

        $query = "UPDATE " .$table . " SET ";

        //set fields
        $sqlparts = array();
        foreach ($input as $field => $value)
        {
            $sqlparts[] = "`" . $field . "` = ?";
            $values[] = $value;
        }
        $sets = implode(", ", $sqlparts);
        $query .= $sets;

        //set wheres
        if (!empty($where_params))
        {
            $sqlparts = array();
            foreach ($where_params as $field => $value)
            {
                $sqlparts[] = "`" . $field . "` = ?";
                $values[] = $value;
            }
            $wheres = implode(" AND ", $sqlparts);
        }

        if (isset($wheres))
		{
			$query .= " WHERE " . $wheres . "";
		}

		return self::query($query, $values, 'rowCount');
    }

    public static function delete($table, array $params = null)
    {
        $query = "DELETE FROM " . $table . "";
        $values = array();
        if (!empty($params))
        {
            $values = array();
			$sqlparts = array();
            foreach ($params as $field => $value)
            {
                $sqlparts[] = "`".$field ."` = ?";
				$values[] = $value;
            }
            $wheres = implode(" AND ", $sqlparts);
        }

        if (isset($wheres))
            $query .= " WHERE " . $wheres . "";

		return self::query($query, $values, "rowCount");
    }

    public static function fetchColumn($table, $fetchColumn, $params, $fetchMode = PDO::FETCH_COLUMN)
    {
        return self::fetch($table, $params, 1, false, $fetchColumn, $fetchMode);
    }

	/**
	 * @param       $query
	 * @param array $values
	 * @param string $returnMethod fetch,fetchAll,fetchColumn,rowCount,lastInsertId
	 * @param string $fetchMode PDO::* (PDO::FETCH_OBJ, PDO::FETCH_PROPS_LATE)
	 * @param integer $attempts
	 *
	 * @return bool
	 * @throws Exception
	 */

    /*
     * $returnMethod = "fetch" | "fetchAll" | "lastInsertId"
     * $fetchMode = PDO::FETCH_ASSOC | PDO::FETCH_OBJ | PDO::FETCH_COLUMN
     */
	public static function query($query, array $values = null, $returnMethod = false, $fetchMode = PDO::FETCH_ASSOC, $attempts = 1, $skipLog = false)
	{
		//debug logging
		$caller = self::getCallerName();
		if(!in_array($caller, self::$kipDebugLogMethods) && !$skipLog)
		{
			if(class_exists('IFR_Log', true))
            {
                IFR_Log::log("[" . $caller . "][query: " . self::pdoDebug($query, $values) . "][$returnMethod $fetchMode]", IFR_Log::LEVEL_DEBUG);
            }
		}

		while( $attempts>0 )
		{
			try
			{
				$stmt = self::prepare($query);
				if( in_array($returnMethod,array("fetch","fetchAll")) && $fetchMode != PDO::FETCH_COLUMN)
				{
					$stmt->setFetchMode($fetchMode);
				}
                self::addQuery(self::pdoDebug($query, $values));
				$stmt->execute($values);

				if( $returnMethod=="lastInsertId")
				{
					return self::lastInsertId();
				}
                elseif($returnMethod == "rowCount")
                {
                    return $stmt->rowCount();
                }
				elseif( $returnMethod && $returnMethod!="lastInsertId")
                {
                    return $stmt->{$returnMethod}();
                }
                elseif($fetchMode == PDO::FETCH_COLUMN)
                {
                    return $stmt->{$returnMethod}(PDO::FETCH_COLUMN);
                }
				else
					return true;
			}
			catch( Exception $e )
			{
				sleep(1);
				$attempts--;
				if( $attempts==0 )
				{
                    if (class_exists('IFR_Log', true))
                    {
                        IFR_Log::log($e->getMessage() . "[" . $caller . "][query: " . self::pdoDebug($query, $values) . "]", IFR_Log::LEVEL_ERROR);
                    }
                    throw New Exception ($e->getMessage());
				}
			}
		}
	}

	public static function quote($input, $paramType = 0)
	{
		return self::$pdo->quote($input, $paramType);
	}

    public static function getCallerName()
    {
        $callers = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $classMethods = get_class_methods('DB');
//        $exceptMethods = array_diff($classMethods, array('updateEntity', 'store'));

        $key = 1;
        do {
            if (isset($callers[$key])) {
                $caller = $callers[$key]['function'];
            } else {
                $lastTrack = end($callers);
                $caller = $lastTrack['function'];

                if (in_array($caller, $classMethods))
                    $caller = $lastTrack['file'];
                break;
            }
            $key++;
        } while (in_array($caller, $classMethods));
        return $caller;
    }

	public static function prepare($sql)
	{
		return self::$pdo->prepare($sql);
	}

	public function exec($stm)
	{
		return self::$pdo->exec($stm);
	}

	public function setAttribute($attribute, $value)
	{
		return self::$pdo->setAttribute($attribute, $value);
	}

	public function getAttribute($attribute)
	{
		return self::$pdo->getAttribute($attribute);
	}

	public static function lastInsertId($name = null)
	{
		return self::$pdo->lastInsertId($name);
	}

	public static function addQuery($string)
	{
		self::$lastQuery = $string;
	}

    public static function getLastQuery()
    {
        return self::$lastQuery;
    }

	public function getQueries()
	{
		return $this->queries;
	}

	public function getPDO()
	{
		return self::$pdo;
	}

    public static function ping()
    {
        try {
            self::$pdo->query('SELECT 1');
        } catch (PDOException $e) {
            self::init(self::$host, self::$name, self::$user, self::$pass); // Don't catch exception here, so that re-connect fail will throw exception
        }
        return true;
    }

    public static function pdoDebug($query, $placeholders)
    {
        if (!empty($placeholders))
        {
            foreach ($placeholders as $key => $value) {
                if (!get_magic_quotes_gpc())
                    $placeholders[$key] = addslashes($value);
            }
            $query = str_replace("?", "'%s'", $query);
            $query = vsprintf($query, $placeholders);
        }
        return $query;
    }

    public static function close()
    {
        self::$pdo = null;
    }
}
