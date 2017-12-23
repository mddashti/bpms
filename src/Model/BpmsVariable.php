<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsVariable extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class, 'ws_pro_id');
    }

    public function fetchMethod()
    {
        return $this->belongsTo(BpmsFetch::class, 'fetch_id');
    }

    public function type()
    {
        return $this->belongsTo(BpmsVariableType::class, 'type_id');
    }
}
