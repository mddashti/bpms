<?php namespace Niyam\Bpms\Data;

interface DataRepositoryInterface
{
    const
        BPMS_STATE = 'Niyam\Bpms\Model\BpmsState',
        BPMS_TRANSITION = 'Niyam\Bpms\Model\BpmsTransition',
        BPMS_META = 'Niyam\Bpms\Model\BpmsMeta',
        BPMS_GATE = 'Niyam\Bpms\Model\BpmsGate',
        BPMS_CASE = 'Niyam\Bpms\Model\BpmsCase',
        BPMS_ELEMENT_TRIGGER = 'Niyam\Bpms\Model\BpmsElementTrigger',
        BPMS_ELEMENT = 'Niyam\Bpms\Model\BpmsElement',
        BPMS_FETCH = 'Niyam\Bpms\Model\BpmsFetch',
        BPMS_STATE_CONFIG = 'Niyam\Bpms\Model\BpmsStateConfig',
        BPMS_VARIABLE = 'Niyam\Bpms\Model\BpmsVariable',
        BPMS_TYPE = 'Niyam\Bpms\Model\BpmsType',
        BPMS_ACTIVITY = 'Niyam\Bpms\Model\BpmsActivity',
        BPMS_WORKFLOW = 'Niyam\Bpms\Model\BpmsWorkflow',
        BPMS_FORM = 'Niyam\Bpms\Model\BpmsForm',
        BPMS_TRIGGER = 'Niyam\Bpms\Model\BpmsTrigger',
        BPMS_VARIABLE_TYPE = 'Niyam\Bpms\Model\BpmsVariableType';

    public function getEntity($entity, $id);

    public function findEntity($entity, $predicate, $columns = '*', $with = null);

    public function findEntities($entity, $predicate, $columns = '*', $with = null);

    public function findEntityByOrder($entity, $predicate, $field, $order);

    public function findEntitiesByOrder($entity, $predicate, $field, $order);

    public function findCasesByMixed($predicate, $columns, $field, $order, $skip, $limit);

    public function findEntityByRandom($entity, $predicate);

    public function countEntity($entity, $predicate);

    public function createEntity($entity, $data);

    public function updateEntity($entity, $predicate, $data, $create = false);

    public function deleteEntity($entity, $predicate);

    public function deleteNotIn($entity, $predicate, $toKeep);
}
