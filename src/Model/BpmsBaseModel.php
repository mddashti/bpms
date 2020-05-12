<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsBaseModel extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'options' => 'array',
    ];
}
