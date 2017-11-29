<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BpmsCase extends Model
{
    use SoftDeletes;
    protected $guarded = ['id'];
    protected $casts = [
        'options' => 'array',
        'system_options' => 'array',
    ];

    public function parts()
    {
        return $this->hasMany('Bpms\Model\BpmsCasePart', 'case_id');
    }

    public function workflow()
    {
        return $this->belongsTo('Bpms\Model\BpmsWorkflow', 'ws_pro_id');
    }

    public function activities()
    {
        return $this->hasMany('Bpms\Model\BpmsActivity', 'case_id');
    }

    public function metas()
    {
        return $this->hasMany('Bpms\Model\BpmsMeta', 'case_id');
    }
}
