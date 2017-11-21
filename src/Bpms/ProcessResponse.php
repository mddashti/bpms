<?php namespace Bpms;

class ProcessResponse
{
    public $isSuccess;
    public $entity;
    public $message;

    public function __construct($result, $entity, $message)
    {
        $this -> isSuccess = $result;
        $this -> entity = $entity;
        $this -> message = $message;
    }
}