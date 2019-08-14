<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsType extends Model
{
    protected $guarded = ['id'];
    //protected $table = 'bpms.bpms_types';

    protected $visible = ['id','name'];

    public function workflows()
    {
        return $this->hasMany(BpmsWorkflow::class, 'type');
    }
}
