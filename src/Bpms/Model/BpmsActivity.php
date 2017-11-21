<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsActivity extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
    ];

    public function case()
    {
        return $this->belongsTo(BpmsCase::class, 'case_id');
    }
}
