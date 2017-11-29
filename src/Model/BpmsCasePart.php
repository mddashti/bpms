<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BpmsCasePart extends Model
{
    use SoftDeletes;
    
    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
        'system_options' => 'array',
    ];
        
    public function case()
    {
        return $this->belongsTo(BpmsCase::class, 'case_id');
    }
}
