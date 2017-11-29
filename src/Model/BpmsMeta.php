<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsMeta extends Model
{
    protected $guarded = ['id'];
    
    protected $casts = [
        'options' => 'array',
    ];

    public function case()
    {
        $this->belongsTo('Bpms\Model\BpmsCase', 'case_id');
    }

}
