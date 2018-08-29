<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class BpmsTimer extends Model
{
    use SoftDeletes;
    protected $guarded = ['id'];

}
