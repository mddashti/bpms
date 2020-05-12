<?php

namespace Niyam\Bpms\Model;

class BpmsTransition extends BpmsBaseModel
{
    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class);
    }

    public function fromState()
    {
        return $this->belongsTo(BpmsState::class, 'from_state');
    }

    public function toState()
    {
        return $this->belongsTo(BpmsState::class, 'to_state');
    }

    public function gate()
    {
        return $this->belongsTo(BpmsGate::class, 'gate_wid', 'wid');
    }
}
