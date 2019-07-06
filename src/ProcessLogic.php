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
        CONFIG_CHECK_USER = true,
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

    private $part;

    private $subProcess = false;

    private $backupStatus;

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

    private $vars = null;

    private $stateReq = null;

    private $partId;

    protected $dataRepo;

    private $baseTable = false;

    private $user_manual = null;

    private $error = null;

    private $state_first_access = false;

    private $preview_next = null;

    protected $formService;

    protected $gateService;

    protected $caseService;


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

    public function hasParentCase()
    {
        $system_options = $this->case->system_options;
        if ($system_options && $system_options['parent_case'])
            return $system_options['parent_case'];
        return 0;
    }

    public function getParentCase()
    {
        if ($this->hasParentCase())
            return $this->case->system_options['parent_case'];
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
        $res = array();
        foreach ($states as $state) {
            if ($state->meta_type == 1 && $state->meta_value == $userId) { //explicit user
                $res[] = $state;
                continue;
            } else if ($this->isPositionBased($state->meta_type)) {
                $positions = isset($state->options['users']) ? $state->options['users'] : null;
                $position = $this->givePositionOfUser($userId);
                if (in_array($position, $positions)) {
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

    public function isUserAtStart($userId, $ws_pro_id = null)
    {
        $predicate = ['position_state' => ProcessLogicInterface::POSITION_START];
        if ($ws_pro_id) {
            $predicate['ws_pro_id'] = $ws_pro_id;
        }

        $states = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, $predicate);
        foreach ($states as $state) {
            if ($state->meta_type == static::META_TYPE_USER && $state->meta_value == $userId) {
                return true;
            } else if ($state->meta_type == static::META_TYPE_COMMON_POSITION || $state->meta_type == static::META_TYPE_CYCLIC_POSITION) {
                $positions = isset($state->options['users']) ? $state->options['users'] : null;
                $position = $this->givePositionOfUser($userId);
                if (in_array($position, $positions)) {
                    return true;
                }
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
                $in_vars = $meta->entity['in_vars'];

                $vars = $this->caseService->getCaseOption('vars', $in_vars);

                if (!$caseId) {
                    $caseResponse = $this->createCase(['ws_pro_id' => $workflowId, 'start' => $startState, 'vars' => $vars]);
                    $this->setSubProcessMeta($currentState->wid, $caseResponse->entity);
                }
                $this->loadSubProcess($caseId);
            }
        }
    }

    public function checkNext($state = null, $user = null)
    {
        $this->state_first_access = true;

        $s = $this->getCurrentState($state);

        if ($s->isSuccess) {
            $currentState = $s->entity;
        } else {
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'STATE_NOT_EXIST');
            return;
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

        if ($currentState->type == "bpmn:MessageEventDefinition") //Message event
        {
            $state = $this->currentState;
            if ($state->meta_type == ProcessLogicInterface::META_TYPE_MESSAGE_VARIABLE) {
                $user = $state->options['users'][0];
                $vars = $this->caseService->getCaseOption('vars');
                $user = $vars[$user] ?: ProcessLogicInterface::USER_NOT_EXIST;
            } else
                $user = $state->options['users'][0];
            $message = $state->options['message'];
            $this->sendMessage($message, $user);
            return;
        }

        if ($currentState->type == "bpmn:TimerEventDefinition") //Timer event
        {
            $state = $this->currentState;
            if ($state->meta_type == ProcessLogicInterface::META_TYPE_TIMER_VARIABLE) {
                $till = $state->options['var'];
                $vars = $this->caseService->getCaseOption('vars');
                $suspend_till = $vars[$till] ?: null;
            } else {
                $type = $state->options['type'];
                if ($type == 1) //Waitfor
                {
                    $day = $state->options['type'];
                    $hour = $state->options['hour'];
                    $minute = $state->options['minute'];
                    $suspend_till = Carbon::now()->addDays($day)->addHours($hour)->addMinutes($minute);
                    BpmsTimer::create(['case_id' => $this->getCaseId(), 'part_id' => 0, 'unsuspend_at' => $suspend_till]);
                    return;
                }
            }
            return;
        }

        if ($currentState->type == 'bpmn:SubProcess') {
            $meta = $this->getSubProcessMeta($currentState->wid);
            if ($meta->isSuccess) {
                $workflowId = $meta->entity['workflow'];
                $caseId = $meta->entity['case'];
                $startState = $meta->entity['start'];

                if ($workflowId) {
                    $caseResponse = $this->createCase(['ws_pro_id' => $workflowId, 'start' => $startState, 'system_options' => ['parent_case' => $this->getCaseId(), 'subprocess_state' => $currentState->id]]);
                    $this->setSubProcessMeta($currentState->wid, $caseResponse->entity);
                    $this->status = 'subprocess';
                }
            }
            $this->setEvent(ProcessLogicInterface::WORKFLOW_SUBPROCESS);
            return;
        }

        if (!$this->test) { //Next user checks
            $userId = $this->getNextUserByType($currentState, true);
            $this->next_state = $currentState;

            //Just used in start!
            if ($user) {
                $userId = $user;
            }

            if (isset($userId)) {
                $predicate = ['element_name' => $state ? $state : $this->state, 'case_id' => $this->id];
                // $m = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, $predicate);

                $formId = isset($currentState->options['forms'][0]) ? $currentState->options['forms'][0] : null;
                $this->setNextForm($formId);

                $data = ['meta_value' => is_array($userId) ? ProcessLogicInterface::USER_COMMAN : $userId, 'meta_type' => $currentState->meta_type];
                $this->setNextUser($userId);

                if (!is_array($userId) && $this->isPositionBased($currentState->meta_type))
                    $data['meta_value'] = $this->givePositionOfUser($userId);
                return $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_META, $predicate, $data, true);
            }
        }
    }

    public function isEligible($metaReq, $typeReq = null, $stateReq = null)
    {
        $lastState = $this->state;

        if ($this->test || !static::CONFIG_CHECK_USER) {
            return true;
        }

        if ($this->status == 'unassigned')
            return false;



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

        if ($this->status == 'subprocess' || $m->meta_type == static::META_TYPE_SUBPROCESS)
            return true;

        if ($m == null || !$m->meta_type) { //state with no meta!
            return true;
        }

        if ($this->isPositionBased($m->meta_type)) {
            $position = $this->givePositionOfUser($metaReq);
            $this->user_position = $position;
            return $position == $m->meta_value;
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
            } else if ($type == ProcessLogicInterface::META_TYPE_CYCLIC_POSITION) {
                $positions = $state->options['users'];
                $key = $state->meta_value;
                if ($key !== null) {
                    if (array_key_exists($key + 1, $positions)) {
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
                return $this->selectUserOfPosition($positions[$state->meta_value], $rival);
            } else if ($type == ProcessLogicInterface::META_TYPE_USER) {
                return $state->meta_value;
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

                if ($rival) {
                    if (in_array($rival, $users)) {
                        return $rival;
                    }
                } else {
                    return $users;
                }
            } else if ($type == ProcessLogicInterface::META_TYPE_COMMON_POSITION) {
                $positions = $state->options['users'];
                if ($rival) {
                    $position = $this->givePositionOfUser($rival);
                    if (in_array($position, $positions)) {
                        return $rival;
                    }
                } else {
                    return $positions;
                }
            } else if ($type == ProcessLogicInterface::META_TYPE_ARRAY_VARIABLE) {
                $users = $state->options['users'];

                if ($rival) {
                    $vars = $this->caseService->getCaseOption('vars');

                    if (in_array($rival, $vars)) {
                        return $rival;
                    } else {
                        return 0;
                    }
                } else {
                    $vars = $this->caseService->getCaseOption('vars');
                    foreach ($users as $u) {
                        $temp[$u] = $vars[$u];
                    }
                    return $temp;
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
            }
            // else if ($type == ProcessLogicInterface::META_TYPE_COMMON_CUSTOM) {
            //     abort(501);
            //     $users = $state->options['users'];

            //     if ($rival) {
            //         if (in_array($rival, $this->getCustomUsers($users))) {
            //             return $rival;
            //         }
            //     } else if ($save && !$rival) {
            //         $res = $this->getCustomUsers($users);
            //         return count($res) > 1 ? $res : $res[0];
            //     } else {
            //         return $this->getCustomUsersText($users);
            //     }
            // } 
            else if ($type == ProcessLogicInterface::META_TYPE_SCRIPT_URL) {
                $user = $state->options['users'][0];
                $vars = $this->caseService->getCaseOption('vars');
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

    public function selectUserOfPosition($position, $userId)
    {
        if ($userId === false) //in preview mode
            return $position;
        $users = $this->giveUsersOfPosition($position);
        if (in_array($userId, $users))
            return $userId;
    }

    public function sendApi($url, $data)
    {
        return 200;
    }

    public function sendMessage($message, $user)
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

    // public function getCustomUsers($users_option)
    // {
    //     return [1,2,3,4,5,6,7,8,9,10];
    // }

    // public function getCustomUsersText($users_option)
    // {
    //     return 'USERS_OPTION_TEXT';
    // }

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

                if ($this->gateService->checkCondition($t->meta, $vars) === false) {
                    continue;
                }
            }
            $state = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_STATE, ['ws_pro_id' => $this->wid, 'wid' => $t->to_state]);
            $result[] = ['is_position' => $this->isPositionBased($state->meta_type), 'next_type' => $state->meta_type, 'next_work' => $state->text, 'next_user' => $this->getNextUserByType($state)];
        }
        if (!isset($result))
            $this->setEvent(ProcessLogicInterface::WORKFLOW_EXCEPTION, 'NO_POSSIBLE_STATE');

        return $result;
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

        if (!$workflowId) {
            return new ProcessResponse(false, null, 'WORKFLOW_NO_WORKFLOW', 1);
        }

        if ($vars) {
            $opts['vars'] = $vars;
        }

        $data = ['ws_pro_id' => $workflowId, 'user_creator' => $userCreator, 'status' => 'created'];
        $data['system_options'] = $system_options;

        if ($startState) {
            if (!BpmsState::where(['wid' => $startState, 'ws_pro_id' => $workflowId])->first())
                return new ProcessResponse(false, null, 'WORKFLOW_NO_START', 2);
            $data['state'] = $startState;
            $data['status'] = 'created';
        } else if ($userCreator != static::SYSTEM_CASE && !$startState) {
            $predicate = ['position_state' => ProcessLogicInterface::POSITION_START, 'ws_pro_id' => $workflowId];
            $states = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_STATE, $predicate)->toArray();
            if (count($states) > 1)
                return new ProcessResponse(false, null, 'WORKFLOW_MANY_START', 3);
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

        $data['title'] = $title;

        $newCaseId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_CASE, $data);


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
                return new ProcessResponse(false, null, $change['message'], 5);
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
        return new ProcessResponse(true, $newCaseId, 'WORKFLOW_SUCCESS', 4);
    }

    public function findWorkflowStarts($ws_pro_id = null)
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

    #region Part
    public function getPartsStateWID()
    {
        if ($this->test == true) {
            return $parts = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_FAKEPART, ['ws_pro_id' => $this->wid])->pluck('state');
        }
        return $parts = $this->dataRepo->findEntities(DataRepositoryInterface::BPMS_PART, ['case_id' => $this->id]->pluck('state'));
    }

    public function setPart($state = null)
    {
        if ($this->test == true) {
            $this->part = $this->dataRepo->findEntityByRandom(DataRepositoryInterface::BPMS_FAKEPART, ['ws_pro_id' => $this->wid]);
        } else {
            //$this -> part = $this ->case -> parts() ->inRandomOrder()->first();
            $this->part = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_PART, ['case_id' => $this->id, 'state' => $state ?: $this->getStateReq()]);
        }

        $this->state = $this->part->state;
        $this->partId = $this->part->id;
        $this->checkSubProcess();
    }

    public function addWorkflowPart($tid, $gid, $from)
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

    public function getSubProcessMeta($stateWID)
    {
        try {
            if ($this->test == true) {
                $res = $this->getCurrentState($stateWID);
                $res = $res->isSuccess ? $res->entity : null;
            } else {
                $res = $this->dataRepo->findEntity(DataRepositoryInterface::BPMS_META, ['element_type' => 3, 'element_name' => $stateWID, 'case_id' => $this->getCaseId()]);
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

    public function updateStateOptions($stateWID, $caseId) //is used in subprocess!!!
    {
        $predicate = ['wid' => $stateWID, 'ws_pro_id' => $this->wid];
        $s = $this->getCurrentState($stateWID);
        $opts = $s->entity->options;
        $opts['cases'][] = $caseId;

        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_STATE, $predicate, ['options' => $opts]);
    }

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
        $data = ['case_id' => $this->getParentCase(), 'original_case_id' => $this->id, 'type' => $type, 'transition_id' => $this->transitionFired, 'comment' => $this->comment, 'pre' => $last, 'part_id' => $this->partId ?: 0, 'user_id' => $user_from];
        $data['position_id'] = $this->givePositionOfUser($user_from);
        $user_current = $this->getNextUser();

        if (is_array($user_current)) {
            $data['user_id'] = ProcessLogicInterface::USER_COMMAN;
            $opts['users'] = $user_current;
        } else {
            $data['user_id'] = $user_current;
            //$data['position_current'] = $this->givePositionOfUser($user_current);
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
            $preLastActivity = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_ACTIVITY, $lastActivity->pre);


            $data = ['case_id' => $this->id, 'type' => $type, 'transition_id' => $lastActivity->transition_id, 'comment' => $this->comment ?: 'WORKFLOW_BACKED', 'pre' => $last, 'part_id' => $this->partId ?: 0, 'user_id' => $user_from, 'options' => $preLastActivity->options];

            $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_ACTIVITY, $data);

            $t = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_TRANSITION, $lastActivity->transition_id);
            $preActivity = $this->dataRepo->getEntity(DataRepositoryInterface::BPMS_ACTIVITY, $lastActivity->pre);
            $data = ['activity_id' =>  $activityId, 'state' => $t->from_state, 'user_current' => $preActivity->user_id, 'user_from' => $user_from];
            $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], $data);
            return;
        }

        $activityId = $this->dataRepo->createEntity(DataRepositoryInterface::BPMS_ACTIVITY, $data);
        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, ['id' => $this->id], ['activity_id' => $activityId, 'cid' => $this->hasParentCase() ? $this->getParentCase() : $this->id]);
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
                    'comment' => $activity->comment,
                    'duration' => Carbon::parse($activity->finished_at)->diffInSeconds(Carbon::parse($activity->created_at)),
                    'status' => $activity->type == ProcessLogicInterface::WORKFLOW_CHANGE_USER ? 'تغییر کاربر' : $activity->finished_at ? 'تکمیل شده' : 'در حال انجام'
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
                $data['vars'] = isset($res) ? $res : null;
            }
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

            if ($this->isPositionBased($s->entity->meta_type))
                $data = ['meta_value' => $this->givePositionOfUser($userId)];
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
            $data = ['gate_wid' => null, 'from_state' => $transition['from'], 'to_state' => $transition['to'], 'ws_pro_id' => $process];

            $tid = $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_TRANSITION, $predicate, $data, true);
            $to_keep[] = $tid;
        }

        if ($to_keep) {
            $this->dataRepo->deleteNotIn(DataRepositoryInterface::BPMS_TRANSITION, ['ws_pro_id' => $process], $to_keep);
            $to_keep = [];
        }

        foreach ($states as $state) {
            $data = ['loop' => isset($state['loop']) ? $state['loop'] : 'NaN', 'wid' => $state['id'], 'type' => $state['type'], 'position_state' => $state['position'], 'text' => $state['name'], 'next_wid' => $state['next']['id'], 'next_type' => $state['next']['type'], 'ws_pro_id' => $process];
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
                    $foundTransition = $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_TRANSITION, ['from_state' => $in, 'to_state' => $out], $data);
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
                return ['status' => 'subprocess', 'type' => static::NEXT_NEXT];

            case ProcessLogicInterface::WORKFLOW_NO_PATH:
                return ['status' => 'error', 'state' => $this->backupState, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_NO_PATH'];

            case ProcessLogicInterface::WORKFLOW_EXCEPTION:
                return ['status' => 'error', 'state' => $this->backupState, 'type' => static::NEXT_ERROR, 'message' => $this->error];

            case ProcessLogicInterface::WORKFLOW_ENDED:
                $this->checkForParentCase();
                $this->addActivityLog();
                return ['status' => 'end', 'state' => $this->state, 'type' => static::NEXT_NEXT];

            case ProcessLogicInterface::WORKFLOW_NO_META:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_NO_META'];

            case ProcessLogicInterface::WORKFLOW_PART_ISNULL:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_PART_ISNULL'];

            case ProcessLogicInterface::WORKFLOW_IS_IN_SUBPROCESS:
                return ['status' => 'error', 'state' => $this->state, 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_IS_IN_SUBPROCESS'];

            case ProcessLogicInterface::WORKFLOW_EVALUATION_ERROR:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => $this->error];

            case ProcessLogicInterface::WORKFLOW_LONELY_PART:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_LONELY_PART'];

            case ProcessLogicInterface::WORKFLOW_STATE_NOTFOUND:
                return ['status' => 'error', 'type' => static::NEXT_ERROR, 'message' => 'WORKFLOW_STATE_NOTFOUND'];

            default:
        }

        $this->addActivityLog();
        return $this->getStatus();
    }

    public function checkForParentCase()
    {
        if ($parent_case = $this->hasParentCase()) {
            $parent_case = $this->getParentCase();
            $this->updateEntity(static::BPMS_CASE, ['id' => $parent_case], ['status' => 'working']);

            $logic = app()->make(ProcessLogic::class);
            $input = ['metaReq' => $this->metaReq, 'nextPreview' => false];
            $logic->setCaseById($parent_case);
            return $logic->goNext($input);
        }
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
        $data['position_from'] = $this->givePositionOfUser($data['user_from']);

        if (is_array($userId)) {
            $data['user_current'] = ProcessLogicInterface::USER_COMMAN;
            $data['status'] = 'unassigned';
            $data['system_options']['users'] = $userId;
        } else {
            $data['user_current'] = $userId;
            $data['position_current'] = $this->givePositionOfUser($userId);
        }

        if ($this->status == "parted") {
            $this->savePart($data);
            $data['status'] = "parted";
        } else if ($this->status == 'subprocess') {
            $data['status'] = "subprocess";
            $data['state'] = $this->state;
        } else {
            $data['state'] = $this->state;
        }

        $data['seen'] = false;
        $predicate = ['id' => $this->id];

        $this->dataRepo->updateEntity(DataRepositoryInterface::BPMS_CASE, $predicate, $data);
        $this->case = $this->case->fresh(); //Updated case is needed in After next function
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

        if ($this->status == 'end' && $this->test) { //workflow to restart
            $this->state = $this->getFirstStateWID();
            $this->status = 'working';
            $this->checkNext($this->state);
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_RESTARTED);
        }

        if ($this->status == 'end') { //case to restart
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_ENDED_BEFORE, false);
        }

        if ($this->status == 'subprocess') { //case to restart
            return $this->saveChanges(ProcessLogicInterface::WORKFLOW_IS_IN_SUBPROCESS, false);
        }

        if ($this->status == "created" && !$this->state) { //case to start
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

        $isPartedBefore = $this->status == 'parted';
        $next_type =  $this->currentState->next_type;

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
                            if ($isPartedBefore) {
                                $this->deleteCurrentPart();
                            }
                        }
                    } //End of check for conditional gates
                    else { //Parallel gate
                        $this->addWorkflowPart($toGoTransition->id, $toGoTransition->gate_wid, $toGoTransition->to_state);
                        $isParellel = true;
                        $this->status = 'parted';
                        if ($isPartedBefore) {
                            $this->deleteCurrentPart();
                        }
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

                    $done = true;

                    foreach ($trans as $t) {
                        $friendPart = $this->findWorkflowPart(['state' => $t->from_state]);
                        if ($friendPart == null && ($this->state != $t->from_state)) {
                            $done = false;
                        }
                    }

                    if ($done == false) { //WORKFLOW_WAIT_FOR_ANOTHER
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
    }

    public function changeUser($stateWID, $userId)
    {
        //Move case
        //Normal
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
        //goNext
        return $cases;
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
                    $this->beforeNext($this->currentState);
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
