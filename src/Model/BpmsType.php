<?php

namespace Niyam\Bpms\Model;

class BpmsType extends BpmsBaseModel
{
    protected $visible = ['id','name'];

    public function workflows()
    {
        return $this->hasMany(BpmsWorkflow::class, 'type');
    }
}
