<?php

namespace KHR\React\Mysql;

use \mysqli_result;

class Result {

    /**
     * @var mysqli_result
     */
    public $result;


    public function __construct(mysqli_result $res) {
        $this->result = $res;
    }

    public function all() {
        $rows = [];
        while($row = $this->result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }


    public function one() {
        return current($this->result->fetch_assoc());
    }

    public function column() {
        $res = [];
        foreach($this->all() as $row) {
            $res[] = current($row);
        }
        return $res;
    }

    public function scalar() {
        return current($this->one());
    }

    public function exists() {
        return $this->result->num_rows > 0;
    }

    public function __destruct() {
        $this->result->free();
    }
}