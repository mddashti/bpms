<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsPart extends Model
{
    use SoftDeletes;
    
    protected $guarded = ['id'];
    
    public function workflow()
    {
        return $this->belongsTo('Bpms\Model\BpmsWorkflow');
    }
}
