<?php namespace Niyam\Bpms\Service;

use Niyam\Bpms\Data\BaseService;

class WorkflowService extends BaseService
{
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
            $typeFound = $this->findEntity(static::BPMS_TYPE, ['name' => $newType, 'user_id' => $userId]);
            if ($typeFound) {
                $typeId = $typeFound->id;
            } else {
                $typeId = $this->createEntity(static::BPMS_TYPE, ['name' => $newType, 'user_id' => $userId]);
            }
        }

        // if (static::CONFIG_FILTER_CREATE_UNIQUE_PROCESS) {
        //     if ($this->dataRepo->findEntity(static::BPMS_WORKFLOW, ['name' => $title]))
        //         return false;
        // }
        return $this->createEntity(static::BPMS_WORKFLOW, [
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
        //check for current cases
        return $this->updateEntity(static::BPMS_WORKFLOW, $predicate, $data, $create);
    }

    public function deleteWorkflow($predicate)
    {
        //check for current cases
        return $this->deleteEntity(static::BPMS_WORKFLOW, $predicate);
    }

    public function getWorkflowTypes($predicate)
    {
        return $this->findEntities(static::BPMS_TYPE, $predicate);
    }

    public function getWorkflows($predicate = null, $columns = '*')
    {
        return $this->findEntities(static::BPMS_WORKFLOW, $predicate, $columns);
    }
}

