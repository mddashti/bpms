<?php

namespace Niyam\Bpms\Model;

use Illuminate\Database\Eloquent\Model;

class BpmsState extends Model
{
    protected $guarded = ['id'];
     
    protected $visible = ['id','wid','text','ws_pro_id', 'workflow','type'];
     
    protected $casts = [
        'options' => 'array',
    ];

    public function workflow()
    {
        return $this->belongsTo(BpmsWorkflow::class, 'ws_pro_id');
    }

    public function user()
    {
        return $this->belongsTo(App\User::class);
    }

    public function stateConfigs()
    {
        return $this->hasMany(BpmsStateConfig::class, 'state_id');
    }

    public function forms()
    {
        return $this->hasMany(BpmsForm::class, 'form_id');
    }

    public function startTransitions()
    {
        return $this->hasMany(BpmsTransition::class, 'from_state');
    }

    public function endTransitions()
    {
        return $this->hasMany(BpmsTransition::class, 'to_state');
    }
}
