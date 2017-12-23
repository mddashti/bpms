<?php namespace Niyam\Bpms;

class ProcessResponse
{
    public $isSuccess;
    public $entity;
    public $message;
    public $code;

    public function __construct($result, $entity, $message, $code = null)
    {
        $this->isSuccess = $result;
        $this->entity = $entity;
        $this->message = $message;
        $this->code = $code;
    }
}