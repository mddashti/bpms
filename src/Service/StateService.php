<?php namespace Niyam\Bpms\Service;

use Niyam\Bpms\Data\BaseService;
use Niyam\Bpms\Model\BpmsState;

class StateService extends BaseService
{
    protected $formService;

    protected $model;

    public function __construct(FormService $formService, BpmsState $model)
    {
        $this->formService = $formService;
        $this->model = $model;
    }

    public function getUserStarts($userId, $ws_pro_id = null)
    {
        $predicate = ['position_state' => ProcessLogicInterface::POSITION_START];
        if ($ws_pro_id) {
            $predicate['ws_pro_id'] = $ws_pro_id;
        }
        $states = BpmsState::with('workflow')->where($predicate)->get();
        $res = array();
        foreach ($states as $state) {
            $state->isPositionBased = FALSE;
            if ($state->meta_type == 1 && $state->meta_value == $userId) { //explicit user
                $res[] = $state;
                continue;
            } else if ($this->isPositionBased($state->meta_type)) {
                $positions = isset($state->options['users']) ? $state->options['users'] : null;
                $position = $this->givePositionOfUser($userId);
                if (in_array($position, $positions)) {
                    $state->isPositionBased = TRUE;
                    $res[] = $state;
                }
            }

            $users = isset($state->options['users']) ? $state->options['users'] : null; //implicit
            if ($users) {
                if (in_array($userId, $users)) {
                    $res[] = $state;
                }
            }
        }
        return $res;
    }

    public function findPositionsOfUserInStart($userId, $state)
    {
        $positionsOfUser = $this->givePositionsOfUser($userId);
        $positionsOfState = $this->findPositionsOfState($state);

        return array_intersect($positionsOfUser, $positionsOfState);
    }

    public function findState($predicate)
    {
        return $this->model->where($predicate)->first();
    }

    public function findPositionsOfState($state)
    {
        $foundState = $this->findState(['wid' => $state]);
        if (!$this->isPositionBased($foundState->meta_type))
            return [];
        return $foundState->options['users'];
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
                $s = $this->findEntity(static::BPMS_META, ['case_id' => $this->getCaseId(), 'element_type' => 1, 'element_name' => $state]);
                $opts = $s->options;
                $s->type = $opts['type'];
                $s->next_wid = $opts['next_wid'];
                $s->next_type = $opts['next_type'];
                $s->text = $opts['text'];
                $this->currentState = $s;
                return new ProcessResponse($s ? true : false, $s, 'OK');
            }

            $s = $this->findEntity(static::BPMS_STATE, ['wid' => $state, 'ws_pro_id' => $this->wid]);
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
            $meta = $this->findEntity(static::BPMS_META, $predicate);
            $opts = $meta->options;
        }

        if (!isset($opts)) {
            $opts = array();
        }

        if (isset($data['users'])) {
            $opts['users'] = $data['users'];
        }


        //Sequential task
        if (isset($data['x'])) {
            $opts['x'] = $data['x'];
        }

        if (isset($data['y'])) {
            $opts['y'] = $data['y'];
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
            $form_id = $data['form']['form_id'];

            $this->formService->updateFormOfState(['state_id' => $state->id, 'form_id' => $form_id], $data['form']);

            if (isset($opts['forms']) ? !in_array($form_id, $opts['forms']) : true)
                $opts['forms'][] = $form_id;
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

    public function getStateMeta($stateWID = null, $predicate = null, $columns = '*')
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

        if (isset($opts['x'])) {
            $res['x'] = $opts['x'];
        }

        if (isset($opts['y'])) {
            $res['y'] = $opts['y'];
        }

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

    public function updateStateOptions($stateWID, $caseId) //is used in subprocess!!!
    {
        $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
        $state = $this->findEntity(static::BPMS_STATE, ['wid' => $stateWID]);
        $opts = $state->options;
        $opts['cases'][] = $caseId;

        $this->updateEntity(static::BPMS_STATE, $predicate, ['options' => $opts]);
    }
}
