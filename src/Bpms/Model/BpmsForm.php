<?php

namespace Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsForm extends Model
{
    protected $guarded = ['id'];
    
    protected $casts = [
            'options' => 'array',
    ];

    public function elements()
    {
        return $this->hasMany(BpmsElement::class, 'form_id');
    }

    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class, 'ws_pro_id');
    }
}
