<?php
namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsForm extends Model
{
    use SoftDeletes;

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

    public function stateConfigs()
    {
        return $this->hasMany(BpmsStateConfig::class, 'form_id');

    }
}
