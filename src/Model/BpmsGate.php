<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsGate extends Model
{
    protected $guarded = ['id'];
    
    public function workflow()
    {
        return $this->belongsTo('Bpms\Model\BpmsWorkflow');
    }
}
