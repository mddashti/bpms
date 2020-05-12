<?php

namespace Niyam\Bpms\Model;

class BpmsVariableType extends BpmsBaseModel
{
    public function variables()
    {
        return $this->hasMany(BpmsVariable::class, 'type_id');
    }
}
