<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsVariable extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];
    protected $table = 'bpms.bpms_variables';

    protected $casts = [
        'options' => 'array',
    ];

    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class, 'ws_pro_id');
    }

    public function fetch()
    {
        return $this->belongsTo(BpmsFetch::class, 'fetch_id');
    }

    public function type()
    {
        return $this->belongsTo(BpmsVariableType::class, 'type_id');
    }

    public function element()
    {
        return $this->hasOne(BpmsElement::class, 'variable_id');
    }

    public function forms()
    {
        return $this->belongsToMany(BpmsForm::class, 'bpms_elements', 'variable_id', 'form_id')->withPivot('element_name', 'element_type');
    }
}
