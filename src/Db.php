<?php

/**
 * Copyright James PRO B.V.
 */

namespace JamesPro\JamesDb;

class Db extends \PDO
{
    private $error;
    public $sql;
    private $bind;
    private $errorCallbackFunction;
    private $errorMsgFormat;


    /**
     * @param $dsn
     * @param $user
     * @param $passwd
     */
    public function __construct($dsn, $user = "", $passwd = "")
    {
        $options = array(
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            PDO::ATTR_EMULATE_PREPARES => true,
        );

        try {
            parent::__construct($dsn . ';charset=utf8mb4;', $user, $passwd, $options);
            $this->run("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * @return void
     */
    private function debug()
    {
        if (!empty($this->errorCallbackFunction)) {
            $error = array("Error" => $this->error);
            if (!empty($this->sql)) {
                $error["SQL Statement"] = $this->sql;
            }
            if (!empty($this->bind)) {
                $error["Bind Parameters"] = trim(print_r($this->bind, true));
            }
            $backtrace = debug_backtrace();
            if (!empty($backtrace)) {
                foreach ($backtrace as $info) {
                    if ($info["file"] != __FILE__) {
                        $error["Backtrace"] = $info["file"] . " at line " . $info["line"];
                    }
                }
            }

            $msg = "";
            if ($this->errorMsgFormat == "html") {
                if (!empty($error["Bind Parameters"])) {
                    $error["Bind Parameters"] = "<pre>" . $error["Bind Parameters"] . "</pre>";
                }
                //$css = trim(file_get_contents(dirname(__FILE__) . "/error.css"));
                //$msg .= '<style type="text/css">' . "\n" . $css . "\n</style>";
                $msg .= '<div class="db-block"></div>';
                $msg .= "\n" . '<div class="db-error">' . "\n\t<h3>SQL Error</h3>";
                $msg .= '<div class="well well-small">';
                foreach ($error as $key => $val) {
                    if ($key == 'Backtrace') {
                        continue;
                    }
                    $msg .= "\n\t<label>" . $key . ":</label>" . $val . '<br>';
                }
                $msg .= '</div>';
                $msg .= '<p>Probeer het opnieuw. Blijft het probleem zich voordoen, neem dan contact op met <a href="mailto:support@jamespro.nl">support@jamespro.nl</a></p>';
                $msg .= '<hr>';
                $msg .= '<p class="text-right"><a href="#" onclick="window.location.reload(true);return false;" class="btn btn-primary">Pagina verversen</a>';
                $msg .= "\n\t</div>";
            } elseif ($this->errorMsgFormat == "text") {
                $msg .= "SQL Error\n" . str_repeat("-", 50);
                foreach ($error as $key => $val) {
                    $msg .= "\n\n$key:\n$val";
                }
            }
            $func = $this->errorCallbackFunction;
            $func($msg);
        }
    }

    /**
     * @param $table
     * @param $where
     * @param $bind
     * @return void
     */
    public function delete($table, $where, $bind = "")
    {
        $sql = "DELETE FROM " . $table . " WHERE " . $where . ";";
        $this->run($sql, $bind);
    }

    /**
     * @param $table
     * @param $info
     * @return array
     */
    private function filter($table, $info)
    {
        $driver = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver == 'sqlite') {
            $sql = "PRAGMA table_info('" . $table . "');";
            $key = "name";
        } elseif ($driver == 'mysql') {
            $sql = "DESCRIBE " . $table . ";";
            $key = "Field";
        } else {
            $sql = "SELECT column_name FROM information_schema.columns WHERE table_name = '" . $table . "';";
            $key = "column_name";
        }

        if (false !== ($list = $this->run($sql))) {
            $fields = array();
            foreach ($list as $record) {
                $fields[] = $record[$key];
            }
            return array_values(array_intersect($fields, array_keys($info)));
        }
        return array();
    }

    /**
     * @param $bind
     * @return array|mixed
     */
    private function cleanup($bind)
    {
        if (!is_array($bind)) {
            if (!empty($bind)) {
                $bind = array($bind);
            } else {
                $bind = array();
            }
        }
        return $bind;
    }

    /**
     * @param $table
     * @param $info
     * @return array|false|int|string|null
     */
    public function insert($table, $info)
    {
        $fields = $this->filter($table, $info);
        $sql = "INSERT INTO " . $table . " (`" . implode("`,`", $fields) . "`) VALUES (:" . implode(", :", $fields) . ");";
        $bind = array();
        foreach ($fields as $field) {
            $bind[":$field"] = $info[$field];
        }
        return $this->run($sql, $bind);
    }

    /**
     * @return void
     */
    public function stripEnters()
    {
        $this->sql = str_replace(array("\n", "\r", "\t", '    '), array('', '', '', ' '), $this->sql);
    }

    /**
     * @param $sql
     * @param $bind
     * @return array|false|int|string|void
     */
    public function run($sql, $bind = "")
    {
        $start = microtime();
        $this->sql = trim($sql);
        if (stripos($this->sql, 'select') === 0) {
            $this->stripEnters();
        }
        $this->bind = $this->cleanup($bind);
        $this->error = "";

        try {
            $pdostmt = $this->prepare($this->sql);
            if ($pdostmt->execute($this->bind) !== false) {
//                                echo '<!--'.$this->sql.' - time: '.(microtime()-$start).'-->'."\n";
                if (preg_match("/^(" . implode("|", array("select", "describe", "pragma", "show")) . ") /i", $this->sql)) {
                    return $pdostmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif (preg_match("/^(" . implode("|", array("delete", "update")) . ") /i", $this->sql)) {
                    return $pdostmt->rowCount();
                } elseif (preg_match("/^(" . implode("|", array("insert")) . ") /i", $this->sql)) {
                    return $this->lastInsertId();
                }
            }
        } catch (PDOException $e) {
            $this->error = $e->getMessage();

            if (DEBUG) {
                echo '<h1>SQL ERROR:</h1><pre>' . print_r($e, true) . '</pre>';
            }

            $this->debug();
            return false;
        }
    }

    /**
     * @param $table
     * @param $where
     * @param $bind
     * @param $fields
     * @param $order
     * @param $limit
     * @param $group
     * @return array|mixed
     */
    public function select($table, $where = "", $bind = "", $fields = "*", $order = "", $limit = "", $group = "")
    {
        $sql = "SELECT " . $fields . " FROM " . $table;
        if (!empty($where)) {
            $sql .= " WHERE " . $where;
        }
        if (!empty($group)) {
            $sql .= " GROUP BY " . $group;
        }
        if (!empty($order)) {
            $sql .= " ORDER BY " . $order;
        }
        if (!empty($limit)) {
            $sql .= " LIMIT " . $limit;
        }
        $sql .= ";";

        $value = $this->run($sql, $bind);
        $value = $this->checkRowsEncode($value);
        return !$value ? [] : $value;
    }

    /**
     * @param $str
     * @return mixed
     */
    public function convertEncoding($str = '')
    {
        return Encoding::fixUTF8($str, Encoding::ICONV_IGNORE);
    }

    /**
     * @param $rows
     * @return array|mixed
     */
    public function checkRowsEncode($rows = [])
    {
        if (isset($rows) && is_array($rows) && count($rows)) {
            foreach ($rows as $index => $row) {
                if (isset($row) && is_array($row) && count($row)) {
                    foreach ($row as $key => $item) {
                        if (is_string($item) && $item) {
                            $rows[$index][$key] = $this->convertEncoding($item);
                        }
                    }
                }
            }
        }
        return $rows;
    }

    /**
     * @param $table
     * @param $where
     * @param $bind
     * @param $fields
     * @param $order
     * @return false|mixed
     */
    public function selectSingle($table, $where = "", $bind = "", $fields = "*", $order = "")
    {
        $sql = "SELECT " . $fields . " FROM " . $table;
        if (!empty($where)) {
            $sql .= " WHERE " . $where;
        }
        if (!empty($order)) {
            $sql .= " ORDER BY " . $order;
        }
        $sql .= " LIMIT 1;";
        $result = $this->run($sql, $bind);
        $result = $this->checkRowsEncode($result);
        if ($result) {
            return $result[0];
        } else {
            return false;
        }
    }

    /**
     * @param $errorCallbackFunction
     * @param $errorMsgFormat
     * @return void
     */
    public function setErrorCallbackFunction($errorCallbackFunction, $errorMsgFormat = "html")
    {
        //Variable functions for won't work with language constructs such as echo and print, so these are replaced with print_r.
        if (in_array(strtolower($errorCallbackFunction), array("echo", "print"))) {
            $errorCallbackFunction = "print_r";
        } else {
            $errorCallbackFunction = "die";
        }
        if (function_exists($errorCallbackFunction)) {
            $this->errorCallbackFunction = $errorCallbackFunction;
            if (!in_array(strtolower($errorMsgFormat), array("html", "text"))) {
                $errorMsgFormat = "html";
            }
            $this->errorMsgFormat = $errorMsgFormat;
        }
    }

    /**
     * @param $table
     * @param $info
     * @param $where
     * @param $bind
     * @return array|false|int|string|null
     */
    public function update($table, $info, $where, $bind = "")
    {
        $fields = $this->filter($table, $info);
        $fieldSize = sizeof($fields);

        $sql = "UPDATE " . $table . " SET ";
        for ($f = 0; $f < $fieldSize; ++$f) {
            if ($f > 0) {
                $sql .= ", ";
            }
            $sql .= '`' . $fields[$f] . '`' . " = :update_" . $fields[$f];
        }
        $sql .= " WHERE " . $where . ";";

        $bind = $this->cleanup($bind);
        foreach ($fields as $field) {
            $bind[":update_$field"] = $info[$field];
        }
        return $this->run($sql, $bind);
    }
}
