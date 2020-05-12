<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsStateConfig extends BpmsBaseModel
{
    use SoftDeletes;

    public function state()
    {
        return $this->belongsTo(BpmsState::class, 'state_id');
    }
    public function form()
    {
        return $this->belongsTo(BpmsForm::class, 'form_id');
    }
}
