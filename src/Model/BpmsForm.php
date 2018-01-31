<?php
namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsForm extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['stateConfigs'];

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

    public function stateConfigs()
    {
        return $this->hasMany(BpmsStateConfig::class, 'form_id');

    }

    public function elementTriggers()
    {
        return $this->hasManyThrough(BpmsElementTrigger::class, BpmsElement::class, 'form_id', 'element_id');
    }

    public function variables()
    {
        return $this->belongsToMany(BpmsVariable::class, 'bpms_elements', 'form_id', 'variable_id')->withPivot('element_name', 'element_type')->with('fetch');
    }

    // public function fetches()
    // {
    //     return $this->hasManyThrough(BpmsFetch::class, BpmsVariable::class, 'fetch_id', 'id');
    // }
}
