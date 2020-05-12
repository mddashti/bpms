<?php

namespace Niyam\Bpms\Model;

class BpmsGate extends BpmsBaseModel
{
    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class);
    }

    public function transitions()
    {
        return $this->hasMany(BpmsTransition::class, 'gate_wid');
    }
}
