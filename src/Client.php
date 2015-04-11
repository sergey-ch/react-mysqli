<?php

namespace KHR\React\Mysql;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;
use React\Promise\Deferred;
use \mysqli;
use \mysqli_result;
use \Exception;

class Client {

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
     * @param LoopInterface
     * @param $connectionPool Pool either connection pool or function that makes connection
     */
    function __construct(LoopInterface $loop, $connectionPool) {
        $this->loop = $loop;
        $this->pool = $connectionPool;
    }


    /**
     * @param string
     * @return \React\Promise\PromiseInterface
     */
    function query($query) {
        return $this->pool->getConnection()->then(function (mysqli $conn) use ($query) {
            $status = $conn->query($query, MYSQLI_ASYNC);
            if ($status === false) {
                $this->pool->free($conn);
                throw new Exception($conn->error);
            }

            $id = spl_object_hash($conn);
            $this->conn[$id] = $conn;
            $this->deferred[$id] = $deferred = new Deferred();

            if (!isset($this->timer)) {
                $this->timer = $this->loop->addPeriodicTimer(0.01, function (){

                    $links = $errors = $reject = $this->conn;
                    mysqli_poll($links, $errors, $reject, 0); // don't wait, just check

                    $each = array('links' => $links, 'errors' => $errors, 'reject' => $reject) ;
                    foreach($each as $type => $connects) {
                        /**
                         * @var $conn mysqli
                         */
                        foreach($connects as $conn) {
                            $id = spl_object_hash($conn);
                            if(isset($this->conn[$id])) {
                                $deferred = $this->deferred[$id];
                                if ($type == 'links') {
                                    /**
                                     * @var $result mysqli_result
                                     */
                                    $result = $conn->reap_async_query();
                                    if ($result === false) {
                                        $deferred->reject(new Exception($conn->error));
                                    } else {
                                        $deferred->resolve(new Result($result));
                                    }
                                }

                                if ($type == 'errors') {
                                    $deferred->reject(new Exception($conn->error));
                                }

                                if ($type == 'reject') {
                                    $deferred->reject(new Exception('Query was rejected'));
                                }

                                unset($this->deferred[$id], $this->conn[$id]);
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
}