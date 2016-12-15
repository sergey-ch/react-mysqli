<?php

namespace React\MySQLi;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;
use React\Promise\Deferred;
use \mysqli;
use \mysqli_result;
use \Exception;

class Client
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var Pool
     */
    private $pool;

    /**
     * @var TimerInterface
     */
    private $timer;

    /**
     * @var Deferred[]
     */
    private $deferred = [];

    /**
     * @var mysqli[]
     */
    private $conn = [];

    /**
     * @var string[]
     */
    private $query = [];


    /**
     * @param LoopInterface $loop
     * @param Pool $connectionPool Pool either connection pool or function that makes connection
     */
    public function __construct(LoopInterface $loop, Pool $connectionPool)
    {
        $this->loop = $loop;
        $this->pool = $connectionPool;
    }


    /**
     * @param string
     * @return \React\Promise\PromiseInterface
     */
    public function query($query, array $params = [])
    {
        return $this->pool->getConnection()->then(function (\mysqli $conn) use ($query, $params) {
            $query = $this->escape($conn, $query, $params);

            $status = $conn->query($query, MYSQLI_ASYNC);
            if ($status === false) {
                $this->pool->free($conn);
                throw new Exception($conn->error);
            }

            $id = spl_object_hash($conn);
            $this->conn[$id] = $conn;
            $this->query[$id] = $query;
            $this->deferred[$id] = $deferred = new Deferred();

            if (!isset($this->timer)) {
                $this->timer = $this->loop->addPeriodicTimer(0.001, function () {

                    $links = $errors = $reject = $this->conn;
                    mysqli_poll($links, $errors, $reject, 0); // don't wait, just check

                    $each = array('links' => $links, 'errors' => $errors, 'reject' => $reject) ;
                    foreach ($each as $type => $connects) {
                        /**
                         * @var mysqli $conn
                         */
                        foreach ($connects as $conn) {
                            $id = spl_object_hash($conn);

                            if (isset($this->conn[$id])) {
                                $deferred = $this->deferred[$id];
                                if ($type == 'links') {
                                    /**
                                     * @var mysqli_result $result
                                     */
                                    $result = $conn->reap_async_query();
                                    if ($result === false) {
                                        $deferred->reject(new Exception($conn->error . '; sql: ' . $this->query[$id]));
                                    } else {
                                        $deferred->resolve(new Result($result, $conn->insert_id, $conn->affected_rows));
                                    }
                                }

                                if ($type == 'errors') {
                                    $deferred->reject(new Exception($conn->error . '; sql: ' . $this->query[$id]));
                                }

                                if ($type == 'reject') {
                                    $deferred->reject(new Exception('Query was rejected; sql: ' . $this->query[$id]));
                                }

                                unset($this->deferred[$id], $this->conn[$id], $this->query[$id]);
                                $this->pool->free($conn);
                            }
                        }
                    }

                    if (empty($this->conn)) {
                        $this->timer->cancel();
                        $this->timer = null;
                    }
                });
            }

            return $deferred->promise();
        });
    }

    /**
     * Escape SQL query parameters such as :foo => "bar"
     * @param mysqli $conn
     * @param $query
     * @param array $params
     *
     * @return mixed
     */
    public function escape(\mysqli $conn, $query, array $params = []) {
        $filtered_params = array_map(function ($value) use ($conn) {
            return '"' . $conn->real_escape_string($value) . '"';
        }, $params);

        return str_replace(array_keys($filtered_params), array_values($filtered_params), $query);

    }
}
