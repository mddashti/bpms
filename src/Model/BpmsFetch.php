<?php

namespace Niyam\Bpms\Model;

class BpmsFetch extends BpmsBaseModel
{
    public function variable()
    {
        return $this->hasOne(BpmsVariable::class, 'fetch_id');
    }
}
