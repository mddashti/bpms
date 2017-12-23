<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BpmsVariableType extends Model
{
    protected $guarded = ['id'];

    public function variables()
    {
        return $this->hasMany(BpmsVariable::class, 'type_id');
    }
}
