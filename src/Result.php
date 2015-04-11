<?php

namespace KHR\React\Mysql;

use \mysqli_result;

class Result {

    /**
     * @var mysqli_result
     */
    public $rows = [];

    public $insert_id;

    public $affected_rows;


    public function __construct($res, $insert_id, $affected_rows) {

        if ($res instanceof mysqli_result) {
            while($row = $res->fetch_assoc()) {
                $this->rows[] = $row;
            }
            $res->free();
        }

        $this->insert_id = $insert_id;
        $this->affected_rows = $affected_rows;
    }

    public function all() {
        return $this->rows;
    }

    public function one() {
        return current($this->all());
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
        return !empty($this->rows);
    }
}