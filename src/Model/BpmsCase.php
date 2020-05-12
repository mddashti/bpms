<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class BpmsCase extends BpmsBaseModel
{
    use SoftDeletes;

    protected $casts = [
        'options' => 'array',
        'system_options' => 'array',
    ];

    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class, 'ws_pro_id');
    }

    public function activities()
    {
        return $this->hasMany(BpmsActivity::class, 'case_id');
    }

    public function metas()
    {
        return $this->hasMany(BpmsMeta::class, 'case_id');
    }
}
