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
        return $this->hasMany(BpmsCasePart::class, 'case_id');
    }

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
