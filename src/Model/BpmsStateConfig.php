<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsStateConfig extends Model
{
    use SoftDeletes;
    protected $guarded = ['id'];
    //protected $table = 'bpms.bpms_state_configs';

        
    protected $casts = [
       'options' => 'array',
    ];

    public function state()
    {
        return $this->belongsTo(BpmsState::class, 'state_id');
    }
    public function form()
    {
        return $this->belongsTo(BpmsForm::class, 'form_id');
    }
}
