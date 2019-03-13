<?php namespace Niyam\Bpms\Service;

use Niyam\Bpms\Data\DataRepositoryInterface;
use Niyam\Bpms\Data\BaseService;
use Niyam\Bpms\Service\FormService;

use Niyam\Bpms\Model\BpmsForm;


class StateService extends BaseService
{
    protected $formService;

    public function __construct(FormService $formService)
    {
        $this->formService = $formService;
    }

    public function getCurrentState($state = null)
    {
        if (!$state) {
            $state = $this->state;
            if ($s = $this->currentState)
                return new ProcessResponse(true, $s, 'OK');
        }
        try {
            if (!$this->baseTable) {
                $s = $this->dataRepo->findEntity(static::BPMS_META, ['case_id' => $this->getCaseId(), 'element_type' => 1, 'element_name' => $state]);
                $opts = $s->options;
                $s->type = $opts['type'];
                $s->next_wid = $opts['next_wid'];
                $s->next_type = $opts['next_type'];
                $s->text = $opts['text'];
                $this->currentState = $s;
                return new ProcessResponse($s ? true : false, $s, 'OK');
            }

            $s = $this->dataRepo->findEntity(static::BPMS_STATE, ['wid' => $state, 'ws_pro_id' => $this->wid]);
            $this->currentState = $s;
            return new ProcessResponse($s ? true : false, $s, 'OK');
        } catch (\Exception $e) {
            return new ProcessResponse(false, 'Exception occurred', 'OK');
        }
    }

    public function setStateMeta($stateWID, $data)
    {
        $state = $this->findEntity(static::BPMS_STATE, ['wid' => $stateWID, 'ws_pro_id' => $this->wid]);

        if ($this->test) {
            $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
            $opts = $state->options;
        } else {
            $predicate = ['element_type' => 1, 'element_name' => $stateWID, 'case_id' => $this->id];
            $meta = $this->dataRepo->findEntity(static::BPMS_META, $predicate);
            $opts = $meta->options;
        }

        if (!isset($opts)) {
            $opts = array();
        }

        if (isset($data['users'])) {
            $opts['users'] = $data['users'];
        }

        //Script task 
        if (isset($data['script'])) {
            $opts['script'] = $data['script'];
        }

        //Intermediate message 
        if (isset($data['message'])) {
            $opts['message'] = $data['message'];
        }

        //Intermediate timer
        if (isset($data['timer'])) {
            $opts['timer'] = $data['timer'];
        }
        //is used for subprocess
        if (isset($data['start'])) {
            $opts['start'] = $data['start'];
        }

        if (isset($data['users_meta'])) {
            $opts['users_meta'] = $data['users_meta'];
        }

        if (isset($data['back'])) {
            $opts['back'] = $data['back'];
        }

        if (isset($data['name'])) {
            $opts['name'] = $data['name'];
        }
        
        //To add one form to forms
        if (isset($data['form'])) {

            $formData = ['form_id' => $data['form']];

            if (isset($data['form_condition'])) {
                $formData['condition'] = $data['form_condition'];
            }
            if (isset($data['options'])) {
                $formData['options'] = $data['options'];
            }

            $this->formService->updateFormOfState(['state_id' => $state->id, 'form_id' => $data['form']], $formData);

            if (isset($opts['forms']) ? !in_array($data['form'], $opts['forms']) : true)
                $opts['forms'][] = $data['form'];
        }
        //To replace all forms --- to rearrange
        if (isset($data['forms'])) {
            $opts['forms'] = $data['forms'];
        }

        if (isset($data['type'])) {
            $dataTemp['meta_type'] = $data['type'];
        }

        if (isset($data['value'])) {
            $dataTemp['meta_value'] = $data['value'];
        }

        $dataTemp['options'] = $opts;

        if ($this->test) {
            return $this->updateEntity(static::BPMS_STATE, $predicate, $dataTemp, true);
        } else {
            return $this->updateEntity(static::BPMS_META, $predicate, $dataTemp);
        }
    }

    public function getStateMeta($stateWID = null, $predicate = null, $columns = null)
    {
        if ($this->test) {
            $meta = $this->findEntity(static::BPMS_STATE, ['wid' => $stateWID ? $stateWID : $this->state, 'ws_pro_id' => $this->wid]);
        } else {
            $predicate = ['element_name' => $stateWID ? $stateWID : $this->state, 'case_id' => $this->id];
            $meta = $this->findEntity(static::BPMS_META, $predicate);
        }

        if (!$meta)
            return;

        $opts = $meta->options;
        
        $res = array();

        if (isset($opts['back'])) {
            $res['back'] = $opts['back'];
        }

        if (isset($opts['name'])) {
            $res['name'] = $opts['name'];
        }
        if (isset($opts['users_meta'])) {
            $res['users_meta'] = $opts['users_meta'];
        }

        if (isset($opts['forms'])) {
            $res['forms'] = $opts['forms'];
        }

        if (isset($opts['script'])) {
            $res['script'] = $opts['script'];
        }

        if (isset($meta->meta_type)) {
            $res['type'] = $meta->meta_type;
        }

        if (isset($opts['users'])) {
            $res['users'] = $opts['users'];
        }

        if (isset($meta->meta_value)) {
            $res['value'] = $meta->meta_value;
        }

        return $res;
    }

    public function deleteStateMeta($stateWID, $option, $data)
    {
        $state = $this->findEntity(static::BPMS_STATE, ['wid' => $stateWID, 'ws_pro_id' => $this->wid]);

        if ($this->test) {
            $opts = $state->options;
            $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
        } else {
            $predicate = ['element_type' => 1, 'element_id' => $state->id, 'element_name' => $stateWID, 'case_id' => $this->id];
            $meta = $this->findEntity(static::BPMS_META, $predicate);
            $opts = $meta->options;
        }

        if ($option == 'forms') {
            $opts['forms'] = array_values(array_diff($opts['forms'], $data));
        } else {
            return;
        }

        $dataTemp['options'] = $opts;

        if ($this->test) {
            return $this->updateEntity(static::BPMS_STATE, $predicate, $dataTemp);
        } else {
            return $this->updateEntity(static::BPMS_META, $predicate, $dataTemp);
        }
    }

    public function updateStateOptions($stateWID, $caseId)//is used in subprocess!!!
    {
        $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
        $state = $this->findEntity(static::BPMS_STATE, ['wid' => $stateWID]);
        $opts = $state->options;
        $opts['cases'][] = $caseId;

        $this->updateEntity(static::BPMS_STATE, $predicate, ['options' => $opts]);
    }
}

