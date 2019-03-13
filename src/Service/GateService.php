<?php namespace Niyam\Bpms\Service;

use Niyam\Bpms\Data\BaseService;
use Niyam\Bpms\Model\BpmsWorkflow;

class GateService extends BaseService
{
    public function getGateConditions($gateWID, $workflow_id = null)
    {
        //if workflow is copy 
        $workflow_id = $workflow_id ? : $this->wid;
       
       
        $workflow = BpmsWorkflow::findOrFail($workflow_id);

        return $workflow->transitions()->where('gate_wid', $gateWID)->orderBy('order_transition')->get(['order_transition', 'meta', 'to_state']);
    }

    public function setGateConditions($gateWID, $data, $workflow_id = null)
    {
        $conditions = $data['conditions'];
        $froms = $data['froms'];
        foreach ($conditions as $condition) {
            foreach ($froms as $from) {
                $data = [
                    'order' => $condition['order'],
                    'from' => $from,
                    'to' => $condition['to'],
                    'condition' => $condition['condition'],
                ];

                $this->setTransitionMeta($data);
            }
        }
    }

    public function setTransitionMeta($data)
    {
        $from = $data['from'];
        $to = $data['to'];
        $meta_value = $data['condition'];

        if (isset($data['meta_json'])) {
            $opts['meta_json'] = $data['meta_json'];
        }

        $order = $data['order'];
        $predicate = ['ws_pro_id' => $this->wid, 'from_state' => $from, 'to_state' => $to];

        if ($this->test) {
            $predicate = ['ws_pro_id' => $this->wid, 'from_state' => $from, 'to_state' => $to];
            $data = ['meta' => $meta_value, 'order_transition' => $order];

            if (isset($opts)) {
                $data['options'] = $opts;
            }

            return $this->updateEntity(static::BPMS_TRANSITION, $predicate, $data);
        }

        $transition = $this->findEntity(static::BPMS_TRANSITION, $predicate);
        $predicate = ['case_id' => $this->id, 'element_type' => 2, 'element_id' => $transition->id];
        $data = ['meta_value' => $meta_value, 'meta_type' => 1];

        if (isset($opts)) {
            $data['options'] = $opts;
        }
        return $this->updateEntity(static::BPMS_META, $predicate, $data);
    }
}

