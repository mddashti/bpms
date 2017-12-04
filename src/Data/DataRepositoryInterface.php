<?php namespace Niyam\Bpms\Data;

interface DataRepositoryInterface
{
    //GIS Config
    // const
    //  TABLE_PROCESS_STATE = 'bpms_task',
    //  TABLE_PROCESS_TRANSITION = 'bpms_transitions',
    //  TABLE_PROCESS_META = 'bpms_metas',
    //  TABLE_PROCESS_GATE = 'bpms_gateway',
    //  TABLE_PROCESS_CASE = 'bpms_cases',
    //  TABLE_PROCESS_PART = 'bpms_cases_parts',
    //  TABLE_PROCESS_FAKEPART = 'bpms_parts',
    //  TABLE_PROCESS_ACTIVITY = 'bpms_cases_activities',
    //  TABLE_PROCESS = 'plugins';

    const
    TABLE_PROCESS_STATE = 'Niyam\Bpms\Model\BpmsState',
    TABLE_PROCESS_TRANSITION = 'Niyam\Bpms\Model\BpmsTransition',
    TABLE_PROCESS_META = 'Niyam\Bpms\Model\BpmsMeta',
    TABLE_PROCESS_GATE = 'Niyam\Bpms\Model\BpmsGate',
    TABLE_PROCESS_CASE = 'Niyam\Bpms\Model\BpmsCase',
    TABLE_PROCESS_PART = 'Niyam\Bpms\Model\BpmsCasePart',
    TABLE_PROCESS_FAKEPART = 'Niyam\Bpms\Model\BpmsPart',
    TABLE_PROCESS_TYPE = 'Niyam\Bpms\Model\BpmsType',
    TABLE_PROCESS_ACTIVITY = 'Niyam\Bpms\Model\BpmsActivity',
    TABLE_PROCESS = 'Niyam\Bpms\Model\BpmsWorkflow',
    TABLE_PROCESS_FORM = 'Niyam\Bpms\Model\BpmsForm',
    TABLE_PROCESS_TRIGGER = 'Niyam\Bpms\Model\BpmsTrigger';    

    //Template
    // const
    // TABLE_PROCESS_STATE = 'workflow_states',
    // TABLE_PROCESS_TRANSITION = 'workflow_transitions',
    // TABLE_PROCESS_META = 'workflow_metas',
    // TABLE_PROCESS_GATE = 'workflow_gates',
    // TABLE_PROCESS_CASE = 'workflow_cases',
    // TABLE_PROCESS_PART = 'workflow_case_parts',
    // TABLE_PROCESS_FAKEPART = 'workflow_parts',
    // TABLE_PROCESS_TYPE = 'workflow_types',
    // TABLE_PROCESS_ACTIVITY = 'workflow_activities',
    // TABLE_PROCESS = 'workflows';
    // TABLE_PROCESS_STATE_FORM = 'process_state_forms';


    public function getEntity($entity, $id);

    public function findEntity($entity, $predicate);

    public function findEntities($entity, $predicate, $columns = null);

    public function findEntityByOrder($entity, $predicate, $field, $order);

    public function findEntitiesByOrder($entity, $predicate, $field, $order);

    public function findCasesByMixed($predicate, $columns, $field, $order, $skip, $limit);


    public function findEntityByRandom($entity, $predicate);


    public function countEntity($entity, $predicate);

    
    public function createEntity($entity, $data);

   
    public function updateEntity($entity, $predicate, $data, $create = false);
    
   
    public function deleteEntity($entity, $predicate);

    public function deleteNotIn($entity, $predicate,$toKeep);


    
}
