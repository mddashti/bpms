<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsVariable extends Model
{
    protected $guarded = ['id'];

    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class, 'ws_pro_id');
    }
    
    public function fetchMethod()
    {
        return $this->hasOne(BpmsFetch::class);
    }
}
