<?php namespace Niyam\Bpms;

interface ProcessLogicInterface
{
    const
        POSITION_START = 0,
        POSITION_MIDDLE = 1,
        POSITION_END = 2;

    const
        NEXT_BADACCESS = 1,
        NEXT_FORM = 2,
        NEXT_PREVIEW = 3,
        NEXT_NEXT = 4,
        NEXT_ERROR = 5;


    const
        ELEMENT_TYPE_STATE = 1,
        ELEMENT_TYPE_TRANSITION = 2,
        ELEMENT_TYPE_SUBPROCESS = 3;


    const
        TRIGGER_TYPE_FORM_BEFORE_LOAD = 1,
        TRIGGER_TYPE_FORM_AFTER_CONFIRM = 2,
        TRIGGER_TYPE_STATE_BEFORE_ASSIGNMENT = 3,
        TRIGGER_TYPE_STATE_BEFORE_EXIT = 4,
        TRIGGER_TYPE_STATE_AFTER_EXIT = 5,
        TRIGGER_TYPE_STATE_TIMEOUT = 6;

    const
        META_TYPE_USER = 1,
        META_TYPE_SUBUSER = 2,
        META_TYPE_SUBPOSITION = 3,
        META_TYPE_SUBPROCESS = 4,//4,ws_pro_id, 
        META_TYPE_CYCLIC = 5,//5,null,users=[1,2] 
        META_TYPE_COMMON = 6,//6,null,users=[1,2]
        META_TYPE_VARIABLE = 7,//7,null,users=[z] where z --> 1
        META_TYPE_MANUAL = 8,
        META_TYPE_COMMON_VARIABLE = 9,//9,null,users=[z] where z --> [1,2] 
        META_TYPE_ARRAY_VARIABLE = 10,
        META_TYPE_COMMON_CUSTOM = 11,
        META_TYPE_SCRIPT_URL = 12,
        META_TYPE_SCRIPT_CODE = 13;

    const
        USER_COMMAN = -1,
        USER_NO_MATCH = -2,
        USER_EXCEPTION = -3,
        USER_NOT_EXIST = -4,
        USER_SCRIPT_URL = -5;

    const
        SYSTEM_CASE = 0;

    const
        WORKFLOW_CREATED = 0,
        WORKFLOW_STARTED = 1,
        WORKFLOW_PART_CREATED = 2,
    //WORKFLOW_PARTED = 3,
        WORKFLOW_PAUSED = 4,
        WORKFLOW_CANCELLED = 5,
        WORKFLOW_ENDED = 6,
        WORKFLOW_NO_PATH = 7,
        WORKFLOW_WORKING = 8,
        WORKFLOW_EXCEPTION = 9,
        WORKFLOW_NO_META = 10,
        WORKFLOW_BACK_TO_WORKING = 11,
        WORKFLOW_PART_ENDED = 12,
        WORKFLOW_PART_WORKING = 13,
        WORKFLOW_PART_ISNULL = 14,
        WORKFLOW_PART_NO_PATH = 15,
        WORKFLOW_WAIT_FOR_ANOTHER = 16,
        WORKFLOW_RESTARTED = 17,
        WORKFLOW_EVALUATION_ERROR = 18,
        WORKFLOW_NO_EVENT = 19,
        WORKFLOW_BACKED = 20,
        WORKFLOW_ENDED_BEFORE = 21,
        WORKFLOW_LONELY_PART = 22,
        WORKFLOW_MAYBE_LOCKED = 23,
        WORKFLOW_STATE_NOTFOUND = 24,
        WORKFLOW_NO_FORM = 25,
        WORKFLOW_NO_MATCH_FORM = 26,
        WORKFLOW_PREVIEW = 27;



    public function setCase($case, $baseTable = false);

    public function setCaseById($caseId, $baseTable = false);

    public function setWorkflow($workflow);

    public function setWorkflowById($id);
    
    //vars, state_vars
    //$value for vars must be array such as ['A'=>1, 'B'=>2] and will be merged with current case vars!
    //$value for state_vars must be array of 'id' and 'vars', id refer to form_id and vars are local variables of form!

    public function setCaseOption($option, $value, $caseId = null);

    public function getCaseOption($option, $caseId = null);

    public function getUserStarts($userId);

    public function saveParsedData($data);

    // public function getNextStep($state = null, $vars = null);

    public function isEligible($metaReq, $stateReq = null, $typeReq = 1);

    public function getAvailableTransitions($fromState = null);

    public function getPossibleStates($fromState, $vars = null);
   
    //InoutArray 'ws_pro_id' => workflow_id, 'user' => user_id_of_case_creator, 'title' => title_of_case
    //Add default global vars => ['A'=>1,'B'=>2]
    //Add 'start'=> state_wid to start case in your desired state, start must be in POSITION_START!
    //Add 'copy' => true to work with snapshot of workflow, default is false!

    public function createCase($inputArray);

    public function getCases($predicate, $filter = null);

    //public function createProcess($title, $wid, $userId, $ws_id = null, $type = null, $newType = null, $wbody = null, $wsvg = null, $opts = null);

    public function createWorkflow($inputArray);

    public function updateWorkflow($predicate, $data);

    public function deleteWorkflow($predicate);

    public function getWorkflows($predicate = null, $columns = null);

    public function getWorkflowTypes($predicate);

    public function getSubprocessMetaWorkflow($workflow, $state);

    // public function getSubprocessMetaCase($case, $state);

    public function setSubProcessMeta($stateWID, $caseId);

    //public function setSubprocessMeta($inputArray);

    public function getLastPartState();

    public function loadSubProcess($caseId);

    public function findWorkflowPart($predicate);

    public function countWorkflowPart();

    public function deleteCurrentPart();

    public function getCurrentState($state = null);

    public function isEndedWorkflow();

    public function takePic();

    public function getSubProcessMeta($stateWID);


    //$data is associative array
    //'type' => META_TYPE_*,
    //'users' => array_of_user_id, 
    //'forms' => array_of_form_id,
    //'form' => form_id will be added to forms option

    //'form_condition' => 
    //'trigger_condition' => 
    //Add 'back' => true to enable back on state!
    //Add 'name' => name_of_user, this property is used when you set type to META_TYPE_USER, currently is used for API access

    public function setStateMeta($stateWID, $data);

    public function setTransitionMeta($data);


    //return data
    //back, forms, users, type (meta_type) ,value (meta_value), 
    //name (used when user of library is system such as API access) 
    //predicate ['form'=> 2]

    public function getStateMeta($stateWID, $predicate = null, $columns = null);

    // public function getTransitionMetaUI();

    public function updateStateOptions($stateWID, $caseId);

    public function getMetaOptions($stateWID, $option, $data);

    public function AddWorkflowPart($tid, $gid, $from);

    public function addActivityLog();

    public function getStatus();

    public function getPartsStateWID();

    public function getBaseState();

    public function getGateConditions($gateWID, $workflow_id = null);

    public function setGateConditions($gateWID, $conditions, $workflow_id = null);

    public function setPart($partId = null);

    public function saveChanges($type, $message = null);

    public function saveWorkflow();

    public function saveCase();

    public function savePart();

    public function goNext($inputArray);

    public function goBack($inputArray);

    public function isStateDone($form);

    //Get state forms ordered by view_order
    public function getStateForms($stateWID, $assigned = true, $columns = null);

    public function getStateFormCondition($InputArray);
    public function deleteStateForm($inputArray);
    public function getVariables($predicate = null, $columns = null);
    public function getVariablesWithValue($predicate = null);
    //public function getFormTriggers($predicate, $columns = null);
    public function getFormElements($predicate, $columns = null);
    // public function addForm($data);
    // public function updateForm($predicate, $data);
    // public function deleteForm($predicate);
    public function deleteWorkflowEntity($entity, $predicate, $check = true);
    public function getWorkflowEntities($entity, $predicate, $columns = null, $with = null);
}




