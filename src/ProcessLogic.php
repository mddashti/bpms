<?php

namespace Niyam\Bpms;

use Niyam\Bpms\ProcessResponse;
use Niyam\Bpms\Data\DataRepositoryInterface;
use Niyam\Bpms\Model\BpmsState;
use Niyam\Bpms\Model\BpmsMeta;
use Niyam\Bpms\Model\BpmsActivity;
use Niyam\Bpms\Model\BpmsCase;
use Niyam\Bpms\Model\BpmsTimer;
use Niyam\Bpms\Service\FormService;
use Niyam\Bpms\Service\CaseService;
use Niyam\Bpms\Service\GateService;
use Symfony\Component\DomCrawler\Crawler;
use Carbon\Carbon;
use Niyam\Bpms\Data\BaseService;

class ProcessLogic extends BaseService implements ProcessLogicInterface
{
    #region CONST
    const
        CONFIG_FILTER_DUPLICATE_CASE = true,
        CONFIG_FILTER_CREATE_UNIQUE_PROCESS = true,
        CONFIG_NEXT_PREVIEW = false,
        CONFIG_WORKFLOW_USE_FORM = false,
        CONFIG_BOOT_ELOQUENT = false,
        CONFIG_CHECK_USER = false,
        CONFIG_BOOT_DATABASE = 'bpms',
        CONFIG_BOOT_USERNAME = 'root',
        CONFIG_BOOT_PASSWORD = '';
    #endregion

    #region Members
    private $id = 0;

    protected $wid = 0;

    protected $workflow;

    protected $case;

    protected $test = false;

    private $status;

    private $state;

    private $currentState;

    // private $subProcess = false;

    // private $backupStatus;

    private $backupState;

    private $event = ProcessLogicInterface::WORKFLOW_NO_EVENT;

    private $transitionFired = 0;

    private $comment;

    private $metaReq = null;

    private $user_name = 'NotSet';

    private $user_position = 0;

    private $next_user = null;

    private $next_state = null;

    private $next_form = 0;

    private $next_position = 0;

    private $vars = null;

    private $stateReq = null;

    private $partId;

    protected $dataRepo;

    private $baseTable = false;

    private $user_manual = null;

    private $error = null;

    private $preview_next = null;

    protected $formService;

    protected $gateService;

    protected $caseService;

    protected $baseCase = 0;

    protected $stateHasAttachment = false;

    protected $attachment_id = 0;

    protected $lastMeta = null;


    #endregion

    #region SETTER & GETTER
    public function __construct(DataRepositoryInterface $dataRepo, FormService $formService, CaseService $caseService, GateService $gateService)
    {
        $this->dataRepo = $dataRepo;
        $this->formService = $formService;
        $this->caseService = $caseService;
        $this->gateService = $gateService;

        if (static::CONFIG_BOOT_ELOQUENT)
            CustomBoot::enable();
    }

    public function setCase($case, $baseTable = false)
    {
        $wf = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_WORKFLOW, $case->ws_pro_id);
        $this->test = false;
        $this->baseTable = $baseTable ?: !isset($case->system_options['copy']);
        $this->case = $case;
        $this->workflow = $wf;
        $this->id = $case->id;
        $this->wid = $wf->id;
        $this->status = $case->status;
        $this->state = $case->state;
        $getState = $this->getCurrentState($case->state);
        $this->currentState = $getState->isSuccess ? $getState->entity : null;
        $this->backupState = $this->state;
        $this->caseService->setCase($case);
        $this->formService->setCase($case);
        $this->baseCase = $case->cid; //base case of case!
    }

    public function setCaseById($caseId, $baseTable = false)
    {
        if (!$caseId) {
            return;
        }
        $case = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_CASE, $caseId);

        if (empty($case))
            return;

        $this->setCase($case);
    }

    public function getCaseId()
    {
        return $this->id;
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

    public function getLastMeta()
    {
        return $this->lastMeta;
    }

    public function hasParentCase()
    {
        return $this->case->parent_case;
    }

    private function getParentCase()
    {
        if ($this->test)
            return $this->id;
        if ($this->hasParentCase())
            return $this->case->system_options['parent_case'];
        return $this->case->id;
    }

    private function getBaseCase()
    {
        if ($this->test)
            return $this->id;
        if ($this->hasParentCase())
            return $this->case->system_options['base_case'];
        return $this->case->id;
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
        $getState = $this->getCurrentState();
        $this->currentState = $getState->isSuccess ? $getState->entity : null;
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

    // public function getCommnet($comment)
    // {
    //     return $this->comment;
    // }

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
        return $this->next_user ?: 0;
    }

    public function getMetaReq()
    {
        return $this->metaReq ?: 0;
    }

    public function setVars($vars)
    {
        $this->vars = $vars;
    }

    public function getVars()
    {
        if (!$this->vars) {
            return isset($this->getCase()->options['vars']) ? $this->getCase()->options['vars'] : null;
        }
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
        if (($this->event != ProcessLogicInterface::WORKFLOW_SUBPROCESS && $this->event != ProcessLogicInterface::WORKFLOW_EXCEPTION) || $event == ProcessLogicInterface::WORKFLOW_EXCEPTION)
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

    public function getUserStarts($userId, $ws_pro_id = null)
    {
        $predicate = ['position_state' => ProcessLogicInterface::POSITION_START];
        if ($ws_pro_id) {
            $predicate['ws_pro_id'] = $ws_pro_id;
        }
        $states = BpmsState::with('workflow')->where($predicate)->get();
        $res = [];
        foreach ($states as $state) {
            if ($this->isUserinState($state, $userId))
                $res[] = $state;
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
            if ($this->isUserinState($state, $userId))
                return true;
        }
        return false;
    }

    public function isUserinState($state, $userId)
    {
        $users = isset($state->options['users']) ? $state->options['users'] : null;
        if ($this->isPositionBased($state))
            $userInfo = $this->givePositionsOfUser($userId);
        else
            $userInfo = [$userId];
        return array_intersect($userInfo, $users) ? true : false;
    }

    public function checkNext($state = null, $user = null)
    {
        $s = $this->getCurrentState($state);

        if ($s->isSuccess) {
            $currentState = $s->entity;
        } else {
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'STATE_NOT_EXIST');
            return;
        }

        if ($this->stateHasAttachment) {
            BpmsTimer::where(['state_id' => $this->attachment_id, 'case_id' => $this->getCaseId()])->delete();
        }

        if ($currentState->type == 'bpmn:ScriptTask') {
            if ($currentState->meta_type == ProcessLogicInterface::META_TYPE_SCRIPT_URL) {
                $url = $currentState->options['script'];

                $data = $this->case;
                $data->display_user_name = $this->user_name;

                $response = $this->sendApi($url, $data);

                if ($response == -1) { // -1 for exception
                    $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'CALL_URL_ERROR');
                }

                if ($response != 200 && $response != -1) {
                    $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'FROM_CLIENT_ERROR');
                }
            }
        } //end of bpmn:ScriptTask check

        else if ($currentState->type == "bpmn:MessageEventDefinition") //Message event
        {
            $state = $this->currentState;
            $messageOption = $state->options['message'] ?? null;
            if ($state->meta_type == ProcessLogicInterface::META_TYPE_MESSAGE_VARIABLE) {
                $vars = $this->caseService->getCaseOption('vars');
                $user = $messageOption['users'][0]; //user is variable like users=>["A"]
                $users = $vars[$user] ?: ProcessLogicInterface::USER_NOT_EXIST;
                $message = $vars[$messageOption['message']] ?? NULL;
                $subject =  $vars[$messageOption['subject']] ?? NULL;
            } else {
                $users = $messageOption['users'];
                $message = $messageOption['message'];
                $subject =  $messageOption['subject'] ?? NULL;
            }
            $center =  $messageOption['center'] ?? NULL;
            $sender =  $messageOption['sender'] ?? NULL;
            $messageType = $messageOption['type'] ?? NULL;
            $this->sendMessage($messageType, $center, $sender, $subject,  $message, $users);
            return;
        } else if ($currentState->type == "bpmn:TimerEventDefinition" || $currentState->has_attachment) //Timer event
        {
            if ($currentState->has_attachment) {
                $attachmentWID = $currentState->attacher_wid;
                $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $attachmentWID, 'ws_pro_id' => $this->getWID()]);
            } else
                $state = $this->currentState;
            $timerOption = $state->options['timer'];
            if ($state->meta_type == ProcessLogicInterface::META_TYPE_TIMER_VARIABLE) {
                $till = $state->options['var'];
                $vars = $this->caseService->getCaseOption('vars');
                $suspend_till = $vars[$till] ?: null;
            } else {
                $type = $timerOption['type'];
                if ($type == 1) //Waitfor
                {
                    $day = $timerOption['type'];
                    $hour = $timerOption['hour'];
                    $month = $timerOption['month'];
                    $year = $timerOption['month'];
                    $suspend_till = Carbon::now()->addDays($day)->addHours($hour)->addMonths($month)->addYears($year);
                    BpmsTimer::create(['case_id' => $this->getCaseId(), 'state_id' => $state->id, 'state_wid' => $state->wid, 'base_state_wid' => $currentState->wid, 'unsuspend_at' => $suspend_till]);
                    if (!$currentState->has_attachment)
                        $this->status = 'paused';
                    return;
                }
            }
            return;
        } else if ($currentState->type == 'bpmn:SubProcess') {
            $meta = $this->getSubProcessMeta($currentState->wid);
            if ($meta->isSuccess) {
                $workflowId = $meta->entity['workflow'];
                $startState = $meta->entity['start'];
                $vars = $this->caseService->getCaseOption('vars');

                if ($workflowId) {
                    $this->createCase(['vars' => $vars, 'ws_pro_id' => $workflowId, 'start' => $startState, 'parent_case' => $this->getCaseId(), 'system_options' => ['parent_case' => $this->getCaseId(), 'base_case' => $this->getParentCase(), 'subprocess_state' => $currentState->id]]);
                    $this->status = 'subprocess';
                }
            }
            $this->setEvent(ProcessLogicInterface::WORKFLOW_SUBPROCESS);
            return;
        } else if ($currentState->loop == "bpmn:MultiSeqInstanceLoopCharacteristics") {
            $this->status = 'sequential';
        }

        if ($this->test)
            return;
        //Next user checks
        $userId = $user > 0 ? $user : $this->getNextUserByType($currentState, true);            //user just used in start!

        $this->next_state = $currentState;

        if (isset($userId)) {
            $predicate = ['element_name' => $state ? $state : $this->state, 'case_id' => $this->id];

            if (static::CONFIG_WORKFLOW_USE_FORM) {
                $formId = isset($currentState->options['forms'][0]) ? $currentState->options['forms'][0] : null;
                $this->setNextForm($formId);
            }

            $data = ['meta_value' => is_array($userId) ? ProcessLogicInterface::USER_COMMAN : $userId, 'meta_type' => $currentState->meta_type];
            $this->setNextUser($userId);

            if (!is_array($userId) && $this->isPositionBased($currentState)) {
                $data['meta_value'] = $this->next_position;
                $data['meta_user'] = 1;
            }
            return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_META, $predicate, $data, true);
        }
    }

    public function isEligible($metaReq, $typeReq = null, $stateReq = null)
    {
        $lastState = $this->state;

        $predicate = ['element_name' => $lastState, 'case_id' => $this->id];
        $m = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);
        $this->lastMeta = $m;


        if ($this->test || !static::CONFIG_CHECK_USER) {
            return true;
        }

        if ($this->status == 'unassigned' || $this->status == 'end')
            return false;

        if ($this->status == 'parted' || $this->status == 'subprocess' || $m == null  || !$m->meta_type || $m->meta_type == static::META_TYPE_SUBPROCESS)
            return true;

        $positions = $this->givePositionsOfUser($metaReq);
        $this->user_position = $positions[0];

        if ($this->isPositionBased($m)) {
            $this->user_position = $m->meta_value;
            return in_array($m->meta_value, $positions);
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
            } else if ($type == ProcessLogicInterface::META_TYPE_CYCLIC) {
                $users = $state->options['users'];
                $key = $state->meta_value;
                $state->meta_value = 0;
                if ($key !== null && array_key_exists($key + 1, $users))
                    $state->meta_value = $key + 1;
                if ($save) {
                    $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $state->wid, 'ws_pro_id' => $state->ws_pro_id], ['meta_value' => $state->meta_value]);
                }
                if ($this->isPositionBased($state)) //is position
                {
                    $this->next_position = $users[$state->meta_value];
                    return $this->giveUsersOfPosition($users[$state->meta_value], $state->meta_successor);
                }
                return $users[$state->meta_value];
            } else if ($type == ProcessLogicInterface::META_TYPE_MANUAL) {
                return $this->user_manual ? $this->user_manual : $state->options['users'];
            } else if ($type == ProcessLogicInterface::META_TYPE_VARIABLE) {
                $user = $state->options['users'][0];
                $vars = $this->caseService->getCaseOption('vars');
                $user = $vars[$user] ?: ProcessLogicInterface::USER_NOT_EXIST;
                if (!$user)
                    $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'USER_NOT_EXIST');
                return $user;
            } else if ($type == ProcessLogicInterface::META_TYPE_COMMON) {
                $users = $state->options['users'];
                if ($rival && $this->isUserinState($state, $rival)) {
                    return $rival;
                } else {
                    return $users;
                }
            } else if ($type == ProcessLogicInterface::META_TYPE_COMMON_VARIABLE) {
                $users = $state->options['users']; //users:["x"] // x:[10,5]
                $var = $users[0];

                if ($rival) {
                    $vars = $this->caseService->getCaseOption('vars');

                    if (in_array($rival, $vars[$var])) {
                        return $rival;
                    } else {
                        return 0;
                    }
                } else {
                    $vars = $this->caseService->getCaseOption('vars');
                    // foreach ($vars as $v) {
                    //     $users[$v] = $vars[$v];
                    // }
                    $user = isset($vars[$var]) ? $vars[$var] : null;
                    if (!$user)
                        $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'USER_NOT_EXIST');
                    return $user;
                }
            } else if ($type == ProcessLogicInterface::META_TYPE_PARENT_POSITION) {
                return $this->giveParentPosition($state->meta_value, $this->getMetaReq());
            } else if ($type == ProcessLogicInterface::META_TYPE_SCRIPT_URL) {
                $user = $state->options['users'][0];
                $vars = $this->caseService->getCaseOption('vars');
                $user = $vars[$user];
                if (!$user)
                    $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'USER_NOT_EXIST');
                return $user;
            } else if ($type == ProcessLogicInterface::META_TYPE_SEQUENTIAL) {
                return $this->findNextSequentialUser($state);
            }
        } catch (\Exception $e) {
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'USER_EXCEPTION');
            return ProcessLogicInterface::USER_EXCEPTION; //Not expected
        }

        $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'USER_NO_MATCH');
        return ProcessLogicInterface::USER_NO_MATCH;  //No matches were found
    }

    public function findNextSequentialUser($state)
    {
        $users = $state->options['users'];
        if ($this->getLastMeta()->element_name != $state->wid)
            return $users[0];

        $user = $this->getLastMeta()->meta_value;
        $key = array_search($user, $users);
        if ($state->meta_user == 0) {
            if ($key !== null && array_key_exists($key + 1, $users))
                $key = $key + 1;
            else
                return 0;
        }
        return $users[$key];
    }


    #region Override by user

    public function sendApi($url, $data)
    {
        return 200;
    }

    public function sendMessage($messageType, $center, $sender, $subject,  $message, $users)
    {
        //abort(501);
    }

    public function beforeNext($state)
    {
        //abort(501);
    }

    public function afterNext($state, $event)
    {
        //abort(501);
    }

    public function getCustomUserDisplayName($user_id)
    {
        return $user_id;
    }
    #endregion

    public function getAvailableTransitions($fromState = null, $columns = '*')
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
        return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_TRANSITION, ['from_state' => $fromState, 'ws_pro_id' => $this->wid], $columns, ['gate']);
    }

    public function getPossibleStates($fromState = null, $vars = null)
    {
        $tis = $this->getAvailableTransitions($fromState);
        $result = [];
        foreach ($tis as $t) {
            if ($vars && $tis->count() > 1) {

                //when gate is parallel ???????
                //subprocess
                if ($this->gateService->checkCondition($t->meta, $vars) === false) {
                    continue;
                }
            }
            $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['ws_pro_id' => $this->wid, 'wid' => $t->to_state]);
            if ($state->type == "bpmn:SubProcess")
                $state = $this->findSubprocessFirstState($t->to_state);
            $result[] = ['is_position' => $this->isPositionBased($state), 'next_type' => $state->meta_type, 'next_work' => $state->text, 'next_user' => $this->getNextUserByType($state)];
        }
        if (!isset($result))
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'NO_POSSIBLE_STATE');

        return $result;
    }

    public function findSubprocessFirstState($stateWID) // in getPossibleStates
    {
        $meta = $this->getSubProcessMeta($stateWID);
        if ($meta->isSuccess) {
            $workflowId = $meta->entity['workflow'];
            $startState = $meta->entity['start'];

            if ($workflowId) {
                return $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['ws_pro_id' => $workflowId, 'wid' =>  $startState]);
            }
        }
    }

    public function createCase($inputArray)
    {
        $workflowId = isset($inputArray['ws_pro_id']) ? $inputArray['ws_pro_id'] : null;
        $startState = isset($inputArray['start']) ? $inputArray['start'] : null;
        $userCreator = isset($inputArray['user']) ? $inputArray['user'] : 0;
        $title = isset($inputArray['title']) ? $inputArray['title'] : $workflowId;
        $vars = isset($inputArray['vars']) ? $inputArray['vars'] : null;
        $make_copy = isset($inputArray['copy']) ? $inputArray['copy'] : false;
        $system_options = isset($inputArray['system_options']) ? $inputArray['system_options'] : [];
        $transitionId = isset($inputArray['transition_id']) ? $inputArray['transition_id'] : 0;
        $parentCase = isset($inputArray['parent_case']) ? $inputArray['parent_case'] : 0;
        $userFrom = isset($inputArray['user_from']) ? $inputArray['user_from'] : $this->metaReq ?: 0;

        if (!$workflowId) {
            return new ProcessResponse(false, null, 'WORKFLOW_NO_WORKFLOW', 1);
        }

        if ($vars) {
            $opts['vars'] = $vars;
        }

        $data = ['ws_pro_id' => $workflowId, 'user_creator' => $userCreator, 'status' => 'created'];
        $data['system_options'] = $system_options;
        $data['transition_id'] = $transitionId;
        $data['parent_case'] = $parentCase;
        $data['user_from'] = $userFrom;
        $data['title'] = $title;

        if ($startState) {
            if (!BpmsState::where(['wid' => $startState, 'ws_pro_id' => $workflowId])->first())
                return new ProcessResponse(false, null, 'WORKFLOW_NO_START', 2);
            $data['state'] = $startState;
            $data['status'] = $userCreator < 1 ? 'working' : 'created';
        } else if ($userCreator > 0 && !$startState) {
            $predicate = ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflowId];
            $states = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, $predicate)->toArray();
            if (count($states) > 1)
                return new ProcessResponse(false, null, 'WORKFLOW_MANY_START', 3);
            $data['state'] = reset($states)['wid'];
            $data['status'] = 'working';
            $startState = $data['state'];
        }

        if ($userCreator > 0 && static::CONFIG_FILTER_DUPLICATE_CASE) {
            $duplicatePredicate = ['user_creator' => $userCreator, 'status' => 'created', 'ws_pro_id' => $workflowId];
            $founds = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_CASE, $duplicatePredicate);

            foreach ($founds as $found) {
                if (!isset(BpmsMeta::where('case_id', $found->id)->first()->options['forms'])) {
                    $found->options = $opts;
                    $found->created_at = Carbon::now();
                    $found->save();
                    return new ProcessResponse(true, $found->id, 'WORKFLOW_SUCCESS', 4);
                }
            }
        }

        if (isset($opts)) {
            $data['options'] = $opts;
        }

        if ($make_copy) {
            $data['system_options']['copy'] = true;
        }

        $newCaseId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_CASE, $data);

        if ($userCreator  <  1) {
            $backup = $this->export($this);
        }

        if ($startState) {
            $this->setCaseById($newCaseId);
            $this->setMetaReq($this->findCaseUserCreator($userCreator));
            $this->checkNext($startState, $userCreator);
            $change = $this->saveChanges(ProcessLogicInterface::WORKFLOW_STARTED);
            if ($change['status'] == 'error') {
                BpmsCase::where('id', $newCaseId)->forceDelete();
                return new ProcessResponse(false, null, $change['message'], 5);
            }
        }

        if ($userCreator < 1)
            $this->import($backup);

        $states = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, ['ws_pro_id' => $workflowId]);



        if ($make_copy) {
            foreach ($states as $state) {
                // if ($make_copy) {
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_STATE, 'meta_type' => $state->meta_type, 'element_id' => $state->id, 'element_name' => $state->wid, 'case_id' => $newCaseId, 'meta_value' => $state->meta_value];
                $opts = $state->options;
                $opts['type'] = $state->type;
                $opts['next_wid'] = $state->next_wid;
                $opts['next_type'] = $state->next_type;
                $opts['text'] = $state->text;
                $data['options'] = $opts;
                $meta = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_META, $data);
                // }
            }
            $transitions = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_TRANSITION, ['ws_pro_id' => $workflowId]);
            foreach ($transitions as $t) {
                $opts = $t->options;
                $opts['from_state'] = $t->from_state;
                $opts['to_state'] = $t->to_state;
                $data = ['element_type' => ProcessLogicInterface::ELEMENT_TYPE_TRANSITION, 'meta_type' => 1, 'element_id' => $t->id, 'element_name' => $t->gate_wid, 'case_id' => $newCaseId, 'meta_value' => $t->meta, 'options' => $opts];
                $meta = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_META, $data);
            }
        }
        return new ProcessResponse(true, $newCaseId, 'WORKFLOW_SUCCESS', 4);
    }

    private function findCaseUserCreator($userCreator)
    {
        return $userCreator > 0 ? $userCreator : $this->metaReq ?: 0;
    }

    public function findWorkflowStarts($ws_pro_id = null) //???
    {
        $predicate = ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $ws_pro_id];
        return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, $predicate)->toArray();
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

    public function createWorkflow($inputArray)
    {
        $title = $inputArray['name'];
        $wid = isset($inputArray['wid']) ? $inputArray['wid'] : null;
        $userId = $inputArray['user_id'];
        $ws_id = isset($inputArray['ws_id']) ? $inputArray['ws_id'] : 1;
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

    public function getSubprocessMetaWorkflow($workflow, $state) //is used for set meta_subprocess
    {
        $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $state, 'ws_pro_id' => $workflow->id]);

        $case = 0;
        $workflow = 0;
        $startId = 0;
        $starts = 0;

        if (!$state) {
            return ['case' => $case, 'start' => $startId, 'workflow' => $workflow, 'starts' => $starts];
        }

        if ($opts = $state->options) {
            if (array_key_exists("cases", $opts)) {
                $case = end($opts['cases']);
            }

            if (array_key_exists("start", $opts)) {
                $start = $opts['start'];
                $workflow = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_WORKFLOW, $state->meta_value);
                $starts = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflow->id]);
                $startId = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $start, 'ws_pro_id' => $workflow->id])->id;
            }
        }
        return ['case' => $case, 'start' => $startId, 'workflow' => $workflow, 'starts' => $starts];
    }

    // public function loadSubProcess($caseId)
    // {
    //     $case = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_CASE, $caseId);

    //     if (!$case) {
    //         $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'SUBPROCESS_CASE_NOT_FOUND');
    //         $this->currentState = null;
    //         return;
    //     }
    //     if ($case->status != 'end') {
    //         $this->backupStatus = $this->getStatus();
    //         $this->subProcess = true;
    //         $this->setCase($case);
    //     }
    // }

    #region Part
    public function getPartsStateWID()
    {
        if ($this->test == true) {
            return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_CASE, ['ws_pro_id' => $this->wid, 'user_creator' => static::PART_CASE])->pluck('state');
        }
        return $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_CASE, ['cid' => $this->getBaseCase(), 'user_creator' =>  static::PART_CASE])->pluck('state');
    }

    public function addWorkflowPart($tid, $gid, $from)
    {
        $foundPart = $this->findWorkflowPart(['transition_id' => $tid]);
        if (!$foundPart) {
            $vars = $this->caseService->getCaseOption('vars');
            $this->createCase(['vars' => $vars, 'transition_id' => $tid, 'parent_case' => $this->getCaseId(), 'user' => static::PART_CASE, 'ws_pro_id' => $this->getWID(), 'start' => $from, 'system_options' => ['parent_case' => $this->getCaseId(), 'base_case' => $this->getParentCase()]]);
        }
    }

    public function findWorkflowPart($predicate)
    {
        $predicate = array_merge($predicate, ['user_creator' => static::PART_CASE, 'cid' => $this->baseCase]); //important to search in parent cases
        return $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_CASE, $predicate);
    }

    public function deletePart($id)
    {
        return $this->dataRepo->deleteEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $id]);
    }

    // public function countWorkflowPart()
    // {
    //     if ($this->test == true) {
    //         return $this->dataRepo->countEntity(DataRepositoryInterface::BPMS_FAKEPART, ['ws_pro_id' => $this->id]);
    //     } else {
    //         return $this->dataRepo->countEntity(DataRepositoryInterface::BPMS_PART, ['case_id' => $this->id]);
    //     }
    // }

    #endregion

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

    public function setCurrentState($state)
    {
        $getState = $this->getCurrentState($state);
        $this->currentState = $getState->isSuccess ? $getState->entity : null;
        $this->state = $state;
    }

    public function isEndedWorkflow()
    {
        $s = $this->getCurrentState($this->state)->entity;
        $check = ($s->type == 'bpmn:EndEvent');
        $this->next_state = $s;

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

    public function getSubProcessMeta($stateWID)
    {
        try {
            if ($this->test == true) {
                $res = $this->getCurrentState($stateWID);
                $res = $res->isSuccess ? $res->entity : null;
            } else {
                $res = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $stateWID]);
            }

            if (!$res)
                return new ProcessResponse(false, null);

            $workflowId = $res->meta_value;
            $opts = $res->options;
            $hasCases = array_key_exists("cases", $opts);
            $caseId = $hasCases ? end($opts['cases']) : null;
            $startState = $opts['start'];
            $in_vars = $opts['in_vars'];
            $out_vars = $opts['in_vars'];

            return new ProcessResponse($res && $workflowId ? true : false, ['workflow' => $workflowId, 'case' => $caseId, 'start' => $startState, 'in_vars' => $in_vars, 'out_vars' => $out_vars], 'OK');
        } catch (\Exception $e) {
            return new ProcessResponse(false, 'No subprocess is provided', 'OK');
        }
    }

    // public function setSubProcessMeta($stateWID, $caseId)
    // {
    //     try {
    //         if ($this->test == true) {
    //             $this->updateStateOptions($stateWID, $caseId);
    //         } else {
    //             $this->setMetaOptions($stateWID, 'cases', $caseId, 3);
    //         }
    //     } catch (\Exception $e) {
    //         return new ProcessResponse(false, 'Exception occurred', 'OK');
    //     }
    // }

    // public function updateStateOptions($stateWID, $caseId) //is used in subprocess!!!
    // {
    //     $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
    //     $s = $this->getCurrentState($stateWID);
    //     $opts = $s->entity->options;
    //     $opts['cases'][] = $caseId;

    //     $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE, $predicate, ['options' => $opts]);
    // }

    public function setMetaOptions($stateWID, $option, $data, $type = 1)
    {
        $predicate = ['element_type' => $type, 'element_name' => $stateWID ? $stateWID : $this->state, 'case_id' => $this->getCaseId()];
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

    public function addActivityLog($data = null)
    {
        if ($this->test)
            return;

        if ($data) {
            $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_ACTIVITY, $data);
            return $activityId;
        }

        $type = $this->getEvent();
        $case = $this->getCase();
        $last = $case->activity_id;
        $user_from = $this->getMetaReq();
        $data = ['case_id' => $this->getBaseCase(), 'original_case_id' => $this->id, 'type' => $type, 'transition_id' => $this->transitionFired, 'comment' => $this->comment, 'pre' => $last, 'part_id' => $this->partId ?: 0, 'user_id' => $user_from];
        $data['position_id'] = $this->user_position;
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

        if ($type == ProcessLogicInterface::WORKFLOW_ENDED || $type == ProcessLogicInterface::WORKFLOW_SUBPROCESS) {
            $data['finished_at'] = date("Y-m-d H:i:s");
            $data['user_id'] = $user_from;
            $data['options->user_name'] = $this->user_name;
        }

        if ($last) {
            $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_ACTIVITY, ['id' => $last], ['finished_at' => date("Y-m-d H:i:s"), 'user_id' => $user_from, 'options->user_name' => $this->user_name], true);
            if ($type == ProcessLogicInterface::WORKFLOW_SUBPROCESS)
                return;
        }

        if ($type == ProcessLogicInterface::WORKFLOW_BACKED) {
            $lastActivity = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_ACTIVITY, $last);
            $transition = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_TRANSITION, ['id' => $lastActivity->transition_id]);
            $fromStateId = $transition->from_state;
            //$preLastActivity = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_ACTIVITY, $lastActivity->pre);
            $preLastActivity = $this->dataRepo->findEntityByOrder(DataRepositoryInterface::BPMS_ACTIVITY, ["case_id" => $this->id, "options->element_name" => $fromStateId], 'id', 'desc');
            $data = ['case_id' => $this->id, 'type' => $type, 'transition_id' => $preLastActivity->transition_id, 'comment' => $this->comment ?: 'WORKFLOW_BACKED', 'pre' => $last, 'part_id' => $this->partId ?: 0, 'user_id' => $preLastActivity->user_id, 'options' => $preLastActivity->options];
            $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_ACTIVITY, $data);
            $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['ws_pro_id' => $this->wid, 'wid' => $fromStateId]);
            $formId = $state->options['forms'][0];
            $data = ['activity_id' => $activityId, 'state' => $fromStateId, 'user_current' => $preLastActivity->user_id, 'user_from' => $user_from, 'status' => $preLastActivity->user_id == -1 ? 'unassigned' : 'working', 'form_id' => $formId];
            $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], $data);
            return;
        }

        $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_ACTIVITY, $data);
        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], ['activity_id' => $activityId, 'cid' => $this->getBaseCase()]);
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
                    'task' => $activity->options['text'] ?? '',
                    'element_name' => $activity->options['element_name'] ?? '',
                    'user_name' => $activity->user_id == ProcessLogicInterface::USER_COMMAN ? $this->getCustomUserDisplayName($activity->options['users']) : (isset($activity->options['user_name']) ? $activity->options['user_name'] : $this->getCustomUserDisplayName($activity->user_id)),
                    'user_id' => $activity->user_id == ProcessLogicInterface::USER_COMMAN ? $activity->options['users'] : $activity->user_id,
                    'start_date' => $activity->created_at,
                    'end_date' => $activity->finished_at,
                    'comment' => $activity->comment,
                    'duration' => Carbon::parse($activity->finished_at)->diffInSeconds(Carbon::parse($activity->created_at)),
                    'status' => $activity->type == ProcessLogicInterface::WORKFLOW_CHANGE_USER ? 'تغییر کاربر' : $activity->finished_at ? 'تکمیل شده' : 'در حال انجام',
                    'options' => $activity->options ?? []
                ];

            $res[] = $temp;
            $index++;
        }
        return $res;
    }

    public function getStatus($inputArray = null)
    {
        if ($inputArray) {
            $this->checkRival($inputArray);
            if ($this->getEvent() == ProcessLogicInterface::WORKFLOW_EXCEPTION)
                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_EXCEPTION, false);
        }

        // if ($this->subProcess) {
        //     return $this->backupStatus;
        // }

        if ($this->status == 'parted') {
            $data = ['status' => $this->status, 'state' => $this->getPartsStateWID(), 'base' => $this->getBaseState()];
        } else {
            $data = ['status' => $this->status, 'state' => $this->state];
        }

        if ($data['status'] == "end")
            return $data;

        if (!$this->test && static::CONFIG_WORKFLOW_USE_FORM) {
            $formData = $this->formService->getFirstFormOfState($this->state);
            if (!$formData->isSuccess) {
                $data['type'] = ProcessLogicInterface::NEXT_ERROR;
                $data['status'] = "error";
                $data['message'] = $formData->message;
                return $data;
            }

            if ($formData->isSuccess && $formData->code == 2) {
                $data['type'] = ProcessLogicInterface::NEXT_NEXT;
                $data['status'] = "working";
                return $data;
            }

            $data['form'] = $formData->entity;
        }

        if (!$this->test)
            if ($case_vars = $this->caseService->getCaseOption('vars')) {
                $vars = $this->workflow->variables;
                foreach ($vars as $var)
                    $res[$var->name] = (isset($case_vars[$var->name]) && !empty($case_vars[$var->name])) ? ($case_vars[$var->name]) : ($var->type_id == 4 ? [] : 0);
                $data['vars'] = isset($res) ? array_replace_recursive($case_vars, $res) : null;
            }
        $stateWID = $data['state'];
        $resdultNameCurrentState = BpmsState::where('wid', $stateWID)->first()->text;

        if (isset($data['form'])) {
            $formId = $data['form']['id'];
            $info = $this->formService->getFormElements(['id' => $formId]);
        }

        $data['elements'] = isset($info) ? $info['elements'] : '';
        $data['state_text'] = !empty($resdultNameCurrentState) ? $resdultNameCurrentState : '';

        return $data;
    }

    public function checkRival($inputArray)
    {
        $user = isset($inputArray['user']) ? $inputArray['user'] : null;
        $state = isset($inputArray['state']) ? $inputArray['state'] : null;

        if (!$user || !$state) {
            return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], ['seen' => true]);
        }

        $s = $this->getCurrentState($state);

        $userId = $this->getNextUserByType($s->entity, $save = true, $rival = $user);
        if (!empty($userId)) {
            $predicate = ['element_name' => $state, 'case_id' => $this->id];

            if ($this->isPositionBased($s->entity))
                $data = ['meta_value' => $this->next_position];
            else
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
        $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'WORKFLOW_RIVAL_NO_USER');
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
            $data = ['gate_wid' => null, 'from_state' => $transition['from'], 'to_state' => $transition['to'], 'ws_pro_id' => $process, 'type' => $transition['isBoundary'] ?? 0];

            $tid = $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_TRANSITION, $predicate, $data, true);
            $to_keep[] = $tid;
        }

        if ($to_keep) {
            $this->dataRepo->deleteNotIn(DataRepositoryInterface::BPMS_TRANSITION, ['ws_pro_id' => $process], $to_keep);
            $to_keep = [];
        }

        foreach ($states as $state) {
            $data = ['loop' => isset($state['loop']) ? $state['loop'] : 'NaN', 'wid' => $state['id'], 'type' => $state['type'], 'position_state' => $state['position'], 'text' => $state['name'], 'next_wid' => $state['next']['id'], 'next_type' => $state['next']['type'], 'ws_pro_id' => $process];
            if (isset($state['attachers'])) {
                $data['has_attachment'] = isset($state['attachers']['id']);
                $data['attacher_wid'] = $state['attachers']['id'] ?? NULL;
                $data['attacher_type'] = $state['attachers']['type'] ?? NULL;
            }
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
                    $data = ['gate_wid' => $gate['id'], 'default' => false];
                    if (!empty($gate['default']) && $gate['default'] == $out)
                        $data['default'] = true;
                    $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_TRANSITION, ['from_state' => $in, 'to_state' => $out], $data);
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

            case ProcessLogicInterface::WORKFLOW_SUBPROCESS:
                $this->addActivityLog();
                return ['status' => 'subprocess', 'type' => static::NEXT_NEXT];

            case ProcessLogicInterface::WORKFLOW_NO_PATH:
                return ['status' => 'error', 'state' => $this->backupState, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_NO_PATH'];

            case ProcessLogicInterface::WORKFLOW_EXCEPTION:
                return ['status' => 'error', 'state' => $this->backupState, 'type' => static::NEXT_ERROR, 'message' => $this->error];

            case ProcessLogicInterface::WORKFLOW_ENDED:
                $this->checkForParentCase();
                $this->addActivityLog();
                return ['status' => 'end', 'state' => $this->state, 'type' => static::NEXT_NEXT];

            case ProcessLogicInterface::WORKFLOW_WAIT_FOR_ANOTHER:
                return ['status' => 'error', 'state' => $this->backupState, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_WAIT_FOR_ANOTHER'];

            case ProcessLogicInterface::WORKFLOW_NO_META:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_NO_META'];

            case ProcessLogicInterface::WORKFLOW_PART_ISNULL:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_PART_ISNULL'];

            case ProcessLogicInterface::WORKFLOW_IS_IN_SUBPROCESS:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_IS_IN_SUBPROCESS'];

            case ProcessLogicInterface::WORKFLOW_IN_PART_MODE:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_IN_PART_MODE'];

            case ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => $this->error];

            case ProcessLogicInterface::WORKFLOW_LONELY_PART:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_LONELY_PART'];

            case ProcessLogicInterface::WORKFLOW_STATE_NOTFOUND:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_STATE_NOTFOUND'];

            case ProcessLogicInterface::WORKFLOW_PAUSED:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_PAUSED'];

            default:
        }

        $this->addActivityLog();
        return $this->getStatus();
    }

    public function checkForParentCase()
    {
        if ($parent_case = $this->hasParentCase()) {
            //$parent_case = $this->getParentCase();
            $case = BpmsCase::findOrFail($parent_case);
            if ($case->status == 'subprocess') {
                $this->updateEntity(static::BPMS_CASE, ['id' => $parent_case], ['status' => 'working']);

                $logic = app()->make(ProcessLogic::class);
                $input = ['metaReq' => $this->metaReq, 'nextPreview' => false];
                $logic->setCaseById($parent_case);
                return $logic->goNext($input);
            }
        }
    }

    public function saveWorkflow()
    {
        $predicate = ['id' => $this->wid];
        $data['state'] = $this->state;
        $data['status'] = $this->status;

        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_WORKFLOW, $predicate, $data);
    }

    public function saveCase()
    {
        $data['status'] = $this->status;
        $data['user_from'] = $this->getMetaReq();
        $data['form_id'] = $this->next_form;
        $userId = $this->getNextUser();
        $data['position_from'] = $this->user_position ?: 0;

        if (is_array($userId)) {
            $data['user_current'] = static::USER_COMMAN;
            $data['status'] = 'unassigned';
            $data['system_options']['users'] = $userId;
        } else {
            $data['user_current'] = $userId;
            $data['position_current'] =  $this->next_position;
        }

        if ($this->status == 'end')
            $data['finished_at'] = date("Y-m-d H:i:s");


        $data['state'] = $this->state;
        $data['seen'] = false;
        $predicate = ['id' => $this->id];

        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, $predicate, $data);
        $this->case = $this->case->fresh(); //Updated case is needed in After next function
    }

    public function nextLogic()
    {
        if (!$this->currentState && $this->status != "created")
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_STATE_NOTFOUND, false);

        else if ($this->status == "parted")
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_IN_PART_MODE, false);


        else if ($this->status == "paused")
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_PAUSED, false);

        else if ($this->status == "sequential") {
            $user = $this->findNextSequentialUser($this->currentState);
            if ($user) {
                $this->checkNext($this->state);
                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_WORKING);
            }
            $this->status = 'working';
        } else if ($this->status == 'end' && $this->test) { //workflow to restart
            $this->state = $this->getFirstStateWID();
            $this->status = 'working';
            $this->checkNext($this->state);
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_RESTARTED);
        } else if ($this->status == 'end')
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_ENDED_BEFORE, false);

        else if ($this->status == 'subprocess')
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_IS_IN_SUBPROCESS, false);

        else if ($this->status == "created" && !$this->state) { //case to start
            $this->state = $this->getFirstStateWID();
            $this->status = 'working';

            $this->checkNext($this->state);
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_STARTED);
        } else if ($this->status == 'created') {
            $this->status = 'working';
        }


        do {
            $next_type =  $this->currentState->next_type;
            $this->stateHasAttachment = $this->currentState->has_attachment;
            if ($this->stateHasAttachment) {
                $attachmentWID = $this->currentState->attacher_wid;
                $foundAttachment = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['wid' => $attachmentWID, 'ws_pro_id' => $this->getWID()]);
                $this->attachment_id = $foundAttachment->id;
            }

            try {
                $available = [];
                $tis = $this->getAvailableTransitions();
                foreach ($tis as $t) {
                    if ($t->default == 1)
                        $nextTransition = $t->id;
                    $available[$t->id] = $t;
                }

                if (count($available) > 1) {
                    foreach ($available as $toGoId => $transition) {
                        $toGoTransition = $transition;

                        if (!$toGoTransition) {
                            continue;
                        }

                        $next_type = $transition->gate->type;
                        if ($next_type == "bpmn:ExclusiveGateway" || $next_type == "bpmn:InclusiveGateway") {

                            $meta = $transition->meta;
                            if (!$meta) {
                                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_NO_META, false);
                            }

                            $ret = $this->gateService->checkCondition($meta, $this->getVars());

                            if ($ret === -1) //Exception occurred
                            {
                                $this->setEvent(ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR, "WORKFLOW_EVALUATION_ERROR");
                                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR, false);
                            }

                            if ($ret == true && $next_type == "bpmn:ExclusiveGateway") {
                                $nextTransition = $toGoTransition->id;
                                break;
                            } elseif ($ret == true && $next_type == "bpmn:InclusiveGateway") {
                                $this->addWorkflowPart($toGoTransition->id, $toGoTransition->gate_wid, $toGoTransition->to_state);
                                $this->status = 'parted';
                                $isParellel = true;
                            }
                        } //End of check for conditional gates
                        else { //Parallel gate
                            $this->addWorkflowPart($toGoTransition->id, $toGoTransition->gate_wid, $toGoTransition->to_state);
                            $isParellel = true;
                            $this->status = 'parted';
                        }
                    } // end of foreach
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

                        // $done = true;

                        foreach ($trans as $t) {
                            $friendPart = $this->findWorkflowPart(['state' => $t->from_state]);
                            if ($friendPart == null && ($this->state != $t->from_state)) {
                                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_WAIT_FOR_ANOTHER, false);
                            }
                        }

                        // if ($done == false) { //WORKFLOW_WAIT_FOR_ANOTHER
                        //     return $this->saveChanges(ProcessLogicInterface::WORKFLOW_WAIT_FOR_ANOTHER, false);
                        // }
                        //All parts are done
                        // foreach ($trans as $t) {
                        //     if ($t->id == $toGoId) {
                        //         continue;
                        //     }

                        //     $friendPart = $this->findWorkflowPart(['state' => $t->from_state]);

                        //     if ($friendPart != null) {
                        //         $this->deletePart($friendPart->id);
                        //     }
                        // }

                        $nextTransition = $toGoTransition->id;
                    } else {
                        $nextTransition = $toGoId;
                    }
                }

                if (isset($isParellel)) {
                    //if ($this->hasParentCase())
                    //$this->deletePart($this->getCaseId());
                    return $this->saveChanges(ProcessLogicInterface::WORKFLOW_PART_CREATED);
                }

                if (isset($nextTransition)) {
                    $this->setTransitionFired($nextTransition, $available);
                } else {
                    return $this->saveChanges(ProcessLogicInterface::WORKFLOW_NO_PATH, false);
                }
            } catch (\Exception $e) {
                $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, $e->getMessage());
                return $this->saveChanges(ProcessLogicInterface::WORKFLOW_EXCEPTION, false);
            }
            $endResult = $this->isEndedWorkflow();

            if ($endResult != ProcessLogicInterface::WORKFLOW_ENDED && $endResult != ProcessLogicInterface::WORKFLOW_PART_ENDED)
                $this->checkNext($this->state);
        } while ($this->currentState->type == "bpmn:MessageEventDefinition"); //continue to reach to a task
        return $this->saveChanges($endResult);
    }

    public function backLogic()
    {
        $state = $this->getCurrentState();

        if (!$state->isSuccess) {
            return ['error' => 'Back is not checked.'];
        }

        $back = isset($state->entity->options['back']) ? $state->entity->options['back'] : true;
        if (!$back) {
            return ['error' => 'Back is not enabled.'];
        }

        return $this->saveChanges(ProcessLogicInterface::WORKFLOW_BACKED, false);
    }

    public function changeUser($stateWID, $userId)
    {
        $meta = BpmsMeta::where(['element_name' => $stateWID, 'case_id' => $this->id])->first();

        if ($meta->meta_value == $userId)
            return false;

        $meta->meta_value = $userId;
        $meta->save();


        $activity = BpmsActivity::find($this->case->activity_id);
        $activity->finished_at = date("Y-m-d H:i:s");
        $activity->type = ProcessLogicInterface::WORKFLOW_CHANGE_USER;
        $activity->save();

        $activity = $activity->replicate();
        $activity->user_id = $userId;
        $activity->type = ProcessLogicInterface::WORKFLOW_WORKING;
        $activity->finished_at = null;
        $activity->save();


        $this->case->user_current = $userId;
        $this->case->activity_id = $activity->id;
        $this->case->save();

        return true;
        //Parted
    }

    public function getFirstStateWID()
    {
        $firstState = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $this->wid]);
        $firstStateWID = $firstState->wid;
        return $firstStateWID;
    }

    public function checkForTimer()
    {
        $now = Carbon::now();
        $cases = BpmsTimer::where('unsuspend_at', '<=', $now)->get();
        $logic = app()->make(ProcessLogic::class);

        foreach ($cases as $case) {
            $input = ['metaReq' => 0, 'nextPreview' => false];
            $foundCase = BpmsCase::find($case->case_id);
            if ($case->base_state_wid == $case->state_wid && $foundCase->state == $case->state_wid) {
                $logic->setCaseById($case->case_id);
                $res = $logic->goNext($input);
                if ($res['status'] == 'error') {
                    $foundCase->status = 'error';
                    $foundCase->save();
                    continue;
                } else
                    $case->delete();
            } else if ($foundCase->state == $case->base_state_wid) {
                $logic->setCaseById($case->case_id);
                $logic->setCurrentState($case->state_wid);
                $res = $logic->goNext($input);
                if ($res['status'] == 'error') {
                    $foundCase->status = 'error';
                    $foundCase->save();
                    continue;
                } else
                    $case->delete();
            }
        }

        return $cases->count();
    }

    public function goNext($inputArray)
    {
        $user = $inputArray['user'] ?? null;
        if ($user) {
            $metaReq = $user->id;
            $this->user_name = $user->name;
        } else {
            $metaReq = $inputArray['metaReq'] ?? null;
            $this->user_name = $metaReq ? $this->getCustomUserDisplayName($metaReq) : 'NoName';
        }
        $vars = $inputArray['vars'] ?? null;
        $stateReq = $inputArray['stateReq'] ?? null;
        $typeReq = $inputArray['typeReq'] ?? null;
        $commentText = $inputArray['commentText'] ?? null;
        $form = $inputArray['form'] ?? null;
        $user_manual = $inputArray['user_manual'] ?? null;
        $preview = $inputArray['preview'] ?? static::CONFIG_NEXT_PREVIEW;

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
                $this->caseService->setCaseOption('vars', $vars);
            }

            if ($form && !$this->test && $this->state) {
                $this->setMetaOptions($this->state, 'forms', $form);
            }

            if ($this->isStateDone($form)) {
                if ($preview && $form) {
                    $this->preview_next = $this->getPossibleStates($this->state, $vars);
                    return $this->saveChanges(ProcessLogicInterface::WORKFLOW_PREVIEW, false);
                } else {
                    $beforeRes = $this->beforeNext($this->currentState);
                    if ($beforeRes['status'] == 'error')
                        return $beforeRes;
                    $res = $this->nextLogic();
                    $this->afterNext($this->currentState, $this->event);
                    $res['type'] = isset($res['type']) ? $res['type'] : ProcessLogicInterface::NEXT_NEXT;
                    return $res; // get first form content
                }
            } else {
                return ['status' => 'form', 'type' => ProcessLogicInterface::NEXT_FORM, 'next_form' => $this->next_form]; //get next_form content
            }
        } else {
            return ['type' => ProcessLogicInterface::NEXT_BADACCESS];
        }
    }

    public function saveDraft($inputArray = null)
    {
        $vars = isset($inputArray['vars']) ? $inputArray['vars'] : null;
        $form = isset($inputArray['form']) ? $inputArray['form'] : null;

        if ($vars)
            $this->caseService->setCaseOption('vars', $vars);

        if ($form)
            $this->setMetaOptions($this->state, 'forms', $form);
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
}
