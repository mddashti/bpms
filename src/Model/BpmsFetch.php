<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsFetch extends Model
{
    protected $guarded = ['id'];

    public function variable()
    {
        return $this->hasOne(BpmsVariable::class, 'fetch_id');
    }
}
