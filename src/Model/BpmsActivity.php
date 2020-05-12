<?php

namespace Niyam\Bpms\Model;

class BpmsActivity extends BpmsBaseModel
{
    public function case()
    {
        return $this->belongsTo(BpmsCase::class, 'case_id');
    }
}
