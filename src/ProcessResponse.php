<?php namespace Niyam\Bpms;

use Illuminate\Contracts\Support\Jsonable;


class ProcessResponse implements Jsonable
{
    public $isSuccess;
    public $entity;
    public $message;
    public $code;

    public function __construct($result, $entity, $message = null, $code = null)
    {
        $this->isSuccess = $result;
        $this->entity = $entity;
        $this->message = $message;
        $this->code = $code;
    }

    public function toJson($options = 0)
    {
        return json_encode($this);
    }
}