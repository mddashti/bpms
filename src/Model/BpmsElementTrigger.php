<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsElementTrigger extends Model
{
    protected $guarded = ['id'];

    public function element()
    {
        return $this->belongsTo(BpmsElement::class, 'element_id');
    }

    public function trigger()
    {
        return $this->belongsTo(BpmsTrigger::class, 'trigger_id');
    }

}
