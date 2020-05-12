<?php

namespace Niyam\Bpms\Model;

class BpmsMeta extends BpmsBaseModel
{
    public function case()
    {
        $this->belongsTo(BpmsCase::class, 'case_id');
    }
}
