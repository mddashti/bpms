<?php

namespace Niyam\Bpms\Service;

use Niyam\Bpms\Data\BaseService;
use Niyam\Bpms\Model\BpmsCase;

class CaseService extends BaseService
{
    public function getUserCases($userId, $status)
    {
        return $this->findEntities(static::BPMS_CASE, ['status' => $status]);
    }

    public function getCases($predicate, $filter = null)
    {
        $field = isset($filter['field']) ? $filter['field'] : 'bpms_cases.created_at';
        $order = isset($filter['order']) ? $filter['order'] : 'asc';
        $columns = isset($filter['columns']) ? $filter['columns'] : null;
        $skip = isset($filter['skip']) ? $filter['skip'] : null;
        $limit = isset($filter['limit']) ? $filter['limit'] : null;

        return $this->findCasesByMixed($predicate, $columns, $field, $order, $skip, $limit);
    }

    //vars, state_vars
    //$value for vars must be array such as ['A'=>1, 'B'=>2] and will be merged with current case vars!
    //$value for state_vars must be array of 'id' and 'vars', id refer to form_id and vars are local variables of form!
    public function setCaseOption($option, $value, $caseId = null)
    {
        $caseId = $caseId ?: $this->cid;
        $found = $this->getEntity(static::BPMS_CASE, $caseId);
        $opts = $found->options;

        if ($option == 'vars') {
            if (isset($opts['vars'])) {
                $opts['vars'] = array_merge($opts['vars'], $value);
            } else {
                $opts['vars'] = $value;
            }
        }

        $data = ['options' => $opts, 'updated_at'  => now()];
        return BpmsCase::updateOrCreate(['id' => $caseId], $data); //return updated case
    }

    private function setSystemCaseOption($option, $value, $caseId = null)
    {
        $caseId = $caseId ?: $this->cid;
        $found = $this->getEntity(static::BPMS_CASE, $caseId);
        $opts = $found->options;

        if ($option == 'users') {
            $opts['users'] = $value;
        }

        $data = ['options' => $opts];
        return $this->updateEntity(static::BPMS_CASE, ['id' => $caseId], $data);
    }

    public function getCaseOption($option, $filter = null, $caseId = null)
    {
        $caseId = $caseId ?? $this->cid;
        if (!$caseId)
            return null;
        $found = $this->getEntity(static::BPMS_CASE, $caseId);
        $opts = $found->options;

        if ($option == 'vars') {
            $to_filter = isset($opts['vars']) ? $opts['vars'] : null;
            if ($to_filter) {
                if ($filter) {
                    $filtered = array_filter($to_filter, function ($key) use ($filter) {
                        return in_array($key, $filter);
                    }, ARRAY_FILTER_USE_KEY);
                    return $filtered;
                } else {
                    return $opts['vars'];
                }
            } else {
                return null;
            }
        }
        return $found->options[$option];
    }

    public function createCase($inputArray)
    {
        $workflowId = isset($inputArray['ws_pro_id']) ? $inputArray['ws_pro_id'] : null;
        $startState = isset($inputArray['start']) ? $inputArray['start'] : null;
        $userCreator = isset($inputArray['user']) ? $inputArray['user'] : 0;
        $title = isset($inputArray['title']) ? $inputArray['title'] : $workflowId;
        $vars = isset($inputArray['vars']) ? $inputArray['vars'] : null;
        $make_copy = isset($inputArray['copy']) ? $inputArray['copy'] : false;

        if (!$workflowId) {
            return false;
        }

        if ($vars) {
            $opts['vars'] = $vars;
        }

        $data = ['ws_pro_id' => $workflowId, 'user_creator' => $userCreator, 'status' => 'created'];

        if ($startState) {
            if (!BpmsState::where(['wid' => $startState, 'ws_pro_id' => $workflowId])->first())
                return false;
            $data['state'] = $startState;
            $data['status'] = 'created';
        } else if ($userCreator != static::SYSTEM_CASE && !$startState) {
            $predicate = ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflowId];

            $states = $this->findEntities(static::BPMS_STATE, $predicate)->toArray();

            if (count($states) > 1)
                return false;
            $data['state'] = reset($states)['wid'];
            $data['status'] = 'working';
            $startState = $data['state'];
        }

        if ($userCreator != static::SYSTEM_CASE && static::CONFIG_FILTER_DUPLICATE_CASE) {
            $duplicatePredicate = ['user_creator' => $userCreator, 'status' => 'created', 'ws_pro_id' => $workflowId];
            $founds = $this->findEntities(static::BPMS_CASE, $duplicatePredicate);

            foreach ($founds as $found) {
                if (!isset(BpmsMeta::where('case_id', $found->id)->first()->options['forms'])) {
                    $found->options = $opts;
                    $found->created_at = Carbon::now();
                    $found->save();
                    return $found->id;
                }
            }
        }

        if (isset($opts)) {
            $data['options'] = $opts;
        }

        if ($make_copy) {
            $data['system_options']['copy'] = true;
        }

        $data['title'] = $title;

        $newCaseId = $this->createEntity(static::BPMS_CASE, $data);


        if ($userCreator == static::SYSTEM_CASE) {
            $backup = $this->export($this);
        }

        if ($startState) {
            $this->setCaseById($newCaseId);
            $this->setMetaReq($userCreator);
            $this->checkNext($startState, $userCreator);
            $change = $this->saveChanges(ProcessLogicInterface::WORKFLOW_STARTED);
            if ($change['status'] == 'error') {
                BpmsCase::where('id', $newCaseId)->forceDelete();
                return false;
            }
        }

        if ($userCreator == static::SYSTEM_CASE)
            $this->import($backup);

        $states = $this->findEntities(static::BPMS_STATE, ['ws_pro_id' => $workflowId]);

        foreach ($states as $state) {
            if ($state->type == 'bpmn:SubProcess') {
                $opts = $state->options;
                $opts['cases'] = [];
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_SUBPROCESS, 'meta_type' => $state->meta_type, 'element_id' => $state->id, 'element_name' => $state->wid, 'case_id' => $newCaseId, 'meta_value' => $state->meta_value, 'options' => $opts];
                $meta = $this->createEntity(static::BPMS_META, $data);
                continue;
            }

            if ($make_copy) {
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_STATE, 'meta_type' => $state->meta_type, 'element_id' => $state->id, 'element_name' => $state->wid, 'case_id' => $newCaseId, 'meta_value' => $state->meta_value];
                $opts = $state->options;
                $opts['type'] = $state->type;
                $opts['next_wid'] = $state->next_wid;
                $opts['next_type'] = $state->next_type;
                $opts['text'] = $state->text;
                $data['options'] = $opts;
                $meta = $this->createEntity(static::BPMS_META, $data);
            }
        }

        if ($make_copy) {
            $transitions = $this->findEntities(static::BPMS_TRANSITION, ['ws_pro_id' => $workflowId]);
            foreach ($transitions as $t) {
                $opts = $t->options;
                $opts['from_state'] = $t->from_state;
                $opts['to_state'] = $t->to_state;
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_TRANSITION, 'meta_type' => 1, 'element_id' => $t->id, 'element_name' => $t->gate_wid, 'case_id' => $newCaseId, 'meta_value' => $t->meta, 'options' => $opts];
                $meta = $this->createEntity(static::BPMS_META, $data);
            }
        }
        return $newCaseId;
    }
}
