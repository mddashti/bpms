<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsGate extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'options' => 'array',
    ];
    
    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class);
    }
}
