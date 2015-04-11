<?php


namespace khr\React\Mysql;

use React\Promise\Deferred;

class Pool {

    private $makeConnection;
    private $maxConnections;

    /** pool of all connections (both idle and busy) */
    private $pool = [];

    private $pool_i = 0;

    /** array of Deferred objects waiting to be resolved with connection */
    private $waiting = [];


    function __construct($makeConnection, $maxConnections = 100) {
        $this->makeConnection = $makeConnection;
        $this->maxConnections = $maxConnections;
    }


    function getConnection() {

        if (!empty($this->pool)) {
            $key = key($this->pool);
            $conn = $this->pool[$key];
            unset($this->pool[$key]);
            return \React\Promise\resolve($conn);
        }

        if ($this->pool_i >= $this->maxConnections) {
            $deferred = new Deferred();
            $this->waiting[] = $deferred;
            return $deferred->promise();
        }

        $conn = call_user_func($this->makeConnection);
        if ($conn !== false){
            $this->pool_i++;
        }

        return ($conn === false) ? \React\Promise\reject(new \Exception(mysqli_connect_error())) : \React\Promise\resolve($conn);
    }


    function free(\mysqli $conn)
    {
        if ($conn->errno != 2006) {
            $this->pool[] = $conn;
        } else {
            $this->pool_i--;
        }

        if (!empty($this->waiting)) {
            $key = key($this->waiting);
            $deferred = $this->waiting[$key];
            unset($this->waiting[$key]);
            $this->getConnection()->done(function($conn) use($deferred){
                $deferred->resolve($conn);
            },[$deferred, 'reject']);
        }
    }
}