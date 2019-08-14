<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsVariableType extends Model
{
    protected $guarded = ['id'];
    //protected $table = 'bpms.bpms_variable_types';


    public function variables()
    {
        return $this->hasMany(BpmsVariable::class, 'type_id');
    }
}
