<?php

namespace Niyam\Bpms\Model;

class BpmsElementTrigger extends BpmsBaseModel
{
    public function element()
    {
        return $this->belongsTo(BpmsElement::class, 'element_id');
    }

    public function trigger()
    {
        return $this->belongsTo(BpmsTrigger::class, 'trigger_id');
    }
}
