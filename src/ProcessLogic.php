<?php namespace Niyam\Bpms;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\DomCrawler\Crawler;
use Carbon\Carbon;
use Niyam\Bpms\Data\DataRepositoryInterface;
use Niyam\Bpms\Model\BpmsForm;
use Niyam\Bpms\Model\BpmsStateConfig;
use Niyam\Bpms\Model\BpmsState;
use Niyam\Bpms\Model\BpmsVariable;
use Niyam\Bpms\Model\BpmsWorkflow;
use Niyam\Bpms\Model\BpmsMeta;
use Niyam\Bpms\Model\BpmsActivity;
use Niyam\Bpms\Model\BpmsCase;



use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;


class ProcessLogic implements ProcessLogicInterface
{
    #region CONST
    const
        CONFIG_FILTER_DUPLICATE_CASE = true,
        CONFIG_FILTER_CREATE_UNIQUE_PROCESS = true,
        CONFIG_NEXT_PREVIEW = true,
        CONFIG_WORKFLOW_USE_FORM = false,
        CONFIG_BOOT_ELOQUENT = false,
        CONFIG_BOOT_DATABASE = 'bpms',
        CONFIG_BOOT_USERNAME = 'root',
        CONFIG_BOOT_PASSWORD = '';
    #endregion

    #region Members
    private $id = 0;

    protected $wid = 0;

    private $workflow;

    private $case;

    private $test = false;

    private $status;

    private $state;

    private $currentState;

    private $part;

    private $subProcess = false;

    private $backupStatus;

    private $backupState;

    private $event = ProcessLogicInterface::WORKFLOW_NO_EVENT;

    private $transitionFired = 0;

    private $comment;

    private $metaReq = null;

    private $user_name = 'NotSet';

    private $next_user = null;

    private $next_state = null;

    private $next_form = 0;

    private $vars = null;

    private $stateReq = null;

    private $partId;

    private $dataRepo;

    private $baseTable = false;

    private $user_manual = null;

    private $error = null;

    private $state_first_access = false;

    private $preview_next = null;


    #endregion

    #region SETTER & GETTER
    public function __construct(DataRepositoryInterface $dataRepo)
    {
        $this->dataRepo = $dataRepo;

        if (static::CONFIG_BOOT_ELOQUENT)
            CustomBoot::enable();
    }

    public function setCase($case, $baseTable = false)
    {
        $wf = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_WORKFLOW, $case->ws_pro_id);
        $this->test = false;
        $this->baseTable = $baseTable ? : !isset($case->system_options['copy']);
        $this->case = $case;
        $this->workflow = $wf;
        $this->id = $case->id;
        $this->wid = $wf->id;
        $this->status = $case->status;
        $this->state = $case->state;
        $getState = $this->getCurrentState($case->state);
        $this->currentState = $getState->isSuccess ? $getState->entity : null;
        $this->backupState = $this->state;
    }

    public function setCaseById($caseId, $baseTable = false)
    {
        if (!$caseId) {
            return;
        }
        $case = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_CASE, $caseId);
        $this->setCase($case);
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
        if (!$id) {
            return;
        }

        $wf = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_WORKFLOW, $id);
        $this->setWorkflow($wf);
    }

    public function getCase($caseId = null)
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

    public function setMetaReq($metaReq, $caseChange = true)
    {
        $this->metaReq = $metaReq;
        if (!$this->test && $caseChange)
            $this->case->user_from = $metaReq;
        if (!$this->user_name)
            $this->user_name = $this->getCustomUserDisplayName($metaReq);
    }

    public function setNextUser($next_user)
    {
        $this->next_user = $next_user;
        if (!$this->test)
            $this->case->current_user = $next_user;
    }

    public function setNextForm($next_form)
    {
        $this->next_form = $next_form;
        if (!$this->test)
            $this->case->form_id = $next_form;
    }

    public function getNextUser()
    {
        return $this->next_user ? : 0;
    }

    public function getMetaReq()
    {
        return $this->metaReq ? : 0;
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

    public function setEvent($event, $message = null)
    {
        if ($this->event != ProcessLogicInterface::WORKFLOW_EXCEPTION || $event == ProcessLogicInterface::WORKFLOW_EXCEPTION)
            $this->event = $event;

        if ($message)
            $this->error = $message;
    }

    public function getError()
    {
        return $this->error;
    }

    public function getEvent()
    {
        return $this->event;
    }
#endregion

    public function setCaseOption($option, $value, $caseId = null)
    {
        $caseId = $caseId ? : $this->id;
        $found = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_CASE, $caseId);
        $opts = $found->options;

        // if ($option == 'state_vars') {
        //     if (isset($opts['state_vars'])) {
        //         $ids = array_column($opts['state_vars'], 'id');
        //         $found_key = array_search($value['id'], $ids);
        //         if ($found_key !== false) {
        //             $opts['state_vars'][$found_key]['vars'] = array_merge($opts['state_vars'][$found_key]['vars'], $value['vars']);
        //         } else {
        //             $opts['state_vars'][] = $value;
        //         }
        //     } else {
        //         $opts['state_vars'][] = $value;
        //     }
        // }

        if ($option == 'vars') {
            if (isset($opts['vars'])) {
                $opts['vars'] = array_merge($opts['vars'], $value);
            } else {
                $opts['vars'] = $value;
            }
        }

        $data = ['options' => $opts];

        return $this->case = BpmsCase::updateOrCreate(['id' => $caseId], $data);
    }

    private function setSystemCaseOption($option, $value, $caseId = null)
    {
        $caseId = $caseId ? : $this->id;
        $found = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_CASE, $caseId);
        $opts = $found->options;

        if ($option == 'users') {
            $opts['users'] = $value;
        }

        $data = ['options' => $opts];
        return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $caseId], $data);
    }

    public function getCaseOption($option, $filter = null, $caseId = null)
    {
        if ($this->test) {
            return null;
        }
        $caseId = $caseId ? $caseId : $this->id;
        $found = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_CASE, $caseId);
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
        $states = BpmsState::with('workflow')->where($predicate)->get();
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

        $states = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, $predicate);
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
        return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_CASE, ['status' => $status]);
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
        $this->state_first_access = true;

        $s = $this->getCurrentState($state);

        if ($s->isSuccess) {
            $currentState = $s->entity;
        } else {
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'STATE_NOT_EXIST');
            return;
        }

        if (!$this->test) {
            $userId = $this->getNextUserByType($currentState, true);
            $this->next_state = $currentState;

            if (isset($userId)) {
                $predicate = ['element_name' => $state ? $state : $this->state, 'case_id' => $this->id];
                // $m = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);

                $formId = isset($currentState->options['forms'][0]) ? $currentState->options['forms'][0] : null;
                $this->setNextForm($formId);

                $data = ['meta_value' => is_array($userId) ? ProcessLogicInterface::USER_COMMAN : $userId, 'meta_type' => $currentState->meta_type];
                $this->setNextUser($userId);

                return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_META, $predicate, $data, true);
            }
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

        if ($currentState->type == 'bpmn:ScriptTask') {
            if ($currentState->meta_type == ProcessLogicInterface::META_TYPE_SCRIPT_URL) {
                $url = $currentState->options['script'];

                try {
                    $client = new Client();
                    $data = $this->case;
                    //$this->case->form_id = $currentState->options['forms'][0];
                    $data->display_user_name = $this->user_name;
                    $data = json_encode($data);

                    $url = $url . '?data=' . $data;

                    $request = new Request('GET', $url);
                    $response = $client->send($request, ['verify' => false, 'timeout' => 5]);
                    if ($response->getStatusCode() != 200) {
                        $result = $response->getBody();
                        $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'FROM_EKIP_ERROR');
                    }
                } catch (\Exception $e) {
                    $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'CALL_URL_ERROR');
                }
            }
        }
    }

    public function isEligible($metaReq, $typeReq = null, $stateReq = null)
    {
        $lastState = $this->state;

        if ($this->test) {
            return true;
        }

        if ($this->status == 'unassigned') {
            return false;
        }

        if ($this->status == "parted") {
            $foundPart = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_PART, ['state' => $stateReq, 'case_id' => $this->id]);
            if (!$foundPart) {
                return false;
            }

            $lastState = $foundPart->state;
        } elseif ($this->status == 'end') {
            return false;
        }

        $predicate = ['element_name' => $lastState, 'case_id' => $this->id];
        $m = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);

        if ($m == null || !$m->meta_type) { //meta to be set.
            return true;
        }

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

        if ($state->type == 'bpmn:EndEvent')
            return 0;

        try {
            if ($type == ProcessLogicInterface::META_TYPE_USER || $type == ProcessLogicInterface::META_TYPE_SUBPOSITION || $type == ProcessLogicInterface::META_TYPE_SUBUSER) {
                return $state->meta_value;
            }
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
                    $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $state->wid, 'ws_pro_id' => $state->ws_pro_id], ['meta_value' => $state->meta_value]);
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
                $user = $state->options['users'][0];
                $vars = $this->getCaseOption('vars');
                $user = $vars[$user] ? : ProcessLogicInterface::USER_NOT_EXIST;
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
                } else if ($save && !$rival) {
                    $res = $this->getCustomUsers($users);
                    return count($res) > 1 ? $res : $res[0];
                } else {
                    return $this->getCustomUsersText($users);
                }
            }
            if ($type == ProcessLogicInterface::META_TYPE_SCRIPT_URL) {
                $user = $state->options['users'][0];
                $vars = $this->getCaseOption('vars');
                $user = $vars[$user];
                if (!$user)
                    $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'USER_NOT_EXIST');
                return $user;
            }

        } catch (\Exception $e) {
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'USER_EXCEPTION');
            return ProcessLogicInterface::USER_EXCEPTION; //Not expected
        }

        $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'USER_NO_MATCH');
        return ProcessLogicInterface::USER_NO_MATCH;  //No matches were found
    }

    #region Override by user
    public function doSomething($type)
    {
        //To be implemented
    }

    public function getCustomUsers($users_option)
    {
        //return [1,2,3,4,5,6,7,8,9,10];
    }

    public function getCustomUsersText($users_option)
    {
        //return 'USERS_OPTION_TEXT';
    }

    public function getCustomUserDisplayName($user_id)
    {
        return $user_id;
    }
    #endregion

    public function getAvailableTransitions($fromState = null, $columns = null)
    {
        $fromState = $fromState ? $fromState : $this->state;
        if ($this->baseTable == false) {
            $tis = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_META, ['element_type' => 2, 'options->from_state' => $fromState, 'case_id' => $this->getCaseId()], $columns);
            foreach ($tis as $t) {
                $opts = $t->options;
                $t->to_state = $opts['to_state'];
                $t->from_state = $opts['from_state'];
                $t->meta = $t->meta_value;
                $t->gate_wid = $t->element_name;
            }
            return $tis;
        }
        return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_TRANSITION, ['from_state' => $fromState, 'ws_pro_id' => $this->wid], $columns);
    }

    public function getPossibleStates($fromState = null, $vars = null)
    {
        $tis = $this->getAvailableTransitions($fromState);
        foreach ($tis as $t) {
            if ($vars && $tis->count() > 1) {

                //when gate is parallel ???????

                if ($this->checkCondition($t->meta, $vars) === false) {
                    continue;
                }
            }
            $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['ws_pro_id' => $this->wid, 'wid' => $t->to_state]);
            $result[] = ['next_type' => $state->meta_type, 'next_work' => $state->text, 'next_user' => $this->getNextUserByType($state)];
        }
        if (!isset($result))
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'NO_POSSIBLE_STATE');

        return $result;
    }

    public function checkCondition($condition, $vars)
    {
        $language = new ExpressionLanguage();
        if (!$condition)
            return false;
        $vars = $vars ? : array();
        try {
            return $ret = $language->evaluate($condition, $vars);
        } catch (\Exception $e) {
            $ret = false;
        }
    }

    public function createCase($inputArray)
    {
        $workflowId = isset($inputArray['ws_pro_id']) ? $inputArray['ws_pro_id'] : null;

        if (!$workflowId) {
            return false;
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
            if (!BpmsState::where(['wid' => $startState, 'ws_pro_id' => $workflowId])->first())
                return false;
            $data['state'] = $startState;
            $data['status'] = 'created';
        } else if ($userCreator != static::SYSTEM_CASE && !$startState) {
            $predicate = ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflowId];
            //$predicate['ws_pro_id'] = $workflowId;

            $states = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, $predicate)->toArray();

            if (count($states) > 1)
                return false;
            $data['state'] = reset($states)['wid'];
            $data['status'] = 'working';
            $startState = $data['state'];
        }

        if ($userCreator != static::SYSTEM_CASE && static::CONFIG_FILTER_DUPLICATE_CASE) {
            $duplicatePredicate = ['user_creator' => $userCreator, 'status' => 'created', 'ws_pro_id' => $workflowId];
            $founds = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_CASE, $duplicatePredicate);

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

        $newCaseId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_CASE, $data);


        if ($userCreator == static::SYSTEM_CASE) {
            $backup = $this->export($this);
        }

        if ($startState) {
            $this->setCaseById($newCaseId);
            $this->setMetaReq($userCreator);
            $this->checkNext($startState);
            $change = $this->saveChanges(ProcessLogicInterface::WORKFLOW_STARTED);
            if ($change['status'] == 'error') {
                BpmsCase::where('id', $newCaseId)->forceDelete();
                return false;
            }
        }

        if ($userCreator == static::SYSTEM_CASE)
            $this->import($backup);

        $states = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, ['ws_pro_id' => $workflowId]);

        foreach ($states as $state) {
            if ($state->type == 'bpmn:SubProcess') {
                $opts = $state->options;
                $opts['cases'] = [];
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_SUBPROCESS, 'meta_type' => $state->meta_type, 'element_id' => $state->id, 'element_name' => $state->wid, 'case_id' => $newCaseId, 'meta_value' => $state->meta_value, 'options' => $opts];
                $meta = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_META, $data);
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
                $meta = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_META, $data);
            }
        }

        if ($make_copy) {
            $transitions = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_TRANSITION, ['ws_pro_id' => $workflowId]);
            foreach ($transitions as $t) {
                $opts = $t->options;
                $opts['from_state'] = $t->from_state;
                $opts['to_state'] = $t->to_state;
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_TRANSITION, 'meta_type' => 1, 'element_id' => $t->id, 'element_name' => $t->gate_wid, 'case_id' => $newCaseId, 'meta_value' => $t->meta, 'options' => $opts];
                $meta = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_META, $data);
            }
        }
        return $newCaseId;
    }

    public function import(ProcessLogic $object)
    {
        foreach (get_object_vars($object) as $key => $value) {
            $this->$key = $value;
        }
    }

    public function export(ProcessLogic $object)
    {
        return clone $object;
    }

    public function getCases($predicate, $filter = null)
    {
        $field = isset($filter['field']) ? $filter['field'] : 'bpms_cases.created_at';
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
            $typeFound = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_TYPE, ['name' => $newType, 'user_id' => $userId]);
            if ($typeFound) {
                $typeId = $typeFound->id;
            } else {
                $typeId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_TYPE, ['name' => $newType, 'user_id' => $userId]);
            }
        }

        if (static::CONFIG_FILTER_CREATE_UNIQUE_PROCESS) {
            if ($this->dataRepo->findEntity(DataRepositoryInterface::BPMS_WORKFLOW, ['name' => $title]))
                return false;
        }
        return $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_WORKFLOW, [
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
        return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_WORKFLOW, $predicate, $data, $create);
    }

    public function deleteWorkflow($predicate)
    {
        return $this->dataRepo->deleteEntity(DataRepositoryInterface::BPMS_WORKFLOW, $predicate);
    }

    public function getWorkflows($predicate = null, $columns = null)
    {
        return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_WORKFLOW, $predicate, $columns);
    }

    public function getWorkflowTypes($predicate)
    {
        return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_TYPE, $predicate);
    }

    public function getSubprocessMetaWorkflow($workflow, $state)
    {
        if ($this->test)
            $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $state, 'ws_pro_id' => $workflow->id]);
        else
            $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, ['element_type' => 3, 'element_name' => $state, 'case_id' => $case->id]);

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
                $workflow = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_WORKFLOW, $state->meta_value);
                $starts = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflow->id]);
                $startId = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $start, 'ws_pro_id' => $workflow->id])->id;
            }
        }
        return ['case' => $case, 'start' => $startId, 'workflow' => $workflow, 'starts' => $starts];
    }

    // public function getSubprocessMetaCase($case, $state)
    // {
    //     $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, ['element_type' => 3, 'element_name' => $state, 'case_id' => $case->id]);

    //     if ($opts = $state->options) {
    //         if ($hasCases = array_key_exists("cases", $opts)) {
    //             $case = end($opts['cases']);
    //         } else {
    //             $case = null;
    //         }
    //         $start = $opts['start'];
    //         $workflow = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_WORKFLOW, $state->meta_value);
    //         $starts = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflow->id]);
    //         $startId = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $start, 'ws_pro_id' => $workflow->id])->id;
    //         return ['case' => $case, 'start' => $startId, 'workflow' => $workflow, 'starts' => $starts];
    //     }
    // }

    public function getLastPartState()
    {
        if ($this->test == true) {
            $lastPart = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_FAKEPART, ['ws_pro_id' => $this->wid]);
        } else {
            $lastPart = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_PART, ['case_id' => $this->id]);
        }
        $this->deletePart($lastPart->id);
        return $lastPart;
    }

    public function loadSubProcess($caseId)
    {
        $case = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_CASE, $caseId);

        if (!$case) {
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'SUBPROCESS_CASE_NOT_FOUND');
            $this->currentState = null;
            return;
        }
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
            return $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_FAKEPART, $predicate);
        } else {
            $predicate = array_merge($predicate, ['case_id' => $this->id]);
            return $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_PART, $predicate);
        }
    }

    public function deletePart($id)
    {
        if ($this->test == true) {
            return $this->dataRepo->deleteEntity(DataRepositoryInterface::BPMS_FAKEPART, ['id' => $id]);
        } else {
            return $this->dataRepo->deleteEntity(DataRepositoryInterface::BPMS_PART, ['id' => $id]);
        }
    }

    public function countWorkflowPart()
    {
        if ($this->test == true) {
            return $this->dataRepo->countEntity(DataRepositoryInterface::BPMS_FAKEPART, ['ws_pro_id' => $this->id]);
        } else {
            return $this->dataRepo->countEntity(DataRepositoryInterface::BPMS_PART, ['case_id' => $this->id]);
        }
    }

    public function deleteCurrentPart()
    {
        if ($this->part != null) {
            $this->partId = $this->part->id;
            if ($this->test) {
                $this->dataRepo->deleteEntity(DataRepositoryInterface::BPMS_FAKEPART, ['id' => $this->partId]);
            } else {
                $this->dataRepo->deleteEntity(DataRepositoryInterface::BPMS_PART, ['id' => $this->partId]);
            }

            $this->part = null;
        }
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
                $s = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, ['case_id' => $this->getCaseId(), 'element_type' => 1, 'element_name' => $state]);
                $opts = $s->options;
                $s->type = $opts['type'];
                $s->next_wid = $opts['next_wid'];
                $s->next_type = $opts['next_type'];
                $s->text = $opts['text'];
                $this->currentState = $s;
                return new ProcessResponse($s ? true : false, $s, 'OK');
            }

            $s = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $state, 'ws_pro_id' => $this->wid]);
            $this->currentState = $s;
            return new ProcessResponse($s ? true : false, $s, 'OK');
        } catch (\Exception $e) {
            return new ProcessResponse(false, 'Exception occurred', 'OK');
        }
    }

    public function isEndedWorkflow()
    {
        $s = $this->getCurrentState($this->state)->entity;
        $check = ($s->type == 'bpmn:EndEvent');
        $this->next_state = $s;

        if ($check && $this->status == 'parted') {
            $this->deleteCurrentPart();

            $remainingParts = $this->countWorkflowPart();

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

    // public function getTransitionMeta($tid)
    // {
    //     try {
    //         if ($this->test == true || $this->baseTable == true) {
    //             $m = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_TRANSITION, $tid)->meta;
    //             return new ProcessResponse($m ? true : false, $m, 'OK');
    //         } else {
    //             $m = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, ['element_type' => 2, 'element_id' => $tid, 'case_id' => $this->id]);
    //             return new ProcessResponse(true, $m->meta_value, 'OK');
    //         }
    //     } catch (\Exception $e) {
    //         return new ProcessResponse(false, 'No condition is provided', 'OK');
    //     }
    // }

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
                $res = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, ['element_type' => 3, 'element_name' => $stateWID, 'case_id' => $this->getCaseId()]);
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
        $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $stateWID, 'ws_pro_id' => $this->wid]);

        if ($this->test) {
            $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
            $opts = $state->options;
        } else {
            $predicate = ['element_type' => 1, 'element_name' => $stateWID, 'case_id' => $this->id];
            $meta = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);
            $opts = $meta->options;
        }

        if (!isset($opts)) {
            $opts = array();
        }

        if (isset($data['users'])) {
            $opts['users'] = $data['users'];
        }

        if (isset($data['script'])) {
            $opts['script'] = $data['script'];
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

            $this->updateFormOfState(['state_id' => $state->id, 'form_id' => $data['form']], $formData);

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
            return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE, $predicate, $dataTemp, true);
        } else {
            return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_META, $predicate, $dataTemp);
        }
    }

    public function updateFormOfState($predicate, $data)
    {
        $predicate['trigger_id'] = null;

        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE_CONFIG, $predicate, $data, true);
    }

    public function deleteStateMeta($stateWID, $option, $data)
    {
        $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $stateWID, 'ws_pro_id' => $this->wid]);

        if ($this->test) {
            $opts = $state->options;
            $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
        } else {
            $predicate = ['element_type' => 1, 'element_id' => $state->id, 'element_name' => $stateWID, 'case_id' => $this->id];
            $meta = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);
            $opts = $meta->options;
        }

        if ($option == 'forms') {
            $opts['forms'] = array_values(array_diff($opts['forms'], $data));
        } else {
            return;
        }

        $dataTemp['options'] = $opts;

        if ($this->test) {
            return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE, $predicate, $dataTemp);
        } else {
            return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_META, $predicate, $dataTemp);
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
        //$gate = $data['gate'];
        $predicate = ['ws_pro_id' => $this->wid, 'from_state' => $from, 'to_state' => $to];


        if ($this->test) {
            $predicate = ['ws_pro_id' => $this->wid, 'from_state' => $from, 'to_state' => $to];
            $data = ['meta' => $meta_value, 'order_transition' => $order];

            if (isset($opts)) {
                $data['options'] = $opts;
            }

            return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_TRANSITION, $predicate, $data);
        }

        $transition = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_TRANSITION, $predicate);
        $predicate = ['case_id' => $this->id, 'element_type' => 2, 'element_id' => $transition->id];
        $data = ['meta_value' => $meta_value, 'meta_type' => 1];

        if (isset($opts)) {
            $data['options'] = $opts;
        }
        return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_META, $predicate, $data);
    }

    public function getStateMeta($stateWID, $predicate = null, $columns = null)
    {
        if ($this->test) {
           //$predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
            $meta = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $stateWID, 'ws_pro_id' => $this->wid]);
        } else {
            $predicate = ['element_name' => $stateWID, 'case_id' => $this->id];
            $meta = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);
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
        if (isset($opts['users_meta'])) {
            $res['users_meta'] = $opts['users_meta'];
        }


        if (isset($opts['forms'])) {
            // if (isset($predicate['form_id']))
            //     $res['form'] = $this->getFormOfState($predicate, $columns);


            $res['forms'] = $opts['forms'];
        }

        if (isset($opts['script'])) {
            // if (isset($predicate['form_id']))
            //     $res['form'] = $this->getFormOfState($predicate, $columns);


            $res['script'] = $opts['script'];
        }

        if (isset($meta->meta_type)) {
            $res['type'] = $meta->meta_type;
        }

        if (isset($opts['users'])) {
            if ($meta->meta_type == 7 || $meta->meta_type == 9) {

            }
            $res['users'] = $opts['users'];

        }

        if (isset($meta->meta_value)) {
            $res['value'] = $meta->meta_value;
        }

        return $res;
    }

    public function updateStateOptions($stateWID, $caseId)
    {
        $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
        $s = $this->getCurrentState($stateWID);
        $opts = $s->entity->options;
        $opts['cases'][] = $caseId;

        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE, $predicate, ['options' => $opts]);
    }

    public function setMetaOptions($stateWID, $option, $data, $type = 1)
    {
        $predicate = ['element_type' => $type, 'element_name' => $stateWID, 'case_id' => $this->getCaseId()];
        $m = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);
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

        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_META, $predicate, $data, true);
    }

    public function getMetaOptions($stateWID, $option, $type = 1)
    {
        $predicate = ['element_type' => $type, 'element_name' => $stateWID, 'case_id' => $this->getCaseId()];
        $m = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);
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
                $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_FAKEPART, $data);
            } else {
                $data = ['from' => $from, 'state' => $from, 'to' => 'NaN', 'transition_id' => $tid, 'gate_id' => $gid, 'case_id' => $this->id];

                $data['user_from'] = $this->getMetaReq();
                $userId = $this->getNextUser();
                if (is_array($userId)) {
                    $data['user_current'] = ProcessLogicInterface::USER_COMMAN;
                    $opts['users'] = $userId;
                    $data['status'] = 'unassigned';
                } else {
                    $data['user_current'] = $userId;
                }

                if (isset($opts)) {
                    $data['system_options'] = $opts;
                }

                $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_PART, $data);
            }
        }
    }



    public function addActivityLog($data = null)
    {
        if ($data) {
            $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_ACTIVITY, $data);
            return $activityId;
        }

        if ($this->test)
            return;

        $type = $this->getEvent();
        $case = $this->getCase();
        $last = $case->activity_id;
        $user_from = $this->getMetaReq();
        $data = ['case_id' => $this->id, 'type' => $type, 'transition_id' => $this->transitionFired, 'comment' => $this->comment, 'pre' => $last, 'part_id' => $this->partId ? : 0, 'user_id' => $user_from];
        $user_current = $this->getNextUser();

        if (is_array($user_current)) {
            $data['user_id'] = ProcessLogicInterface::USER_COMMAN;
            $opts['users'] = $user_current;
        } else {
            $data['user_id'] = $user_current;
        }

        if ($this->next_state) {
            $opts['text'] = $this->next_state->text;
            $opts['element_name'] = $this->next_state->wid;
            $data['options'] = $opts;
        }

        if ($type == ProcessLogicInterface::WORKFLOW_ENDED) {
            $data['finished_at'] = date("Y-m-d H:i:s");
            $data['user_id'] = $user_from;
            $data['options->user_name'] = $this->user_name;
        }

        if ($last) {
            $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_ACTIVITY, ['id' => $last], ['finished_at' => date("Y-m-d H:i:s"), 'user_id' => $user_from, 'options->user_name' => $this->user_name], true);
        }

        if ($type == ProcessLogicInterface::WORKFLOW_BACKED) {
            $lastActivity = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_ACTIVITY, $last);
            $data = ['case_id' => $this->id, 'type' => $type, 'transition_id' => $lastActivity->transition_id, 'comment' => $this->comment ? : 'WORKFLOW_BACKED', 'pre' => $last, 'part_id' => $this->partId ? : 0, 'user_id' => $user_from];

            $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_ACTIVITY, $data);

            $t = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_TRANSITION, $lastActivity->transition_id);
            $preActivity = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_ACTIVITY, $lastActivity->pre);
            $data = ['activity_id' => $lastActivity->pre, 'state' => $t->from_state, 'user_current' => $preActivity->user_id, 'user_from' => $user_from];
            $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], $data);
            return;
        }

        $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_ACTIVITY, $data);
        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], ['activity_id' => $activityId]);

        // if ($type != ProcessLogicInterface::WORKFLOW_BACKED) {
        //     $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], ['activity_id' => $activityId]);
        // } else {
        //     $t = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_TRANSITION, $lastActivity->transition_id);
        //     $preActivity = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_ACTIVITY, $lastActivity->pre);
        //     $data = ['activity_id' => $lastActivity->pre, 'state' => $t->from_state, 'user_current' => $preActivity->user_id, 'user_from' => $user_from];
        //     $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], $data);
        // }
    }

    public function getActivityLog($caseId = null, $fromDate = null, $toDate = null)
    {
        if ($caseId) {
            $case = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_CASE, $caseId);
            $wf = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_WORKFLOW, $case->ws_pro_id);
        } else {
            $caseId = $this->getCaseId();
            $wf = $this->getWorkflow();
        }

        $predicate = ['case_id' => $caseId];
        $title = $wf->name;
        $acts = BpmsActivity::where($predicate)->where('type', '<>', ProcessLogicInterface::WORKFLOW_ENDED)->orderBy('id', 'asc')->get();
        $res = [];
        $index = 1;
        foreach ($acts as $activity) {
            $temp =
                [
                'index' => $index,
                'case_id' => $caseId,
                'title' => $title,
                'task' => $activity->options['text'],
                'element_name' => $activity->options['element_name'],
                'user_name' => $activity->user_id == ProcessLogicInterface::USER_COMMAN ? $this->getCustomUserDisplayName($activity->options['users']) : (isset($activity->options['user_name']) ? $activity->options['user_name'] : $this->getCustomUserDisplayName($activity->user_id)),
                'user_id' => $activity->user_id == ProcessLogicInterface::USER_COMMAN ? $activity->options['users'] : $activity->user_id,
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

    public function getStatus($inputArray = null)
    {
        if ($inputArray)
            $this->checkRival($inputArray);

        if ($this->subProcess) {
            return $this->backupStatus;
        }

        if ($this->status == 'parted') {
            $data = ['status' => $this->status, 'state' => $this->getPartsStateWID(), 'base' => $this->getBaseState()];
        } else {
            $data = ['status' => $this->status, 'state' => $this->state];
        }

        if ($data['status'] == "end")
            return $data;


        if (static::CONFIG_WORKFLOW_USE_FORM) {
            $formData = $this->getFirstFormOfState();
            if (!$formData) {
                $data['type'] = ProcessLogicInterface::NEXT_ERROR;
                $data['status'] = "error";
                $data['message'] = $this->getEvent() == ProcessLogicInterface::WORKFLOW_NO_FORM ? "WORKFLOW_NO_FORM" : "WORKFLOW_NO_MATCH_FORM";
                return $data;
            }

            if ($formData === true) {
                $data['type'] = ProcessLogicInterface::NEXT_NEXT;
                $data['status'] = "working";
                return $data;
            }

            $data['form'] = $formData;

            // if ($state_vars = $this->getCaseOption('state_vars', ['id' => $formData->id])) {
            //     $data['state_vars'] = $state_vars;
            // }
        }

        if ($vars = $this->getCaseOption('vars')) {
            $data['vars'] = $vars;
        }
        return $data;
    }

    public function checkRival($inputArray)
    {
        $user = isset($inputArray['user']) ? $inputArray['user'] : null;
        $state = isset($inputArray['state']) ? $inputArray['state'] : null;

        if (!$user || !$state)
            return;

        $s = $this->getCurrentState($state);

        $userId = $this->getNextUserByType($s->entity, $save = true, $rival = $user);
        if (isset($userId)) {
            $predicate = ['element_name' => $state, 'case_id' => $this->id];
            $data = ['meta_value' => $userId];
            $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_META, $predicate, $data);
            if ($this->status == 'parted') {
                return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_PART, ['state' => $state], ['status' => 'working', 'user_current' => $userId]);
            } else {
                $activity = BpmsActivity::where('case_id', $this->id)->whereNull('finished_at')->first();
                $activity->user_id = $userId;
                $activity->save();
                return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], ['status' => 'working', 'user_current' => $userId, 'seen' => true]);
            }
        }
    }

    public function getFirstFormOfState($stateWID = null, $state = null)
    {
        $s = $this->getCurrentState($stateWID);
        $state = $s->isSuccess ? $s->entity : null;

        if (!$state)
            return false;

        if ($state->type == "bpmn:ScriptTask") {
            return true;
        }

        $forms = $this->getStateForms(null, true, null, $state, 'stateConfigs')['forms'];

        if (!$forms) {
            $this->setEvent(ProcessLogicInterface::WORKFLOW_NO_FORM);
            return null;
        }

        $vars = $this->getCaseOption('vars');

        foreach ($forms as $form) {
            $config = $form->stateConfigs->where('state_id', $state->id)->first();
            if ($this->checkCondition($config ? $config->condition : null, $vars)) {
                $candidateForm = $form;
                break;
            }
        }

        if (!isset($candidateForm)) {
            $this->setEvent(ProcessLogicInterface::WORKFLOW_NO_MATCH_FORM);
            return null;
        }

        $meta = BpmsMeta::where(['element_name' => $this->state, 'case_id' => $this->id])->first();
        if (!$meta || $this->state_first_access || !isset($meta->options['forms']))
            return $candidateForm;
        else {
            $formMeta = collect($meta->options['forms'])->where('id', $candidateForm->id)->first();
            $candidateForm->formMeta = $formMeta;
            return $candidateForm;
        }
    }

    public function getPartsStateWID()
    {
        if ($this->test == true) {
            $parts = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_FAKEPART, ['ws_pro_id' => $this->wid]);
        } else {
            $parts = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_PART, ['case_id' => $this->id]);
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

    public function setPart($state = null)
    {
        if ($this->test == true) {
            $this->part = $this->dataRepo->findEntityByRandom(DataRepositoryInterface::BPMS_FAKEPART, ['ws_pro_id' => $this->wid]);
        } else {
            //$this -> part = $this ->case -> parts() ->inRandomOrder()->first();
            $this->part = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_PART, ['case_id' => $this->id, 'state' => $state ? $state : $this->getStateReq()]);
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
            $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_WORKFLOW, ['id' => $process], ['wxml' => $wcontent['wxml'], 'wsvg' => $wcontent['wsvg']]);
        }

        $to_keep = [];

        foreach ($transitions as $transition) {
            $predicate = ['from_state' => $transition['from'], 'to_state' => $transition['to'], 'ws_pro_id' => $process];
            $data = ['gate_wid' => null, 'from_state' => $transition['from'], 'to_state' => $transition['to'], 'ws_pro_id' => $process];

            $tid = $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_TRANSITION, $predicate, $data, true);
            $to_keep[] = $tid;
        }

        if ($to_keep) {
            $this->dataRepo->deleteNotIn(DataRepositoryInterface::BPMS_TRANSITION, ['ws_pro_id' => $process], $to_keep);
            $to_keep = [];
        }

        foreach ($states as $state) {
            $data = ['wid' => $state['id'], 'type' => $state['type'], 'position_state' => $state['position'], 'text' => $state['name'], 'next_wid' => $state['next']['id'], 'next_type' => $state['next']['type'], 'ws_pro_id' => $process];
            $sid = $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $state['id']], $data, true);
            $to_keep[] = $sid;
        }

        if ($to_keep) {
            $this->dataRepo->deleteNotIn(DataRepositoryInterface::BPMS_STATE, ['ws_pro_id' => $process], $to_keep);
            $to_keep = [];
        }

        foreach ($gateways as $gate) {
            foreach ($gate['inState'] as $in) {
                foreach ($gate['outState'] as $out) {
                    $foundTransition = $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_TRANSITION, ['from_state' => $in, 'to_state' => $out], ['gate_wid' => $gate['id']]);
                }
            }

            $data = ['type' => $gate['type'], 'wid' => $gate['id'], 'is_end' => $gate['isEnd'], 'ws_pro_id' => $process];
            $gid = $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_GATE, ['wid' => $gate['id']], $data, true);
            $to_keep[] = $gid;
        }

        if ($to_keep) {
            $this->dataRepo->deleteNotIn(DataRepositoryInterface::BPMS_GATE, ['ws_pro_id' => $process], $to_keep);
        }

        return true;
    }

    public function saveChanges($type, $changed = true)
    {
        $this->setEvent($type);

        if ($changed && $this->getEvent() != ProcessLogicInterface::WORKFLOW_EXCEPTION) {
            if ($this->test == true) {
                $this->saveWorkflow();
            } else {
                $this->saveCase();
            }
        }

        switch ($this->getEvent()) {

            case ProcessLogicInterface::WORKFLOW_PREVIEW:
                return ['status' => 'preview', 'type' => static::NEXT_PREVIEW, 'next' => $this->preview_next];

            case ProcessLogicInterface::WORKFLOW_NO_PATH:
                return ['status' => 'error', 'state' => $this->backupState, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_NO_PATH'];

            case ProcessLogicInterface::WORKFLOW_EXCEPTION:
                return ['status' => 'error', 'state' => $this->backupState, 'type' => static::NEXT_ERROR, 'message' => $this->error];

            case ProcessLogicInterface::WORKFLOW_ENDED:
                $this->addActivityLog();
                return ['status' => 'end', 'state' => $this->state, 'type' => static::NEXT_NEXT];

            case ProcessLogicInterface::WORKFLOW_NO_META:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_NO_META'];

            case ProcessLogicInterface::WORKFLOW_PART_ISNULL:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_PART_ISNULL'];

            case ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => $this->error];

            case ProcessLogicInterface::WORKFLOW_LONELY_PART:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_LONELY_PART'];

            case ProcessLogicInterface::WORKFLOW_STATE_NOTFOUND:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_STATE_NOTFOUND'];

            default:
        }

        $this->addActivityLog();
        $this->doSomething($type);
        return $this->getStatus();
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

        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_WORKFLOW, $predicate, $data);
    }

    public function saveCase()
    {
        $data['status'] = $this->status;
        $data['user_from'] = $this->getMetaReq();
        $data['form_id'] = $this->next_form;
        $userId = $this->getNextUser();

        if (is_array($userId)) {
            $data['user_current'] = ProcessLogicInterface::USER_COMMAN;
            $data['status'] = 'unassigned';
            $data['system_options']['users'] = $userId;
        } else {
            $data['user_current'] = $userId;
        }

        if ($this->status == "parted") {
            $this->savePart($data);
            $data['status'] = "parted";
        } else {
            $data['state'] = $this->state;
        }

        $data['seen'] = false;
        $predicate = ['id' => $this->id];
        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, $predicate, $data);
    }

    public function savePart($data = null)
    {
        if ($this->part != null) {
            $data['state'] = $this->state;
            if ($this->test) {
                $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_FAKEPART, ['ws_pro_id' => $this->wid, 'id' => $this->part->id], $data);
            } else {
                $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_PART, ['case_id' => $this->id, 'id' => $this->part->id], $data);
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
            $this->checkNext($this->state);
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_RESTARTED);
        }

        if ($this->status == 'end') {//case to restart
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_ENDED_BEFORE, false);
        }

        if ($this->status == "created" && !$this->state) {//case to start

            $this->state = $this->getFirstStateWID();
            $this->status = 'working';

            $this->checkNext($this->state);
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_STARTED);
        }

        if ($this->status == 'created') {
            $this->status = 'working';
        }

        $this->checkSubprocess();

        if (!$this->currentState)
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_STATE_NOTFOUND, false);


        switch ($next_type = $this->currentState->next_type) {
            case "bpmn:ParallelGateway":
                $isPartedBefore = $this->status == 'parted';
                $this->status = 'parted';
                break;

            case "bpmn:InclusiveGateway":
                $isPartedBefore = $this->status == 'parted';
                $this->status = 'parted';
                $isParellel = true;
                break;
            default:
        }

        try {
            $available = [];
            $tis = $this->getAvailableTransitions();
            foreach ($tis as $t) {
                $available[$t->id] = $t;
            }

            if (count($available) > 1) {
                foreach ($available as $toGoId => $transition) {
                    $toGoTransition = $transition;

                    if (!$toGoTransition) {
                        continue;
                    }

                    if ($next_type == "bpmn:ExclusiveGateway" || $next_type == "bpmn:InclusiveGateway") {

                        $meta = $transition->meta;
                        if (!$meta) {
                            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_NO_META, false);
                        }

                        try {
                            $language = new ExpressionLanguage();

                            if (!$vars = $this->getVars()) {
                                $vars = isset($this->getCase()->options['vars']) ? $this->getCase()->options['vars'] : null;
                            }
                            $ret = $language->evaluate($meta, $vars);
                        } catch (\Exception $e) {
                            $this->setEvent(ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR, $e->getMessage());
                            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR, false);
                        }

                        if ($ret == true && $next_type == "bpmn:ExclusiveGateway") {
                            $nextTransition = $toGoTransition->id;
                            break;
                        } elseif ($ret == true && $next_type == "bpmn:InclusiveGateway") {
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

                $toGoTransition = reset($available);
                if (!$toGoTransition) {
                    return $this->saveChanges(ProcessLogicInterface::WORKFLOW_NO_PATH, false);
                }

                if ($next_type == "bpmn:ParallelGateway" || $next_type == "bpmn:InclusiveGateway") {
                    $foundPart = $this->findWorkflowPart(['state' => $toGoTransition->from_state]);

                    if (!$foundPart) {
                        return $this->saveChanges(ProcessLogicInterface::WORKFLOW_LONELY_PART, false);
                    }

                    $trans = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_TRANSITION, ['gate_wid' => $toGoTransition->gate_wid]);

                    $done = true;

                    foreach ($trans as $t) {
                        $friendPart = $this->findWorkflowPart(['state' => $t->from_state]);
                        if ($friendPart == null && ($this->state != $t->from_state)) {
                            $done = false;
                        }
                    }

                    if ($done == false) {//WORKFLOW_WAIT_FOR_ANOTHER
                        return $this->saveChanges(ProcessLogicInterface::WORKFLOW_WAIT_FOR_ANOTHER, false);
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
                    }
                } else {
                    $nextTransition = $toGoId;
                }
            }

            if (isset($isParellel)) {
                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_PART_CREATED);
            }

            if (isset($nextTransition)) {
                $this->setTransitionFired($nextTransition, $available);
            } else {
                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_NO_PATH, false);
            }

        } catch (\Exception $e) {
            echo $e->getMessage(), "\n";
        }
        $endResult = $this->isEndedWorkflow();

        if ($endResult != ProcessLogicInterface::WORKFLOW_ENDED && $endResult != ProcessLogicInterface::WORKFLOW_PART_ENDED)
            $this->checkNext($this->state);

        return $this->saveChanges($endResult);
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

        return $this->saveChanges(ProcessLogicInterface::WORKFLOW_BACKED, false);

        // $lastActivity = BpmsActivity::find($case->activity_id);

        // if ($lastActivity->type == ProcessLogicInterface::WORKFLOW_STARTED)
        //     return ['error' => 'Back is not enabled at start'];

        // $t = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_TRANSITION, $lastActivity->transition_id);
        // $currentState = $t->to_state;
        // $backState = $t->from_state;

        // $meta = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, ['case_id' => $case->id, 'element_name' => $currentState]);

        // if (!$meta) { //foreach user different rules apply!
        //     return ['error' => 'Back is not enabled. meta not found'];
        // }

        // $this->state = $backState;
        // $this->status = "working";
        // $this->setNextUser($case->user_from);

        // return $this->saveChanges(ProcessLogicInterface::WORKFLOW_BACKED);
    }

    public function getFirstStateWID()
    {
        $firstState = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $this->wid]);
        $firstStateWID = $firstState->wid;
        return $firstStateWID;
    }

    public function goNext($inputArray)
    {
        $user = isset($inputArray['user']) ? $inputArray['user'] : null;
        if ($user) {
            $metaReq = $user->id;
            $this->user_name = $user->name;
        } else {
            $metaReq = isset($inputArray['metaReq']) ? $inputArray['metaReq'] : null;
            $this->user_name = $metaReq ? $this->getCustomUserDisplayName($metaReq) : 'NoName';
        }
        $vars = isset($inputArray['vars']) ? $inputArray['vars'] : null;
        $stateReq = isset($inputArray['stateReq']) ? $inputArray['stateReq'] : null;
        $typeReq = isset($inputArray['typeReq']) ? $inputArray['typeReq'] : null;
        $commentText = isset($inputArray['commentText']) ? $inputArray['commentText'] : null;
        $form = isset($inputArray['form']) ? $inputArray['form'] : null;
        $user_manual = isset($inputArray['user_manual']) ? $inputArray['user_manual'] : null;
        $preview = isset($inputArray['preview']) ? $inputArray['preview'] : static::CONFIG_NEXT_PREVIEW;

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
            }

            if ($this->isStateDone($form)) {
                if ($preview && $form) {
                    $this->preview_next = $this->getPossibleStates($this->state, $vars);
                    return $this->saveChanges(ProcessLogicInterface::WORKFLOW_PREVIEW, false);

                } else {
                    $res = $this->nextLogic();
                    $res['type'] = isset($res['type']) ? $res['type'] : ProcessLogicInterface::NEXT_NEXT;
                    return $res;// get first form content
                }
            } else {
                return ['status' => 'form', 'type' => ProcessLogicInterface::NEXT_FORM, 'next_form' => $this->next_form];//get next_form content
            }
        } else {
            return ['type' => ProcessLogicInterface::NEXT_BADACCESS];
        }
    }

    public function goBack($inputArray)
    {
        $metaReq = isset($inputArray['meta']) ? $inputArray['meta'] : null;
        if ($metaReq) {
            $this->setMetaReq($metaReq, false);
        }

        return $this->backLogic();
    }

    public function isStateDone($form)
    {
        if (!static::CONFIG_WORKFLOW_USE_FORM)
            return true;

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
            if ($key === false) {
                $this->setNextForm($forms[0]);
                return false;
            }
            if (array_key_exists($key + 1, $forms)) {
                $this->setNextForm($forms[$key + 1]);
                return false;
            }
        }
        return $res;
    }

    public function getForms($predicate = null, $columns = null)
    {
        if (!$predicate)
            return BpmsForm::where('ws_pro_id', $this->wid)->get($columns);

        if ($this->wid && !isset($predicate['ws_pro_id'])) {
            $predicate['ws_pro_id'] = $this->wid;
        }
        return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_FORM, $predicate, $columns);
    }

    // public function addForm($data)
    // {
    //     $data['ws_pro_id'] = $data['ws_pro_id'] ? : $this->wid;
    //     return BpmsForm::create($data)->id;
    // }

    // public function updateForm($predicate, $data)
    // {
    //     $predicate['ws_pro_id'] = $predicate['ws_pro_id'] ? : $this->wid;

    //     return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_FORM, $predicate, $data);
    // }

    // public function deleteForm($predicate)
    // {
    //     $predicate['ws_pro_id'] = $predicate['ws_pro_id'] ? : $this->wid;

    //     return $this->dataRepo->deleteEntity(DataRepositoryInterface::BPMS_FORM, $predicate);
    // }

    public function getStateForms($stateWID = null, $assigned = true, $columns = null, $state = null, $with = null)
    {
        if (!$state)
            $state = $this->getCurrentState($stateWID)->entity;
        $forms = isset($state->options['forms']) ? $state->options['forms'] : null;

        if ($assigned && $forms) {
            if ($with)
                $bpmsForms = BpmsForm::with($with)->whereIn('id', $forms)->get($columns);
            else
                $bpmsForms = BpmsForm::whereIn('id', $forms)->get($columns);
            foreach ($forms as $form) {
                $foundForm = $bpmsForms->where('id', $form)->first();
                if ($foundForm)
                    $res[] = $foundForm;
            }

        } else if (!$assigned && $forms)
            $res = BpmsForm::where('ws_pro_id', $this->wid)->whereNotIn('id', $forms)->get($columns);
        else if ($assigned && !$forms) {
            $res = null;

        } else if (!$assigned && !$forms)
            $res = BpmsForm::where('ws_pro_id', $this->wid)->get($columns);

        return ['forms' => $res, 'state_id' => $state->id];
    }

    public function getStateFormCondition($inputArray)
    {
        $state_id = $inputArray['state_id'];
        $form_id = $inputArray['form_id'];
        $columns = $inputArray['columns'];
        $id = isset($inputArray['id']) ? $inputArray['id'] : null;

        if ($id)
            BpmsStateConfig::firstOrFail($id);

        return BpmsStateConfig::where(['state_id' => $state_id, 'form_id' => $form_id, 'trigger_id' => null])->get($columns)->first();
    }

    public function deleteStateForm($inputArray)
    {
        $state_id = $inputArray['state_id'];
        $form_id = $inputArray['form_id'];

        BpmsStateConfig::where(['state_id' => $state_id, 'form_id' => $form_id])->delete();
        $state = BpmsState::findOrFail($state_id);
        $opts = $state->options;
        $forms = $opts['forms'];

        $new_forms = [];
        foreach ($forms as $form) {
            if ($form != $form_id)
                $new_forms[] = $form;
        }

        $opts['forms'] = $new_forms;
        $state->options = $opts;
        $state->save();
    }

    public function getVariables($predicate = null, $columns = null)
    {
        if (!$predicate)
            return BpmsVariable::where('ws_pro_id', $this->wid)->get($columns);
        $predicate['ws_pro_id'] = $this->wid;
        return BpmsVariable::where($predicate)->get($columns);
    }

    public function getVariablesWithValue($predicate = null)
    {
        if ($predicate)
            $vars = BpmsVariable::where($predicate)->get();
        else
            $vars = BpmsVariable::where(['ws_pro_id' => $this->wid])->get();

        foreach ($vars as $var) {
            $res[$var->name] = isset($var->options['default_value']) ? $var->options['default_value'] : 0;
        }
        return isset($res) ? $res : null;
    }


    public function deleteWorkflowEntity($entity, $predicate, $check = true)
    {
        if ($result = $this->dataRepo->deleteEntity($entity, $predicate)) {
            return new ProcessResponse(true, null, 'OK');
        }
    }

    public function getWorkflowEntities($entity, $predicate, $columns = null, $with = null)
    {
        return $this->dataRepo->findEntities($entity, $predicate, $columns, $with);
    }

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

    public function getFormElements($predicate, $columns = null)
    {
        $form = BpmsForm::where($predicate)->with('variables')->first();

        $elements = $form ? $form->variables : null;
        // if ($elements->isEmpty())
        //     return null;


        foreach ($elements as $element) {
            // if ($element->is_global)
            $vars[$element->name] = 0;
            $data['element_name'] = $element->pivot->element_name;
            $data['element_type'] = $element->pivot->element_type;
            $data['element_value'] = $element->fetch()->exists() ? $this->executeSelectQuery($element->fetch->query) : null;
            $data['default_value'] = isset($element->options['default_value']) ? $element->options['default_value'] : null;
            $res[] = $data;
        }

        return ['elements' => isset($res) ? $res : null, 'form' => $form, 'vars' => isset($vars) ? $vars : null];
    }

    public function executeSelectQuery($query)
    {
        try {
            return DB::select($query);
        } catch (\Exception $e) {
            return null;
        }
    }
}

