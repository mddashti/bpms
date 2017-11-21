<?php

namespace Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsType extends Model
{
    protected $guarded = ['id'];
    protected $visible = ['id','name'];

    public function workflows()
    {
        return $this->hasMany(BpmsWorkflow::class, 'type');
    }
}
