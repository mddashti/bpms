<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsElement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
    ];

    public function form()
    {
        return $this->belongsTo(BpmsForm::class, 'form_id');
    }

    public function variable()
    {
        return $this->belongsTo(BpmsVariable::class, 'variable_id');
    }

    public function elementTriggers()
    {
        return $this->hasMany(BpmsElementTrigger::class, 'element_id');
    }

    
}
