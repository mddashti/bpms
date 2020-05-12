<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class BpmsTrigger extends BpmsBaseModel
{
    use SoftDeletes;

    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class, 'ws_pro_id');
    }

    public function element()
    {
        return $this->hasOne(BpmsElementTrigger::class, 'trigger_id');
    }
}
