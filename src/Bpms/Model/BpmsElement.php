<?php

namespace Bpms\Model;

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
        return $this->hasOne(BpmsVariable::class, 'variable_id');
    }
}
