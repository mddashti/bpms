<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsTrigger extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];
    
    protected $casts = [
        'options' => 'array',
    ];

    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class, 'ws_pro_id');
    }

    public function element()
    {
        return $this->hasOne(BpmsElementTrigger::class, 'trigger_id');
    }
}
