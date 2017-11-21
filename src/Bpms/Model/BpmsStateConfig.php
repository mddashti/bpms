<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsStateConfig extends Model
{
    protected $guarded = ['id'];
        
    protected $casts = [
       'options' => 'array',
    ];

    public function state()
    {
        $this->belongsTo(BpmsState::class, 'state_id');
    }
}
