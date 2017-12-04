<?php namespace Niyam\Bpms;


use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\DomCrawler\Crawler;
use Niyam\Bpms\Data\DataRepositoryInterface;
use Carbon\Carbon;


class ProcessLogic implements ProcessLogicInterface
{
    private $id = 0;

    private $wid = 0;

    private $workflow;

    private $case;

    private $test = false;

    private $status;

    private $state;

    private $parts;

    private $transitions;

    private $sm;

    private $part;

    private $subProcess = false;

    private $backupStatus;

    private $backupState;

    private $changed = true;

    private $event = ProcessLogicInterface::WORKFLOW_NO_EVENT;

    private $transitionFired = 0;

    private $comment;

    private $metaReq = null;

    private $next_user = null;

    private $next_state_text = null;

    private $next_form = 0;

    private $vars = null;

    private $stateReq = null;

    private $partId;

    private $dataRepo;

    private $baseTable = false;

    private $user_manual = null;

    // private $last_part = null;


    public function __construct(DataRepositoryInterface $dataRepo )
    {
        $this->dataRepo = $dataRepo;

        if(ProcessLogicInterface::CONFIG_BOOT_ELOQUENT)
            CustomBoot::enable();
    }

    public function setCase($case, $baseTable = false)
    {
        $wf = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS, $case->ws_pro_id);
        $this->test = false;
        $this->baseTable = $baseTable ? : !isset($case->system_options['copy']);
        $this->case = $case;
        $this->workflow = $wf;
        $this->id = $case->id;
        $this->wid = $wf->id;
        $this->status = $case->status;
        $this->state = $case->state;
        $this->backupState = $this->state;
    }

    public function setCaseById($caseId, $baseTable = false)
    {
        if (!$caseId) {
            return;
        }
        $case = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $caseId);
        $wf = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS, $case->ws_pro_id);
        $this->test = false;
        $this->baseTable = $baseTable ? : !isset($case->system_options['copy']);
        $this->case = $case;
        $this->workflow = $wf;
        $this->id = $case->id;
        $this->wid = $wf->id;
        $this->status = $case->status;
        $this->state = $case->state;
        $this->backupState = $this->state;
    }

    public function setWorkflow($workflow)
    {
        $this->test = true;
        $this->baseTable = true;
        $this->id = $workflow->id;
        $this->wid = $this->id;
        $this->workflow = $workflow;
        $this->status = $workflow->status;
        $this->state = $workflow->state;
        $this->backupState = $this->state;
    }

    public function setWorkflowById($id)
    {
        $wf = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS, $id);
        $this->test = true;
        $this->baseTable = true;
        $this->workflow = $wf;
        $this->id = $wf->id;
        $this->wid = $wf->id;
        //$this -> status = $wf -> status;
        //$this -> state = $wf -> state;
        $this->backupState = $this->state;
    }

    public function getCase()
    {
        return $this->case;
    }

    public function setComment($comment)
    {
        $this->comment = $comment;
    }

    public function getCommnet($comment)
    {
        return $this->comment;
    }

    public function setMetaReq($metaReq)
    {
        $this->metaReq = $metaReq;
    }

    public function setNextUser($next_user)
    {
        $this->next_user = $next_user;
    }

    public function setNextForm($next_form)
    {
        $this->next_form = $next_form;
    }

    public function getNextUser()
    {
        return $this->next_user;
    }

    public function getMetaReq()
    {
        return $this->metaReq;
    }

    public function setVars($vars)
    {
        $this->vars = $vars;
    }

    public function getVars()
    {
        return $this->vars;
    }

    public function setStateReq($stateReq)
    {
        $this->stateReq = $stateReq;
    }

    public function getStateReq()
    {
        return $this->stateReq;
    }

    public function setTransitionFired($tid, $available = null)
    {
        if ($available) {
            if (array_key_exists($tid, $available)) {
                $this->state = $available[$tid]->to_state;
            }
        }
        $this->transitionFired = $tid;
    }

    public function getTransitionFired()
    {
        return $this->transitionFired;
    }

    public function setCaseOption($option, $value, $caseId = null)
    {
        $caseId = $caseId ? $caseId : $this->id;
        $found = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $caseId);
        $opts = $found->options;

        if ($option == 'state_vars') {
            if (isset($opts['state_vars'])) {
                $ids = array_column($opts['state_vars'], 'id');
                $found_key = array_search($value['id'], $ids);
                if ($found_key !== false) {
                    $opts['state_vars'][$found_key]['vars'] = array_merge($opts['state_vars'][$found_key]['vars'], $value['vars']);
                } else {
                    $opts['state_vars'][] = $value;
                }
            } else {
                $opts['state_vars'][] = $value;
            }
        }

        if ($option == 'vars') {
            if (isset($opts['vars'])) {
                $opts['vars'] = array_merge($opts['vars'], $value);
            } else {
                $opts['vars'] = $value;
            }
        }

        $data = ['options' => $opts];
        return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, ['id' => $caseId], $data);
    }

    private function setSystemCaseOption($option, $value, $caseId = null)
    {
        $caseId = $caseId ? $caseId : $this->id;
        $found = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $caseId);
        $opts = $found->options;

        if ($option == 'users') {
            $opts['users'] = $value;
        }

        $data = ['options' => $opts];
        return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, ['id' => $caseId], $data);
    }

    public function getCaseOption($option, $filter = null, $caseId = null)
    {
        if ($this->test) {
            return null;
        }
        $caseId = $caseId ? $caseId : $this->id;
        $found = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $caseId);
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

        if ($option == 'state_vars' && $filter) {
            if (isset($opts['state_vars'])) {
                $ids = array_column($opts['state_vars'], 'id');
                $found_key = array_search($filter['id'], $ids);
                if ($found_key !== false) {
                    return $opts['state_vars'][$found_key];
                }
            }
            return null;
        }

        return $found->options[$option];
    }

    public function getUserStarts($userId, $ws_pro_id = null)
    {
        $predicate = ['position_state' => ProcessLogicInterface::POSITION_START];
        if ($ws_pro_id) {
            $predicate['ws_pro_id'] = $ws_pro_id;
        }
        $states = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_STATE, $predicate);
        $res = array();
        foreach ($states as $state) {
            if ($state->meta_type == 1 && $state->meta_value == $userId) {
                $res[] = $state;
                contunue;
            }

            $users = isset($state->options['users']) ? $state->options['users'] : null;
            if ($users) {
                if (in_array($userId, $users)) {
                    $res[] = $state;
                }
            }
        }
        return $res;
    }

    public function isUserAtStart($userId, $ws_pro_id = null)
    {
        $predicate = ['position_state' => ProcessLogicInterface::POSITION_START];
        if ($ws_pro_id) {
            $predicate['ws_pro_id'] = $ws_pro_id;
        }

        $states = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_STATE, $predicate);
        foreach ($states as $state) {
            if ($state->meta_type == 1 && $state->meta_value == $userId) {
                return true;
            }

            $users = isset($state->options['users']) ? $state->options['users'] : null;
            if ($users) {
                if (in_array($userId, $users)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getUserCases($userId, $status)
    {
        return $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_CASE, ['status' => $status]);
    }

    public function setEvent($event)
    {
        $this->event = $event;
    }

    public function getEvent()
    {
        return $this->event;
    }

    public function checkSubProcess()
    {
        $s = $this->getCurrentState();
        if ($s->isSuccess) {
            $currentState = $s->entity;
        } else {
            return;
        }

        if ($currentState->type == 'bpmn:SubProcess') {
            $meta = $this->getSubProcessMeta($currentState->wid);
            if ($meta->isSuccess) {
                $workflowId = $meta->entity['workflow'];
                $caseId = $meta->entity['case'];
                $startState = $meta->entity['start'];

                if (!$caseId) {
                    $caseId = $this->createCase(['ws_pro_id' => $workflowId, 'start' => $startState]);
                    $this->setSubProcessMeta($currentState->wid, $caseId);
                }
                $this->loadSubProcess($caseId);
            }
        }
    }

    public function checkNext($state = null)
    {
        $s = $this->getCurrentState($state);

        if ($s->isSuccess) {
            $currentState = $s->entity;
        } else {
            return;
        }

        if ($currentState->type == 'bpmn:SubProcess') {
            $meta = $this->getSubProcessMeta($currentState->wid);
            if ($meta->isSuccess) {
                $workflowId = $meta->entity['workflow'];
                $caseId = $meta->entity['case'];
                $startState = $meta->entity['start'];

                if ($workflowId) {
                    $caseId = $this->createCase(['ws_pro_id' => $workflowId, 'start' => $startState]);
                    $this->setSubProcessMeta($currentState->wid, $caseId);
                }
            }
        }

        if ($this->test) {
            return;
        }

        $userId = $this->getNextUserByType($currentState, true);
        $this->next_state_text = $currentState->text;

        if (isset($userId)) {
            $predicate = ['element_name' => $state ? $state : $this->state, 'case_id' => $this->id];
            $m = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate);

            $formId = isset($currentState->options['forms'][0]) ? $currentState->options['forms'][0] : null;
            $this->setNextForm($formId);

            $data = ['meta_value' => is_array($userId) ? -1 : $userId, 'meta_type' => $currentState->meta_type];
            $this->setNextUser($userId);

            // if (! $m) {
            //     $data['element_id'] = 0;
            //     $data['element_type'] = 1;
            // }
            return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate, $data, true);
        }
    }

    public function getNextStep($vars = null, $state = null)
    {
        $s = $this->getCurrentState($state);
        if ($s->isSuccess) {
            $currentState = $s->entity;
        } else {
            return;
        }

        switch ($currentState->next_type) {
            case "bpmn:ExclusiveGateway":
                return $this->getPossibleStates($state, $vars);
                break;

            case "bpmn:ParallelGateway":
                return $this->getPossibleStates($state);
                break;

            case "bpmn:InclusiveGateway":
                return $this->getPossibleStates($state, $vars);

                break;

            default:
                $s = $this->getCurrentState($currentState->next_wid);
                if ($s->isSuccess) {
                    $currentState = $s->entity;
                    return [['next_work' => $currentState->text, 'next_user' => $this->getNextUserByType($currentState), 'next_type' => $currentState->meta_type]];
                }
        }
    }

    public function isEligible($metaReq, $typeReq = null, $stateReq = null)
    {
        $lastState = $this->state;

        if ($this->test) {
            return true;
        }

        if ($this->status == 'created') {
            return true;
        }

        if ($this->status == 'unassigned') {
            return false;
        }

        if ($this->status == "parted") {
            $foundPart = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_PART, ['state' => $stateReq, 'case_id' => $this->id]);
            if (!$foundPart) {
                return false;
            }

            $lastState = $foundPart->state;
        } elseif ($this->status == 'end') {
            return false;
        }

        $predicate = ['element_name' => $lastState, 'case_id' => $this->id];
        $m = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate);

        if ($m == null || !$m->meta_type) { //meta to be set.
            return true;
        }

        // if ($m -> meta_type == ProcessLogicInterface::META_TYPE_COMMON && ! $m -> meta_value) {
        //     $users = $m -> options['users'];

        //     if (in_array($metaReq, $users)) {
        //         $this -> dataRepo -> updateEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate, ['meta_value' => $metaReq]);
        //         return true;
        //     }
        // }

        if ($typeReq) {
            if ($m->meta_type != $typeReq || $m->meta_value != $metaReq) {
                return false;
            }
        } else {
            if ($m->meta_value != $metaReq) {
                return false;
            }
        }

        return true;
    }

    public function getNextUserByType($state, $save = false, $rival = false)
    {
        $type = $state->meta_type;

        if ($type == ProcessLogicInterface::META_TYPE_CYCLIC) {
            $users = $state->options['users'];
            $key = $state->meta_value;
            if ($key !== null) {
                if (array_key_exists($key + 1, $users)) {
                    $state->meta_value = $key + 1;
                } else {
                    $state->meta_value = 0;
                }
            } else {
                $state->meta_value = 0;
            }
            if ($save) {
                $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $state->wid, 'ws_pro_id' => $state->ws_pro_id], ['meta_value' => $state->meta_value]);
            }
            return $users[$state->meta_value];
        }
        if ($type == ProcessLogicInterface::META_TYPE_USER) {
            return $state->meta_value;
        }
        if ($type == ProcessLogicInterface::META_TYPE_MANUAL) {
            return $this->user_manual ? $this->user_manual : $state->options['users'];
        }
        if ($type == ProcessLogicInterface::META_TYPE_VARIABLE) {
            $user = $state->options['users'];
            $vars = $this->getCaseOption('vars');
            $user = $vars[$user[0]];
            return $user;
        }
        if ($type == ProcessLogicInterface::META_TYPE_COMMON) {
            $users = $state->options['users'];

            if ($rival) {
                if (in_array($rival, $users)) {
                    return $rival;
                }
            } else {
                return $users;
            }
        }
        if ($type == ProcessLogicInterface::META_TYPE_ARRAY_VARIABLE) {
            $users = $state->options['users'];

            if ($rival) {
                $vars = $this->getCaseOption('vars');
                // foreach ($vars as $v) {
                //     $users[$v] = $vars[$v];
                // }

                if (in_array($rival, $vars)) {
                    return $rival;
                } else {
                    return 0;
                }
            } else {
                $vars = $this->getCaseOption('vars');
                foreach ($users as $u) {
                    $temp[$u] = $vars[$u];
                }
                return $temp;
            }
        }
        if ($type == ProcessLogicInterface::META_TYPE_COMMON_VARIABLE) {
            $users = $state->options['users'];//users:["x"] // x:[10,5]
            $var = $users[0];

            if ($rival) {
                $vars = $this->getCaseOption('vars');

                if (in_array($rival, $vars[$var])) {
                    return $rival;
                } else {
                    return 0;
                }
            } else {
                $vars = $this->getCaseOption('vars');
                // foreach ($vars as $v) {
                //     $users[$v] = $vars[$v];
                // }
                return isset($vars[$var]) ? $vars[$var] : null;
            }
        }
        if ($type == ProcessLogicInterface::META_TYPE_COMMON_CUSTOM) {
            $users = $state->options['users'];

            if ($rival) {
                if (in_array($rival, $this->getCustomUsers($users))) {
                    return $rival;
                }
            } else {
                return $users;
            }
        }
    }

    public function getCustomUsers($users_option)
    {
        //return [1,2,3,4,5,6,7,8,9,10];
    }

    public function getCustomUsersText($users_option)
    {
        //return 'USERS_OPTION_TEXT';
    }

    public function getAvailableTransitions($fromState = null, $columns = null)
    {
        $fromState = $fromState ? $fromState : $this->state;
        if ($this->baseTable == false) {
            $tis = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_META, ['element_type' => 2, 'options->from_state' => $fromState, 'case_id' => $this->getCaseId()], $columns);
            foreach ($tis as $t) {
                $opts = $t->options;
                $t->to_state = $opts['to_state'];
                $t->from_state = $opts['from_state'];
                $t->meta = $t->meta_value;
                $t->gate_wid = $t->element_name;
            }
            return $tis;
        }
        return $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, ['from_state' => $fromState, 'ws_pro_id' => $this->wid], $columns);
    }

    public function getPossibleStates($fromState, $vars = null)
    {
        $tis = $this->getAvailableTransitions($fromState);
        $result = array();
        foreach ($tis as $t) {
            if ($vars) {
                $language = new ExpressionLanguage();

                try {
                    $ret = $language->evaluate($t->meta, $vars);
                } catch (\Exception $e) {
                    //return $this -> saveChanges(ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR, $e -> getMessage());
                }

                if ($ret == false) {
                    continue;
                }
            }
            $state = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['ws_pro_id' => $this->wid, 'wid' => $t->to_state]);
            $result[] = ['next_type' => $state->meta_type, 'next_work' => $state->text, 'next_user' => $this->getNextUserByType($state)];
        }
        return $result;
    }

    public function createCase($inputArray)
    {
        $workflowId = isset($inputArray['ws_pro_id']) ? $inputArray['ws_pro_id'] : null;

        if (!$workflowId) {
            return null;
        }

        $startState = isset($inputArray['start']) ? $inputArray['start'] : null;
        $userCreator = isset($inputArray['user']) ? $inputArray['user'] : 0;
        $title = isset($inputArray['title']) ? $inputArray['title'] : null;
        $vars = isset($inputArray['vars']) ? $inputArray['vars'] : null;
        $make_copy = isset($inputArray['copy']) ? $inputArray['copy'] : false;

        if ($vars) {
            $opts['vars'] = $vars;
        }

        $title = $title ? $title : $workflowId;

        $data = ['ws_pro_id' => $workflowId, 'user_creator' => $userCreator, 'status' => 'created'];

        if ($startState) {
            $data['state'] = $startState;
            //$data['status'] = 'working';
        }

        if ($userCreator && ProcessLogicInterface::CONFIG_FILTER_DUPLICATE_CASE) {
            $found = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $data);

            if ($found != null) {
                return $found->id;
            }
        }

        if (isset($opts)) {
            $data['options'] = $opts;
        }

        if ($make_copy) {
            $data['system_options']['copy'] = true;
        }

        $data['title'] = $title;

        $newCaseId = $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $data);

        if ($startState) {
            $state = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['ws_pro_id' => $workflowId, 'wid' => $startState]);
            $opts['text'] = $state->text;
            $activityId = $this->addActivityLog(['case_id' => $newCaseId, 'user_id' => 0, 'transition_id' => 0, 'part_id' => 0, 'type' => ProcessLogicInterface::WORKFLOW_STARTED, 'pre' => 0, 'options' => $opts]);
            $formId = isset($state->options['forms'][0]) ? $state->options['forms'][0] : 0;

            $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, ['id' => $newCaseId], ['form_id' => $formId, 'activity_id' => $activityId, 'user_from' => $userCreator, 'user_current' => $this->getNextUserByType($state, true)]);
        }

        $states = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_STATE, ['ws_pro_id' => $workflowId]);

        foreach ($states as $state) {
            //if ($state -> meta_type || $state -> options) {
            if ($state->type == 'bpmn:SubProcess') {
                $opts = $state->options;
                $opts['cases'] = [];
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_SUBPROCESS, 'meta_type' => $state->meta_type, 'element_id' => $state->id, 'element_name' => $state->wid, 'case_id' => $newCaseId, 'meta_value' => $state->meta_value, 'options' => $opts];
                $meta = $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_META, $data);
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
                $meta = $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_META, $data);
            }
        }

        if ($make_copy) {
            $transitions = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, ['ws_pro_id' => $workflowId]);
            foreach ($transitions as $t) {
                // if ($t -> meta) {
                $opts = $t->options;
                $opts['from_state'] = $t->from_state;
                $opts['to_state'] = $t->to_state;
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_TRANSITION, 'meta_type' => 1, 'element_id' => $t->id, 'element_name' => $t->gate_wid, 'case_id' => $newCaseId, 'meta_value' => $t->meta, 'options' => $opts];
                $meta = $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_META, $data);
                // }
            }
        }
        return $newCaseId;
    }

    public function getCases($predicate, $filter = null)
    {
        $field = isset($filter['field']) ? $filter['field'] : 'workflow_cases.created_at';
        $order = isset($filter['order']) ? $filter['order'] : 'asc';
        $columns = isset($filter['columns']) ? $filter['columns'] : null;
        $skip = isset($filter['skip']) ? $filter['skip'] : null;
        $limit = isset($filter['limit']) ? $filter['limit'] : null;

        return $this->dataRepo->findCasesByMixed($predicate, $columns, $field, $order, $skip, $limit);
    }

    public function createWorkflow($inputArray)
    {
        $title = $inputArray['name'];
        $wid = isset($inputArray['wid']) ? $inputArray['wid'] : null;
        $userId = $inputArray['user_id'];
        $ws_id = 1;
        $type = isset($inputArray['type']) ? $inputArray['type'] : null;
        $newType = isset($inputArray['newType']) ? $inputArray['newType'] : null;
        $wxml = isset($inputArray['wxml']) ? $inputArray['wxml'] : null;
        $wsvg = isset($inputArray['wsvg']) ? $inputArray['wsvg'] : null;
        $opts = isset($inputArray['opts']) ? $inputArray['opts'] : null;
        $desc = isset($inputArray['description']) ? $inputArray['description'] : null;

        if ($type) {
            $typeId = $type;
        } elseif ($newType) {
            $typeFound = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_TYPE, ['name' => $newType, 'user_id' => $userId]);
            if ($typeFound) {
                $typeId = $typeFound->id;
            } else {
                $typeId = $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_TYPE, ['name' => $newType, 'user_id' => $userId]);
            }
        }

        if (ProcessLogicInterface::CONFIG_FILTER_CREATE_UNIQUE_PROCESS) {
            if ($this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS, ['name' => $title]))
                return false;
        }
        return $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS, [
            'name' => $title,
            'wid' => $wid,
            'user_id' => $userId,
            'ws_id' => $ws_id,
            'type' => isset($typeId) ? $typeId : null,
            'wsvg' => $wsvg ? $wsvg : null,
            'options' => $opts,
            'description' => $desc,
            'wxml' => $wxml ? $wxml : '<?xml version="1.0" encoding="UTF-8"?>
                <bpmn:definitions xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI" xmlns:dc="http://www.omg.org/spec/DD/20100524/DC" xmlns:di="http://www.omg.org/spec/DD/20100524/DI" id="Definitions_1" targetNamespace="http://bpmn.io/schema/bpmn">
            <bpmn:process id="Process_1" isExecutable="false">
                <bpmn:startEvent id="StartEvent_1" />
            </bpmn:process>
            <bpmndi:BPMNDiagram id="BPMNDiagram_1">
                <bpmndi:BPMNPlane id="BPMNPlane_1" bpmnElement="Process_1">
                <bpmndi:BPMNShape id="_BPMNShape_StartEvent_2" bpmnElement="StartEvent_1">
                    <dc:Bounds x="173" y="102" width="36" height="36" />
                </bpmndi:BPMNShape>
                </bpmndi:BPMNPlane>
            </bpmndi:BPMNDiagram>
            </bpmn:definitions>'
        ]);
    }

    public function updateWorkflow($predicate, $data, $create = false)
    {
        return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS, $predicate, $data, $create);
    }

    public function deleteWorkflow($predicate)
    {
        return $this->dataRepo->deleteEntity(DataRepositoryInterface::TABLE_PROCESS, $predicate);
    }

    public function getWorkflows($predicate = null, $columns = null)
    {
        //if ($predicate)
            return $this->toArray($this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS, $predicate, $columns));
        //return $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS, ['id',]);
    }

    public function toArray($collection)
    {
        if(ProcessLogicInterface::CONFIG_RETURN_ARRAY)
            return $collection->toArray();
        return $collection;
    }

    public function getWorkflowTypes($predicate)
    {
        return $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_TYPE, $predicate);
    }

    public function getSubprocessMetaWorkflow($workflow, $state)
    {
        $state = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $state, 'ws_pro_id' => $workflow->id]);
        $case = 0;
        $workflow = 0;
        $startId = 0;
        $starts = 0;
        if (!$state) {
            return ['case' => $case, 'start' => $startId, 'workflow' => $workflow, 'starts' => $starts];
        }

        if ($opts = $state->options) {
            if ($hasCases = array_key_exists("cases", $opts)) {
                $case = end($opts['cases']);
            }

            if ($hasStart = array_key_exists("start", $opts)) {
                $start = $opts['start'];
                $workflow = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS, $state->meta_value);
                $starts = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_STATE, ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflow->id]);
                $startId = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $start, 'ws_pro_id' => $workflow->id])->id;
            }
        }
        return ['case' => $case, 'start' => $startId, 'workflow' => $workflow, 'starts' => $starts];
    }

    public function getSubprocessMetaCase($case, $state)
    {
        $state = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, ['element_type' => 3, 'element_name' => $state, 'case_id' => $case->id]);

        if ($opts = $state->options) {
            if ($hasCases = array_key_exists("cases", $opts)) {
                $case = end($opts['cases']);
            } else {
                $case = null;
            }
            $start = $opts['start'];
            $workflow = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS, $state->meta_value);
            $starts = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_STATE, ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflow->id]);
            $startId = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $start, 'ws_pro_id' => $workflow->id])->id;
            return ['case' => $case, 'start' => $startId, 'workflow' => $workflow, 'starts' => $starts];
        }
    }

    public function getLastPartState()
    {
        if ($this->test == true) {
            $lastPart = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, ['ws_pro_id' => $this->wid]);
        } else {
            $lastPart = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_PART, ['case_id' => $this->id]);
        }
            //$state = $lastPart -> state;
            //$lastPart -> delete();
        $this->deletePart($lastPart->id);
        return $lastPart;
    }

    public function loadSubProcess($caseId)
    {
        $case = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $caseId);

        if ($case->status != 'end') {
            $this->backupStatus = $this->getStatus();
            $this->subProcess = true;
            $this->setCase($case);
        }
    }

    public function findWorkflowPart($predicate)
    {
        if ($this->test == true) {
            $predicate = array_merge($predicate, ['ws_pro_id' => $this->id]);
            return $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, $predicate);
        } else {
            $predicate = array_merge($predicate, ['case_id' => $this->id]);
            return $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_PART, $predicate);
        }
    }

    public function deletePart($id)
    {
        if ($this->test == true) {
            return $this->dataRepo->deleteEntity(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, ['id' => $id]);
        } else {
            return $this->dataRepo->deleteEntity(DataRepositoryInterface::TABLE_PROCESS_PART, ['id' => $id]);
        }
    }

    public function countWorkflowPart()
    {
        if ($this->test == true) {
            return $this->dataRepo->countEntity(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, ['ws_pro_id' => $this->id]);
        } else {
            return $this->dataRepo->countEntity(DataRepositoryInterface::TABLE_PROCESS_PART, ['case_id' => $this->id]);
        }
    }

    public function deleteCurrentPart()
    {
        if ($this->part != null) {
            $this->partId = $this->part->id;
            if ($this->test) {
                $this->dataRepo->deleteEntity(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, ['id' => $this->partId]);
            } else {
                $this->dataRepo->deleteEntity(DataRepositoryInterface::TABLE_PROCESS_PART, ['id' => $this->partId]);
            }

            $this->part = null;
        }
    }

    public function getCurrentState($state = null)
    {
        if (!$state) {
            $state = $this->state;
        }
        try {
            if (!$this->baseTable) {
                $s = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, ['case_id' => $this->getCaseId(), 'element_type' => 1, 'element_name' => $state]);
                $opts = $s->options;
                $s->type = $opts['type'];
                $s->next_wid = $opts['next_wid'];
                $s->next_type = $opts['next_type'];
                $s->text = $opts['text'];
                return new ProcessResponse($s ? true : false, $s, 'OK');
            }

            $s = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $state, 'ws_pro_id' => $this->wid]);
            return new ProcessResponse($s ? true : false, $s, 'OK');
        } catch (\Exception $e) {
            return new ProcessResponse(false, 'Exception occurred', 'OK');
        }
    }

    public function isEndedWorkflow()
    {
        $check = $this->getCurrentState()->entity->type == 'bpmn:EndEvent' ? true : false;

        if ($check && $this->status == 'parted') {
            $this->deleteCurrentPart();

            $remainingParts = $this->countWorkflowPart();

            // if ($remainingParts == 1) {
            //     $this -> last_part = $this -> getLastPartState();

            //     // $this -> state = $last -> state;

            //     // if(isset($last -> status))
            //     //     $this -> status = $last -> status;
            //     // else
            //     //     $this -> status = 'working';
            //     return ProcessLogicInterface::WORKFLOW_BACK_TO_WORKING;
            // }

            if ($remainingParts == 0) {
                $this->status = 'end';
                return ProcessLogicInterface::WORKFLOW_ENDED;
            }

            return ProcessLogicInterface::WORKFLOW_PART_ENDED;
        }

        if ($check && $this->status != 'parted') {
            $this->status = 'end';
            return ProcessLogicInterface::WORKFLOW_ENDED;
        }

        return $this->status == 'parted' ? ProcessLogicInterface::WORKFLOW_PART_WORKING : ProcessLogicInterface::WORKFLOW_WORKING;
    }

    public function takePic()
    {
        //$workflow = BpmsWorkflow::find($case -> ws_pro_id);

        if (!$this->workflow->wsvg) {
            return 'NaN';
        }
        $html = $this->workflow->wsvg;
        $crawlerBase = new Crawler;
        $crawlerBase->addHTMLContent($html, 'UTF-8');

        $crawler = $crawlerBase->filter('.djs-group');
        $crawler2 = $crawlerBase->filter('.djs-drag-group')->each(function (Crawler $crawler) {
            foreach ($crawler as $node) {
                $node->parentNode->removeChild($node);
            }
        });

        foreach ($crawler as $domElement) {
            $element_id = $domElement->firstChild->getAttribute('data-element-id');

            if ($element_id == $this->state && $this->status != "parted") {
                $req = $domElement->firstChild->firstChild->firstChild->setAttribute('style', 'stroke: black; stroke-width: 2px; fill: lime !important;');
            }

            $element_id = $domElement->firstChild->setAttribute('class', '');
        }

        return $crawlerBase->html();
    }

    public function getTransitionMeta($tid)
    {
        try {
            if ($this->test == true || $this->baseTable == true) {
                $m = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, $tid)->meta;
                return new ProcessResponse($m ? true : false, $m, 'OK');
            } else {
                $m = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, ['element_type' => 2, 'element_id' => $tid, 'case_id' => $this->id]);
                return new ProcessResponse(true, $m->meta_value, 'OK');
            }
        } catch (\Exception $e) {
            return new ProcessResponse(false, 'No condition is provided', 'OK');
        }
    }

    public function getCaseId()
    {
        return $this->id;
    }

    public function getSubProcessMeta($stateWID)
    {
        try {
            if ($this->test == true) {
                $res = $this->getCurrentState($stateWID);
                $result = $res->isSuccess;
                if ($result) {
                    $res = $res->entity;
                }
                $workflowId = $res->meta_value;
                $hasCases = array_key_exists("cases", $res->options);
                $opts = $res->options;
                $caseId = $hasCases ? end($opts['cases']) : null;
                $startState = $opts['start'];
            } else {
                $res = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, ['element_type' => 3, 'element_name' => $stateWID, 'case_id' => $this->getCaseId()]);
                $result = $res ? true : false;
                $workflowId = $res->meta_value;
                $opts = $res->options;
                $hasCases = array_key_exists("cases", $opts);
                $caseId = $hasCases ? end($opts['cases']) : null;
                $startState = $opts['start'];
            }

            return new ProcessResponse($result && $workflowId ? true : false, ['workflow' => $workflowId, 'case' => $caseId, 'start' => $startState], 'OK');
        } catch (\Exception $e) {
            return new ProcessResponse(false, 'No subprocess is provided', 'OK');
        }
    }

    public function setSubProcessMeta($stateWID, $caseId)
    {
        try {
            if ($this->test == true) {
                $this->updateStateOptions($stateWID, $caseId);
            } else {
                $this->setMetaOptions($stateWID, 'cases', $caseId, 3);
            }
        } catch (\Exception $e) {
            return new ProcessResponse(false, 'Exception occurred', 'OK');
        }
    }

    public function setStateMeta($stateWID, $data)
    {
        $state = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID, 'ws_pro_id' => $this->wid]);

        if ($this->test) {
            $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
            $opts = $state->options;
        } else {
            $predicate = ['element_type' => 1, 'element_id' => $state->id, 'element_name' => $stateWID, 'case_id' => $this->id];
            $meta = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate);
            $opts = $meta->options;
        }

        if (!isset($opts)) {
            $opts = array();
        }

        if (isset($data['users'])) {
            $opts['users'] = $data['users'];
        }

        if (isset($data['back'])) {
            $opts['back'] = $data['back'];
        }

        if (isset($data['name'])) {
            $opts['name'] = $data['name'];
        }
            //To add one form to forms
        if (isset($data['form'])) {
            $opts['forms'][] = $data['form'];
        }
            //To replace all forms
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
            return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, $predicate, $dataTemp, true);
        } else {
            return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate, $dataTemp);
        }
    }

    public function deleteStateMeta($stateWID, $option, $data)
    {
        $state = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID, 'ws_pro_id' => $this->wid]);

        if ($this->test) {
            $opts = $state->options;
            $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
        } else {
            $predicate = ['element_type' => 1, 'element_id' => $state->id, 'element_name' => $stateWID, 'case_id' => $this->id];
            $meta = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate);
            $opts = $meta->options;
        }

        if ($option == 'forms') {
            $opts['forms'] = array_values(array_diff($opts['forms'], $data));
        } else {
            return;
        }

        $dataTemp['options'] = $opts;

        if ($this->test) {
            return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, $predicate, $dataTemp);
        } else {
            return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate, $dataTemp);
        }
    }

    public function setTransitionMeta($data)
    {
        $from = $data['from'];
        $to = $data['to'];
        $meta_value = $data['meta'];

        if (isset($data['meta_json'])) {
            $opts['meta_json'] = $data['meta_json'];
        }

        $order = $data['order'];
        $gate = $data['gate'];
        $predicate = ['ws_pro_id' => $this->wid, 'from_state' => $from, 'to_state' => $to];


        if ($this->test) {
            $predicate = ['ws_pro_id' => $this->wid, 'from_state' => $from, 'to_state' => $to];
            $data = ['meta' => $meta_value, 'gate_wid' => $gate, 'order_transition' => $order];

            if (isset($opts)) {
                $data['options'] = $opts;
            }

            return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, $predicate, $data);
        }

        $transition = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, $predicate);
        $predicate = ['case_id' => $this->id, 'element_name' => $gate, 'element_type' => 2, 'element_id' => $transition->id];
        $data = ['meta_value' => $meta_value, 'meta_type' => 1];

        if (isset($opts)) {
            $data['options'] = $opts;
        }
        return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate, $data);
    }

    public function getStateMeta($stateWID)
    {
        if ($this->test) {
            $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
            $meta = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, $predicate);
        } else {
            $predicate = ['element_name' => $stateWID, 'case_id' => $this->id];
            $meta = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate);
        }

        if (!$meta)
            return;

        $opts = $meta->options;
        
            //Is needed when there is no meta on state!
        $res = array();

        if (isset($opts['back'])) {
            $res['back'] = $opts['back'];
        }

        if (isset($opts['name'])) {
            $res['name'] = $opts['name'];
        }

        if (isset($opts['forms'])) {
            $res['forms'] = $opts['forms'];
        }

        if (isset($meta->meta_type)) {
            $res['type'] = $meta->meta_type;
        }

        if (isset($meta->meta_value)) {
            $res['value'] = $meta->meta_value;
        }

        return $res;
    }

    public function getTransitionMetaUI()
    {
        return;
    }

    public function updateStateOptions($stateWID, $caseId)
    {
        $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
        $s = $this->getCurrentState($stateWID);
        $opts = $s->entity->options;
        $opts['cases'][] = $caseId;

        $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, $predicate, ['options' => $opts]);
    }

    public function setMetaOptions($stateWID, $option, $data, $type = 1)
    {
        $predicate = ['element_type' => $type, 'element_name' => $stateWID, 'case_id' => $this->getCaseId()];
        $m = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate);
        $opts = $m ? $m->options : null;

        if ($option == 'cases') {
            $opts['cases'][] = $data;
        }

        if ($option == 'forms') {
            if (isset($opts['forms'])) {
                $ids = array_column($opts['forms'], 'id');
                $found_key = array_search($data['id'], $ids);
                if ($found_key !== false) {
                    $opts['forms'][$found_key] = $data;
                } else {
                    $opts['forms'][] = $data;
                }
            } else {
                $opts['forms'][] = $data;
            }
        }


        $data = ['options' => $opts];
        
        // if (! $m) {
        //     $data['element_id'] = 0;
        // }

        $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate, $data, true);
    }

    public function getMetaOptions($stateWID, $option, $type = 1)
    {
        $predicate = ['element_type' => $type, 'element_name' => $stateWID, 'case_id' => $this->getCaseId()];
        $m = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate);
        $opts = $m->options;

        if (isset($opts[$option])) {
            return $opts[$option];
        } else {
            return null;
        }
    }

    public function AddWorkflowPart($tid, $gid, $from)
    {
        $foundPart = $this->findWorkflowPart(['transition_id' => $tid]);

        $this->checkNext($from);

        if (!$foundPart) {
            if ($this->test == true) {
                $data = ['from' => $from, 'state' => $from, 'to' => 'NaN', 'transition_id' => $tid, 'gate_id' => $gid, 'ws_pro_id' => $this->wid, 'is_ended' => false];
                $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, $data);
            } else {
                $data = ['from' => $from, 'state' => $from, 'to' => 'NaN', 'transition_id' => $tid, 'gate_id' => $gid, 'case_id' => $this->id];

                $data['user_from'] = $this->getMetaReq();
                $userId = $this->getNextUser();
                if (is_array($userId)) {
                    $data['user_current'] = -1;
                    $opts['users'] = $userId;
                    $data['status'] = 'unassigned';
                } else {
                    $data['user_current'] = $userId;
                }

                if (isset($opts)) {
                    $data['system_options'] = $opts;
                }

                $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_PART, $data);
            }
        }
    }

    public function addActivityLog($data = null)
    {
        if ($data) {
            $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_ACTIVITY, $data);
            return $activityId;
        }

        $type = $this->getEvent();

        $case = $this->getCase();
        $last = $case->activity_id;
        $user_from = $this->getMetaReq() ? : 0;

        $data = ['case_id' => $this->id, 'type' => $type, 'transition_id' => $this->transitionFired, 'comment' => $this->comment, 'pre' => $last, 'part_id' => $this->partId ? : 0, 'user_id' => $user_from];

        $user_current = $this->getNextUser() ? : 0;

        if (is_array($user_current)) {
            $data['user_id'] = -1;
            $opts['users'] = $user_current;
        } else {
            $data['user_id'] = $user_current;
        }

        $opts['text'] = $this->next_state_text;

        $data['options'] = $opts;

        if ($type == ProcessLogicInterface::WORKFLOW_ENDED) {
            $data['finished_at'] = date("Y-m-d H:i:s");
        }

        if ($last) {
            $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_ACTIVITY, ['id' => $last], ['finished_at' => date("Y-m-d H:i:s"), 'user_id' => $user_from]);
        }

        if ($type == ProcessLogicInterface::WORKFLOW_BACKED) {
            $lastActivity = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_ACTIVITY, $last);
            $data = ['case_id' => $this->id, 'type' => $type, 'transition_id' => $lastActivity->transition_id, 'comment' => $this->comment ? : 'WORKFLOW_BACKED', 'pre' => $last, 'part_id' => $this->partId ? : 0, 'user_id' => $user_from];
        }

        $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_ACTIVITY, $data);

        if ($type != ProcessLogicInterface::WORKFLOW_BACKED) {
            $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, ['id' => $this->id], ['activity_id' => $activityId]);
        } else {
            $t = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, $lastActivity->transition_id);
            $preActivity = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_ACTIVITY, $lastActivity->pre);
            $data = ['activity_id' => $lastActivity->pre, 'state' => $t->from_state, 'user_current' => $preActivity->user_id, 'user_from' => $user_from];
            $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, ['id' => $this->id], $data);
        }
    }

    public function getActivityLog($caseId = null, $fromDate = null, $toDate = null)
    {
        if ($caseId) {
            $case = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $caseId);
            $wf = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS, $case->ws_pro_id);
        } else {
            //$case = $this -> getCase();
            $caseId = $this->getCaseId();
            $wf = $this->getWorkflow();
        }

        $predicate = ['case_id' => $caseId];
        $title = $wf->name;
        $acts = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_ACTIVITY, $predicate);

        $res = [];
        $index = 1;
        foreach ($acts as $activity) {
            //$diff = round(abs($activity -> finished_at - $activity -> created_at) / 60,2);
            $temp =
                [
                'index' => $index,
                'case_id' => $caseId,
                'title' => $title,
                'task' => $activity->options['text'],
                'user_id' => $activity->user_id != -1 ? $activity->user_id : $activity->options['users'],
                'start_date' => $activity->created_at,
                'end_date' => $activity->finished_at,
                'duration' => Carbon::parse($activity->finished_at)->diffInMinutes(Carbon::parse($activity->created_at)),
                'status' => $activity->finished_at ? 'تکمیل شده' : 'در حال انجام'
            ];

            $res[] = $temp;
            $index++;
        }
        return $res;
    }

    public function prepareStateMachine($stateWID = null)
    {
        $this->states = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_STATE, ['ws_pro_id' => $this->wid]);
        $this->transitions = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, ['ws_pro_id' => $this->wid]);

        if ($stateWID == null) {
            $stateWID = $this->state;
        }

        $document = new Document;

        $sm = new StateMachine();

        foreach ($this->states as $state) {
            if ($state->position_state == ProcessLogicInterface::POSITION_START) {
                $sm->addState(new State($state->wid, StateInterface::TYPE_INITIAL));
            } elseif ($state->position_state == ProcessLogicInterface::POSITION_END) {
                $sm->addState(new State($state->wid, StateInterface::TYPE_FINAL));
            } else {
                $sm->addState($state->wid);
            }
        }

        foreach ($this->transitions as $transition) {
            $sm->addTransition((string)$transition->id, $transition->from_state, $transition->to_state);
        }

        $document->setFiniteState($stateWID);
                
        // Initialize
        $sm->setObject($document);
        $sm->initialize();

        $this->sm = $sm;
    }

    public function getStatus($inputArray = null)
    {
        $user = isset($inputArray['user']) ? $inputArray['user'] : null;
        $state = isset($inputArray['state']) ? $inputArray['state'] : null;

        if ($this->subProcess) {
            return $this->backupStatus;
        }

        $s = $this->getCurrentState($state);
        $form = isset($s->entity->options['forms'][0]) ? $s->entity->options['forms'][0] : null;

        if ($user && $state) {
            $userId = $this->getNextUserByType($s->entity, $save = true, $rival = $user);
            if (isset($userId)) {
                $predicate = ['element_name' => $state, 'case_id' => $this->id];
                $data = ['meta_value' => $userId];
                $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_META, $predicate, $data);
                if ($this->status == 'parted') {
                    return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_PART, ['state' => $state], ['status' => 'working', 'user_current' => $userId]);
                } else {
                    return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, ['id' => $this->id], ['status' => 'working', 'user_current' => $userId]);
                }
            }
        }

        if ($this->status == 'parted') {
            $data = ['state' => $this->status, 'meta' => $this->getPartsStateWID(), 'base' => $this->getBaseState()];
        } else {
            $data = ['state' => $this->status, 'meta' => $this->state];
        }

        if ($form) {
            $data['form'] = $form;
            if ($state_vars = $this->getCaseOption('state_vars', ['id' => $form])) {
                $data['state_vars'] = $state_vars;
            }
        }

        if ($vars = $this->getCaseOption('vars')) {
            $data['vars'] = $vars;
        }
        return $data;
    }

    public function getPartsStateWID()
    {
        if ($this->test == true) {
            $parts = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, ['ws_pro_id' => $this->wid]);
        } else {
            $parts = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_PART, ['case_id' => $this->id]);
        }

        $result = array();
        foreach ($parts as $part) {
            $result[] = $part->state;
        }
        return $result;
    }

    public function getBaseState()
    {
        return $this->backupState;
    }

    public function getWorkflow()
    {
        return $this->workflow;
    }

    public function getWID()
    {
        return $this->wid;
    }

    public function getCurrentStateStateMachine()
    {
        return $this->sm->getCurrentState()->getName();
    }

    public function isEndGate($gateWID)
    {
        return $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_GATE, ['wid' => $gateWID, 'ws_pro_id' => $this->getWID()])->is_end;
    }

    public function setPart($state = null)
    {
        if ($this->test == true) {
            $this->part = $this->dataRepo->findEntityByRandom(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, ['ws_pro_id' => $this->wid]);
        } else {
            //$this -> part = $this ->case -> parts() ->inRandomOrder()->first();
            $this->part = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_PART, ['case_id' => $this->id, 'state' => $state ? $state : $this->getStateReq()]);
        }

        $this->state = $this->part->state;
        $this->partId = $this->part->id;
        $this->checkSubProcess();
    }

    public function saveParsedData($dataArray)
    {
        $transitions = $dataArray['transitions'];
        $states = $dataArray['states'];
        $gateways = $dataArray['gateways'];
        $process = $dataArray['ws_pro_id'];
        $wcontent = isset($dataArray['wcontent']) ? $dataArray['wcontent'] : null;

        if ($wcontent) {
            $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS, ['id' => $process], ['wbody' => $wcontent['wbody'], 'wsvg' => $wcontent['wsvg']]);
        }

        $to_keep = [];

        foreach ($transitions as $transition) {
            $predicate = ['from_state' => $transition['from'], 'to_state' => $transition['to'], 'ws_pro_id' => $process];
            $data = ['gate_wid' => null, 'from_state' => $transition['from'], 'to_state' => $transition['to'], 'ws_pro_id' => $process];

            $tid = $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, $predicate, $data, true);
            $to_keep[] = $tid;
        }

        if ($to_keep) {
            $this->dataRepo->deleteNotIn(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, ['ws_pro_id' => $process], $to_keep);
            $to_keep = [];
        }

        foreach ($states as $state) {
            $data = ['wid' => $state['id'], 'type' => $state['type'], 'position_state' => $state['position'], 'text' => $state['name'], 'next_wid' => $state['next']['id'], 'next_type' => $state['next']['type'], 'ws_pro_id' => $process];
            $sid = $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $state['id']], $data, true);
            $to_keep[] = $sid;
        }

        if ($to_keep) {
            $this->dataRepo->deleteNotIn(DataRepositoryInterface::TABLE_PROCESS_STATE, ['ws_pro_id' => $process], $to_keep);
            $to_keep = [];
        }

        foreach ($gateways as $gate) {
            foreach ($gate['inState'] as $in) {
                foreach ($gate['outState'] as $out) {
                    $foundTransition = $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, ['from_state' => $in, 'to_state' => $out], ['gate_wid' => $gate['id']]);
                }
            }

            $data = ['type' => $gate['type'], 'wid' => $gate['id'], 'is_end' => $gate['isEnd'], 'ws_pro_id' => $process];
            $gid = $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_GATE, ['wid' => $gate['id']], $data, true);
            $to_keep[] = $gid;
        }

        if ($to_keep) {
            $this->dataRepo->deleteNotIn(DataRepositoryInterface::TABLE_PROCESS_GATE, ['ws_pro_id' => $process], $to_keep);
        }

        return true;
    }

    public function saveChanges($type, $message = null)
    {
        $this->setEvent($type);

        if ($this->changed) {
            if ($this->test == true) {
                $this->saveWorkflow();
            } else {
                $this->saveCase();
            }
        }

        switch ($type) {
            case ProcessLogicInterface::WORKFLOW_PART_CREATED:
                break;

            case ProcessLogicInterface::WORKFLOW_NO_PATH:
                return ['state' => 'error', 'message' => 'WORKFLOW_NO_PATH', 'meta' => $this->state, 'base' => $this->state];
                break;

            case ProcessLogicInterface::WORKFLOW_WORKING:
                //$this -> addActivityLog();
                break;

            case ProcessLogicInterface::WORKFLOW_PART_WORKING:
                //$this -> addActivityLog();
                break;

            // case ProcessLogicInterface::WORKFLOW_BACK_TO_WORKING:
            //     //$this -> addActivityLog();
            //     break;

            case ProcessLogicInterface::WORKFLOW_STARTED:
                //$this -> addActivityLog();
                break;

            case ProcessLogicInterface::WORKFLOW_ENDED:
                 //$this -> addActivityLog();
                break;

            case ProcessLogicInterface::WORKFLOW_PART_ENDED:
                //$this -> addActivityLog();
                break;

            case ProcessLogicInterface::WORKFLOW_NO_META:
                return ['state' => 'error', 'message' => 'No meta', 'meta' => $this->state];
                break;

            case ProcessLogicInterface::WORKFLOW_PART_ISNULL:
                return ['state' => 'error', 'message' => 'Part is null', 'meta' => $this->state, 'base' => $this->state];
                break;

            case ProcessLogicInterface::WORKFLOW_WAIT_FOR_ANOTHER:
                //return;
                break;

            case ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR:
                return ['state' => 'error', 'message' => $message];

            case ProcessLogicInterface::WORKFLOW_LONELY_PART:
                return ['state' => 'error', 'message' => 'WORKFLOW_LONELY_PART'];

            default:
        }

        if (!$this->test) {
            $this->addActivityLog();
            $this->doSomething($type);
        }

        return $this->getStatus();
    }

    public function doSomething($type)
    {
        //To be implemented
    }

    public function saveWorkflow()
    {
        $data = array();

        if ($this->status == 'parted') {
            $this->savePart();
        } else {
            $data['state'] = $this->state;
        }

        $predicate = ['id' => $this->wid];
        $data['status'] = $this->status;
        
        // if ($this -> getEvent() == ProcessLogicInterface::WORKFLOW_BACK_TO_WORKING) {
        //     $data['state'] = $this -> last_part -> state;
        //     $data['status'] = 'working';
        // }
        $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS, $predicate, $data);
    }

    public function saveCase()
    {
        $data['status'] = $this->status;
        $data['user_from'] = $this->getMetaReq() ? $this->getMetaReq() : 0;
        $data['form_id'] = $this->next_form;
        $userId = $this->getNextUser() ? $this->getNextUser() : 0;

        if (is_array($userId)) {
            $data['user_current'] = -1;
            $data['status'] = 'unassigned';
            $data['system_options']['users'] = $userId;
        } else {
            $data['user_current'] = $userId;
        }

        if ($this->status == "parted") {
            $this->savePart($data);
            $data['status'] = "parted";
            // if ($this -> getEvent() == ProcessLogicInterface::WORKFLOW_BACK_TO_WORKING) {
            //     $part = $this -> last_part;
            //     $data['state'] =  $part -> state;
            //     if (isset($part -> status)) {
            //         $this -> status = $part -> status;
            //     } else {
            //         $this -> status = 'working';
            //     }
                
            //     $data['user_current'] = $part -> user_current;
                
            //     if ($part -> user_current == -1) {
            //         $data['system_options']['users'] = $part -> system_options['users'];
            //     }
            //     $data['user_from'] = $part -> user_from;
            //     $data['form_id'] = $part -> form_id;
            // }
        } else {
            $data['state'] = $this->state;
        }

        $predicate = ['id' => $this->id];
        $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_CASE, $predicate, $data);
    }

    public function savePart($data = null)
    {
        if ($this->part != null) {
            $data['state'] = $this->state;
            if ($this->test) {
                $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_FAKEPART, ['ws_pro_id' => $this->wid, 'id' => $this->part->id], $data);
            } else {
                $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_PART, ['case_id' => $this->id, 'id' => $this->part->id], $data);
            }
        }
    }

    public function nextLogic()
    {
        if ($this->status == "parted") {
            $this->setPart();
        }

        if ($this->status == 'end' && $this->test) {//workflow to restart
            $this->state = $this->getFirstStateWID();
            $this->status = 'working';
            $this->checkNext();
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_RESTARTED);
        }

        if ($this->status == 'end') {//case to restart
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_ENDED_BEFORE);
        }

        if ($this->status == "created" && !$this->state) {//case to start

            $this->state = $this->getFirstStateWID();
            $this->status = 'working';

            $this->checkNext();
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_STARTED);
        }

        if ($this->status == 'created') {
            $this->status = 'working';
        }

        $this->checkSubprocess();

        $currentState = $this->getCurrentState();

        //$this -> prepareStateMachine();

        $isParellel = false;
        //$checkAllTransitions = false;
        //$partEnded = false;
        //$partWorkflow = 0;
        $next_type = $currentState->entity->next_type;

        switch ($next_type) {
            case "bpmn:ExclusiveGateway":
                break;

            case 'bpmn:EndEvent':
                break;

            case "bpmn:ParallelGateway":
                $isPartedBefore = $this->status == 'parted' ? true : false;
                // $isEnd = (bool)$this -> isEndGate($currentState-> entity -> next_wid);
                // $isPartCreated = $isEnd ? false : true;
                $this->status = 'parted';
                break;

            case "bpmn:InclusiveGateway":
                $isPartedBefore = $this->status == 'parted' ? true : false;
                //$checkAllTransitions = true;
                $this->status = 'parted';
                $isParellel = true;
                break;
            default:
        }

        try {
            //$available =  $this -> sm -> getCurrentState() -> getTransitions();
            $tis = $this->getAvailableTransitions();
            foreach ($tis as $t) {
                $available[$t->id] = $t;
            }

            // if (empty($available)) {
            //     if ($this -> status == 'parted') {
            //         $this -> part -> delete();
            //         $this -> part = null;
                    
            //         if ($this -> countWorkflowPart() == 0) {
            //             $this -> status = 'end';
            //             return $this -> saveChanges(ProcessLogicInterface::WORKFLOW_ENDED);
            //         } else {
            //             return $this -> saveChanges(ProcessLogicInterface::WORKFLOW_PART_ENDED);
            //         }
            //     }

            //      $this -> status = 'end';
            //      return $this -> saveChanges(ProcessLogicInterface::WORKFLOW_ENDED);
            // }

            if (count($available) > 1) {
                foreach ($available as $toGoId => $transition) {
                    //$toGoTransition = $this -> dataRepo -> getEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, $toGoId);
                    $toGoTransition = $transition;

                    if (!$toGoTransition) {
                        continue;
                    }
                        
                    //if ($toGoTransition -> type == ProcessLogicInterface::TRANSITION_NORMAL || $toGoTransition -> type == ProcessLogicInterface::TRANSITION_OR_SPLIT) {
                    if ($next_type == "bpmn:ExclusiveGateway" || $next_type == "bpmn:InclusiveGateway") {
                        $language = new ExpressionLanguage();

                        // $meta = $this -> getTransitionMeta($toGoId);
                        // if (!$meta -> isSuccess) {
                        //     $this -> changed = false;
                        //     return $this -> saveChanges(ProcessLogicInterface::WORKFLOW_NO_META);
                        // }

                        $meta = $transition->meta;
                        if (!$meta) {
                            $this->changed = false;
                            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_NO_META);
                        }

                        //$toGoCondition = $meta -> entity;

                        try {
                            if (!$vars = $this->getVars()) {
                                $vars = isset($this->getCase()->options['vars']) ? $this->getCase()->options['vars'] : null;
                            }
                            $ret = $language->evaluate($meta, $vars);
                        } catch (\Exception $e) {
                            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR, $e->getMessage());
                        }

                        if ($ret == true && $next_type == "bpmn:ExclusiveGateway") {// before checkAllTransitions == false
                            $nextTransition = $toGoTransition->id;
                            break;
                        } elseif ($ret == true && $next_type == "bpmn:InclusiveGateway") { // before checkAllTransitions == true
                            $this->AddWorkflowPart($toGoTransition->id, $toGoTransition->gate_wid, $toGoTransition->to_state);
                            $isParellel = true;
                            if ($isPartedBefore) {
                                $this->deleteCurrentPart();
                            }
                        }
                    } //end of type == 0
                    else {
                        $this->AddWorkflowPart($toGoTransition->id, $toGoTransition->gate_wid, $toGoTransition->to_state);
                        $isParellel = true;
                        if ($isPartedBefore) {
                            $this->deleteCurrentPart();
                        }
                    }
                }// end of foreach
            } //end of main if available count > 1

            else { //when we have one path to go!

                $toGoId = key($available);
                //$toGoTransition = $this -> dataRepo -> getEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, $toGoId);

                $toGoTransition = reset($available);
                if (!$toGoTransition) {
                    return;
                }

                //if ($toGoTransition -> type == ProcessLogicInterface::TRANSITION_AND_JOIN) {
                if ($next_type == "bpmn:ParallelGateway" || $next_type == "bpmn:InclusiveGateway") {
                    $foundPart = $this->findWorkflowPart(['state' => $toGoTransition->from_state]);

                    if (!$foundPart) {
                        $this->changed = false;
                        return $this->saveChanges(ProcessLogicInterface::WORKFLOW_LONELY_PART);
                    }
                    
                    //$foundPart -> is_ended = true;

                    //$foundPart -> save();

                    $trans = $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, ['gate_wid' => $toGoTransition->gate_wid]);

                    $done = true;

                    foreach ($trans as $t) {
                        $friendPart = $this->findWorkflowPart(['state' => $t->from_state]);
                        if ($friendPart == null && ($this->state != $t->from_state)) {
                            $done = false;
                        }
                    }

                    if ($done == false) {//WORKFLOW_WAIT_FOR_ANOTHER
                        return $this->saveChanges(ProcessLogicInterface::WORKFLOW_WAIT_FOR_ANOTHER);
                    } else {
                        foreach ($trans as $t) {
                            if ($t->id == $toGoId) {
                                continue;
                            }

                            $friendPart = $this->findWorkflowPart(['state' => $t->from_state]);

                            if ($friendPart != null) {
                                $this->deletePart($friendPart->id);
                            }
                        }

                        $nextTransition = $toGoTransition->id;
                        //$partEnded = true;

                        //$partWorkflow = $this -> countWorkflowPart();
                    }
                    //} elseif ($toGoTransition -> type != ProcessLogicInterface::TRANSITION_AND_JOIN) {
                } else {
                    $nextTransition = $toGoId;
                }
            }

            if ($isParellel) {
                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_PART_CREATED);
            }

            if (isset($nextTransition)) {
                $this->setTransitionFired($nextTransition, $available);
                //$this -> sm -> apply($nextTransition);
            } else {
                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_NO_PATH);
            }
            
            //$this -> state = $this -> sm -> getCurrentState() -> getName();
            $this->checkNext();
        } catch (\Finite\Exception\StateException $e) {
            echo $e->getMessage(), "\n";
        }

        return $this->saveChanges($this->isEndedWorkflow());
    }

    public function backLogic()
    {
        $case = $this->getCase();
        $state = $this->getCurrentState();

        if (!$state->isSuccess) {
            return ['error' => 'Back is not checked.'];
        }

        $back = isset($state->entity->options['back']) ? $state->entity->options['back'] : true;
        if (!$back) {
            return ['error' => 'Back is not enabled.'];
        }

        return $this->saveChanges(ProcessLogicInterface::WORKFLOW_BACKED);
        
        
        // $lastActivity = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_ACTIVITY, ['case_id' => $case->activity_id]);
        
        // if ($lastActivity -> type != ProcessLogicInterface::WORKFLOW_STARTED) {
        //     $t = $this->dataRepo->getEntity(DataRepositoryInterface::TABLE_PROCESS_TRANSITION, $lastActivity -> transition_id);
        //     //$currentState = $t -> to_state;
        
        //     // $meta = $this->dataRepo->findEntitiy(DataRepositoryInterface::TABLE_PROCESS_META, ['case_id' => $case->id,'element_name' => $currentState]);
                   
        //     // if ($meta == null) { //foreach user different rules apply!
        //     //     return ['error' => 'Back is not enabled.'];
        //     // }
        
        //     // if ($meta -> options['back']) {
        //         $backState = $t -> from;
        //         $case -> state = $backState;
        //         $case -> status = "working";
        //         $case -> activity_id = $last -> pre;
        //         $case -> save();
                
                //$this->setNextUser()
                //$data = ['case_id' => $case -> id, 'type' => ProcessLogicInterface::WORKFLOW_BACKED, ]
                //$this -> addActivityLog($case, 3, $last -> transition_id, 'workflow backed.');
        
                // return ['message' => 'Back to back!'];
            // } else {
            //     return ['error' => 'Back is not enabled.'];
            // }
        // } elseif ($last -> type == 2) {
        // }
        
        
        // return ['error' => 'Back is not available'];
    }

    public function getFirstStateWID()
    {
        $firstState = $this->dataRepo->findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $this->wid]);
        $firstStateWID = $firstState->wid;
        return $firstStateWID;
    }

    public function goNext($inputArray)
    {
        $metaReq = isset($inputArray['metaReq']) ? $inputArray['metaReq'] : null;
        $vars = isset($inputArray['vars']) ? $inputArray['vars'] : null;
        $stateReq = isset($inputArray['stateReq']) ? $inputArray['stateReq'] : null;
        $typeReq = isset($inputArray['typeReq']) ? $inputArray['typeReq'] : null;
        $commentText = isset($inputArray['commentText']) ? $inputArray['commentText'] : null;
        $form = isset($inputArray['form']) ? $inputArray['form'] : null;
        $user_manual = isset($inputArray['user_manual']) ? $inputArray['user_manual'] : null;

        if ($user_manual) {
            $this->user_manual = $user_manual;
        }

        if ($commentText) {
            $this->setComment($commentText);
        }

        if ($metaReq) {
            $this->setMetaReq($metaReq);
        }

        if ($vars) {
            $this->setVars($vars);
        }

        if ($stateReq) {
            $this->setStateReq($stateReq);
        }

        if ($this->isEligible($metaReq, $typeReq, $stateReq)) {
            if ($vars && !$this->test) {
                $this->setCaseOption('vars', $vars);
            }

            if ($form && !$this->test && $this->state) {
                $this->setMetaOptions($this->state, 'forms', $form);
                // $this -> setCaseOption('state_vars',
                // ['id' => $form['id'], 'vars' => ['A' => 3]]);
            }

            if ($this->isStateDone($form)) {
                if (ProcessLogicInterface::CONFIG_NEXT_PREVIEW && $form) {
                    $res['next'] = $this->getNextStep($vars);
                    $res['type'] = ProcessLogicInterface::NEXT_PREVIEW;
                    return $res;
                } else {
                    $res = $this->nextLogic();
                    $res['type'] = ProcessLogicInterface::NEXT_NEXT;
                    return $res;// get first form content
                }
            } else {
                return ['type' => ProcessLogicInterface::NEXT_FORM, 'next_form' => $this->next_form];//get next_from content
            }
        } else {
            return ['type' => ProcessLogicInterface::NEXT_BADACCESS];
        }
    }

    public function goBack($inputArray)
    {
        $metaReq = isset($inputArray['metaReq']) ? $inputArray['metaReq'] : null;
        if ($metaReq) {
            $this->setMetaReq($metaReq);
        }

        return $this->backLogic();
    }

    public function isStateDone($form)
    {
        if ($this->test) {
            return true;
        }

        $res = true;

        $s = $this->getCurrentState();
        if ($s->isSuccess) {
            $s = $s->entity;
        } else {
            return true;
        }

        $opts = $s->options;
        $forms = isset($opts['forms']) ? $opts['forms'] : null;
        if (!$forms) {
            return $res;
        }

        $current = isset($form['id']) ? $form['id'] : null;

        if ($current) {
            $key = array_search($current, $forms);
            if (array_key_exists($key + 1, $forms)) {
                $this->setNextForm($forms[$key + 1]);
                $res = false;
            }
        }

        return $res;
    }

    public function getForms($predicate, $columns = null)
    {
        if ($this->wid && !isset($predicate['ws_pro_id'])) {
            $predicate['ws_pro_id'] = $this->wid;
        }
        return $this->dataRepo->findEntities(DataRepositoryInterface::TABLE_PROCESS_FORM, $predicate, $columns);
    }

    public function addForm($data)
    {
        if ($this->wid && !isset($data['ws_pro_id'])) {
            $data['ws_pro_id'] = $this->wid;
        }
        return $this->dataRepo->createEntity(DataRepositoryInterface::TABLE_PROCESS_FORM, $predicate, $columns);
    }

    public function updateForm($predicate, $data)
    {
        if ($this->wid && !isset($predicate['ws_pro_id'])) {
            $predicate['ws_pro_id'] = $this->wid;
        }
        return $this->dataRepo->updateEntity(DataRepositoryInterface::TABLE_PROCESS_FORM, $predicate, $data);
    }

    public function deleteForm($predicate)
    {
        if ($this->wid && !isset($predicate['ws_pro_id'])) {
            $predicate['ws_pro_id'] = $this->wid;
        }
        return $this->dataRepo->deleteEntity(DataRepositoryInterface::TABLE_PROCESS_FORM, $predicate);
    }

    
    

    // public function getStateForms($stateWID, $type = null)
    // {
    //     $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //     if ($state) {
    //         return $this -> dataRepo -> findEntitiesByOrder(DataRepositoryInterface::TABLE_PROCESS_STATE_FORM, ['state_id' => $state -> id], 'view_order', 'asc');
    //     }
    // }
    
    // public function updateStateForm($stateWID, $predicate, $data)
    // {
    //     if (! isset($predicate['id'])) {
    //         $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         $predicate['state_id'] = $state -> id;
    //     }
        
    //     return $this -> dataRepo -> updateEntity(DataRepositoryInterface::TABLE_PROCESS_STATE_FORM, $predicate, $data);
    // }

    // public function addStateForm($stateWID, $data)
    // {
    //     if (! isset($data['state_id'])) {
    //         $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         $data['state_id'] = $state -> id;
    //     }
       
    //     $data['view_order'] = $this -> dataRepo -> countEntity(DataRepositoryInterface::TABLE_PROCESS_STATE_FORM, ['state_id' => $data['state_id'] ]) + 1;
    //     return $this -> dataRepo -> createEntity(DataRepositoryInterface::TABLE_PROCESS_STATE_FORM, $data);
    // }
    
    // public function deleteStateForm($stateWID, $predicate)
    // {
    //     if (! isset($predicate['id'])) {
    //         $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         $predicate['state_id'] = $state -> id;
    //     }
        
    //     return $this -> dataRepo -> deleteEntity(DataRepositoryInterface::TABLE_PROCESS_STATE_FORM, $predicate);
    // }

    // public function getStateTriggers($stateWID, $type)
    // {
    //     $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //     if ($state) {
    //         return $this -> dataRepo -> findEntitiesByOrder(DataRepositoryInterface::TABLE_PROCESS_STATE_TRIGGER, ['state_id' => $state -> id], 'run_order', 'asc');
    //     }
    // }
    
    // public function updateStateTrigger($stateWID, $predicate, $data)
    // {
    //     if (! isset($predicate['id'])) {
    //         $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         $predicate['state_id'] = $state -> id;
    //     }
    //     return $this -> dataRepo -> updateEntity(DataRepositoryInterface::TABLE_PROCESS_STATE_TRIGGER, $predicate, $data);
    // }
    
    // public function addStateTrigger($stateWID, $data)
    // {
    //     if (! isset($data['state_id'])) {
    //         $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         $data['state_id'] = $state -> id;
    //     }
        
    //     $data['run_order'] = $this -> dataRepo -> countEntity(DataRepositoryInterface::TABLE_PROCESS_STATE_TRIGGER, ['state_id' => $data['state_id'],'trigger_run_type' => $data['trigger_run_type']]) + 1;
    //     return $this -> dataRepo -> createEntity(DataRepositoryInterface::TABLE_PROCESS_STATE_TRIGGER, $data);
    // }
    
    // public function deleteStateTrigger($stateWID, $predicate)
    // {
    //     if (! isset($predicate['id'])) {
    //         $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         $predicate['state_id'] = $state -> id;
    //     }
        
    //     return $this -> dataRepo -> deleteEntity(DataRepositoryInterface::TABLE_PROCESS_STATE_TRIGGER, $predicate);
    // }

    // public function getFormTriggers($stateWID, $type)
    // {
    // }
    
    // public function updateFormTrigger($statesWID, $predicate, $data)
    // {
    //     if (! isset($predicate['state_form_id'])) {
    //         //$state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         //$predicate['state_id'] = $state -> id;
    //     }
    //     return $this -> dataRepo -> updateEntity(DataRepositoryInterface::TABLE_PROCESS_FORM_TRIGGER, $predicate, $data);
    // }
    
    // public function addFormTrigger($stateWID, $data)
    // {
    //     if (! isset($data['state_form_id'])) {
    //         $state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         //find form and generate state_form_ids
    //     }
        
    //     $data['run_order'] = $this -> dataRepo -> countEntity(DataRepositoryInterface::TABLE_PROCESS_FORM_TRIGGER, ['state_form_id' => $data['state_form_id'], 'trigger_run_type' => $data['trigger_run_type'] ]) + 1;
    //     return $this -> dataRepo -> createEntity(DataRepositoryInterface::TABLE_PROCESS_FORM_TRIGGER, $data);
    // }
    
    // public function deleteFormTrigger($stateWID, $predicate)
    // {
    //     if (! isset($predicate['id'])) {
    //         //$state = $this -> dataRepo -> findEntity(DataRepositoryInterface::TABLE_PROCESS_STATE, ['wid' => $stateWID]);
    //         //$predicate['state_id'] = $state -> id;
    //     }
        
    //     return $this -> dataRepo -> deleteEntity(DataRepositoryInterface::TABLE_PROCESS_FORM_TRIGGER, $predicate);
    // }
}

// class Document implements StatefulInterface
// {
//     private $state;

//     public function setFiniteState($state)
//     {
//         $this->state = $state;
//     }

//     public function getFiniteState()
//     {
//         return $this->state;
//     }
// }
