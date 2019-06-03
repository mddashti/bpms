<?php

namespace Niyam\Bpms\Http\Controllers;

use Illuminate\Http\Request;
use Niyam\Bpms\ProcessLogic;
use Niyam\FusionPBX\FusionPBX;
use Niyam\Bpms\Model\BpmsWorkflow;
use Niyam\Bpms\Model\BpmsForm;
use Niyam\Bpms\Model\BpmsState;
use Niyam\Bpms\Service\WorkflowService;
use Niyam\Bpms\Service\GateService;
use Niyam\Bpms\Service\StateService;
use Illuminate\Support\Facades\Cache;


class WorkflowController
{
    private $logic;
    protected $ldap;
    protected $wservice;
    protected $gservice;
    protected $sservice;

    public function __construct(ProcessLogic $logic, WorkflowService $wservice, GateService $gservice, StateService $sservice)
    {
        $this->logic = $logic;
        $this->wservice = $wservice;
        $this->gservice = $gservice;
        $this->sservice = $sservice;
        //$this->ldap = $ldap;
    }
    public function index(Request $request)
    {
        return view('workflow.index');
    }


    public function getWorkflows(Request $request)
    {
        return Cache::remember('active-workflows', 5, function () {
            return $this->wservice->getWorkflows();
        });
    }

    public function test(FusionPBX $pbx, Request $request)
    {
        return $request->all();
        //$this->logic->setWorkflowbyId(3);
        //$this->logic->setCasebyId(9);
        //$this->logic->setStateMeta('Task_0f4qvbh', ['form' => 1, 'form_condition' => 'ddd==1']);
        //return $this->logic->getStateMeta('Task_0f4qvbh', ['form_id' => 2], ['form_id','condition']);
        //return $this->logic->getStateForms('Task_0f4qvbh', true,['id','title','description']);
        //return $this->logic->getStateFormCondition(['state_id' => 1, 'form_id' => 1, 'columns' => ['id','condition']]);
        //return $this->logic->deleteStateForm(['state_id' => 1, 'form_id' => 1]);
        //return $this->logic->getWorkflowEntities(DataRepositoryInterface::BPMS_VARIABLE,['ws_pro_id' => 1], null, ['fetchMethod']);
        //return $this->repo->createEntity(DataRepositoryInterface::BPMS_FETCH, ['dbconnection_id' => 1, 'query' => 'select']);
        //return $res->options['z'];
        //return $this->logic->getFormTriggers(['id'=>1]);
        //return $this->logic->getFormElements(['id'=>1]);
        //return $this->logic->getStateCurrentForm('Task_0f4qvbh');
        //return BpmsWorkflow::with(['states','transitions','gates'])->get();
        //return FusionPBX::getActiveCalls();

        //return $res = $pbx->changeLineEnable(60,0);
        //return $res == FusionPBX::CALLING ? $pbx->getCallerNumber() : $res;
        //return $res =  $pbx->getHistoryOfCalls('09138562838', '', 'missed','outbound');


        // return $res = json_decode($res);

        return $users = $this->ldap->search()->users()->get();

        // return $pbx->changeLineEnable(60,0);
    }

    public function getWorkflowsParsed(Request $request)
    {
        $user = $request->user()->id;
        return BpmsWorkflow::where(['user_id' => $user, 'is_parsed' => true])->get();
    }

    public function getWorkflowdata(BpmsWorkflow $workflow)
    {
        return $workflow->wxml;
    }

    public function getWorkflowUser(BpmsWorkflow $workflow, Request $request)
    {
        $state = $request->state;
        $this->sservice->setWorkflow($workflow);
        return $this->sservice->getStateMeta($state);
    }

    public function getWorkflowCondition(BpmsWorkflow $workflow, Request $request, $gate)
    {
        return $this->gservice->getGateConditions($gate, $workflow->id);
    }

    public function postWorkflowCondition(BpmsWorkflow $workflow, Request $request, $gate)
    {
        $this->gservice->setWorkflow($workflow);

        $data = $request->all();

        $this->gservice->setGateConditions($gate, $data);
    }

    public function postWorkflowUser(BpmsWorkflow $workflow, Request $request)
    {
        if (!$workflow->is_parsed) {
            return ['type' => 'error', 'message' => 'ِFirst parse workflow'];
        }

        $user = $request->user();
        $metaValue = $request->value ?: null;
        $metaType = $request->type;
        $metaName = $request->name;
        $metaBack = $request->back;
        $script = $request->script;


        if ($metaType != 7 && $metaType != 9 && $metaType != 12) {
            $metaValuePure = array_map('intval', explode(',', $request->users));
        } else
            $metaValuePure = explode(',', $request->users);
        $forms = array_map('intval', explode(',', $request->forms));

        foreach ($forms as $form)
            if (!BpmsForm::find($form))
                BpmsForm::create(['ws_pro_id' => $workflow->id, 'id' => $form, 'title' => 'Form' . str_random(8), 'content_html' => '<div>Form' . $form . '</div>']);

        $testForm = ['form_id' => 5];
        $state = $request->state;

        //$data = ['type' => $metaType, 'value' => $metaValue, 'users' => $metaValuePure, 'forms' => $forms, 'script' => $script];
        $data = ['type' => $metaType, 'value' => $metaValue, 'users' => $metaValuePure, 'form' => $testForm, 'script' => $script];

        //$data = ['back' => $metaBack];
        //$data = ['form' => 1];

        $this->sservice->setWorkflow($workflow);
        $this->sservice->setStateMeta($state, $data);

        return ['type' => 'success', 'message' => 'Meta has been added.'];
    }

    public function postWorkflowdata(BpmsWorkflow $workflow, Request $request)
    {
        $workflow->wxml = $request->xml;
        $workflow->wsvg = $request->svg;

        $workflow->save();

        return ['message' => 'Workflow edited'];
    }

    public function getSubprocessMeta(BpmsWorkflow $workflow, $state)
    {
        $this->logic->setWorkflow($workflow);

        return $this->logic->getSubprocessMetaWorkflow($workflow, $state);
    }

    public function getNext(BpmsWorkflow $workflow, Request $request, $form)
    {
        \Debugbar::startMeasure('render', 'WORKFLOW_NEXT');

        if (!$workflow->is_parsed)
            return ['state' => 'error', 'message' => 'Please parse it first!'];

        $this->logic->setWorkflow($workflow);

        $form = $form != 0 ? ['id' => $form, 'content' => 'jsonjsonjson'] : null;
        $input = ['metaReq' => 1, 'nextPreview' => false, 'vars' => array('A' => 1, 'B' => 2, 'C' => 3), 'form' => $form];
        $res = $this->logic->goNext($input);
        \Debugbar::stopMeasure('render');

        return $res;
    }

    public function getNextStep(BpmsWorkflow $workflow, $state)
    {
        $this->logic->setWorkflow($workflow);
        return $this->logic->getNextStep($state, array('A' => 1, 'B' => 2, 'C' => 3));
    }

    public function postWorkflowparse(BpmsWorkflow $workflow, Request $request)
    {
        $transitions = $request->transitions;
        $states = $request->tasks;
        $gateways = $request->gateways;

        $data = ['transitions' => $transitions, 'states' => $states, 'gateways' => $gateways, 'ws_pro_id' => $workflow->id];
        $this->logic->saveParsedData($data);

        $workflow->is_parsed = true;
        $workflow->save();
        return ['message' => 'Workflow parsed'];
    }

    public function postSubprocessMeta(Request $request, BpmsWorkflow $workflow)
    {
        $case = $request->case;
        $startId = $request->start; //stateId of stateToSet
        $state = $request->state;
        $stateToSet = BpmsState::findOrFail($startId);

        // 4 is  META_TYPE_SUBPROCESS
        $data = ['start' => $stateToSet->wid, 'type' => 4, 'value' => $stateToSet->ws_pro_id];
        $this->logic->setWorkflow($workflow);
        $this->logic->setStateMeta($state, $data);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required'
        ]);

        $userId = $request->user()->id;
        $isNewType = $request->input('new');
        $newType = $request->input('newtype');
        $type = $request->input('type');
        $title = $request->input('name');
        $wid = str_random(8);
        //1 for workspace

        $this->logic->createWorkflow([
            'name' => $title,
            'wid' => $wid,
            'user_id' => $userId,
            'ws_id' => 1,
            'type' => $type,
            'newType' => $newType,
            'wxml' => null,
            'wsvg' => null,
            'opts' => ['A' => 1],
            'description' => null
        ]);

        return ['message' => 'Workflow created'];
    }

    public function show(BpmsWorkflow $workflow)
    {
        return view('workflow.show')->with('wid', $workflow->wid);
    }

    public function getStatus(BpmsWorkflow $workflow)
    {
        $this->logic->setWorkflow($workflow);
        return $this->logic->getStatus();
    }

    public function edit($wid)
    {
        return view('workflow.edit')->with('wid', $wid);
    }

    public function update(Request $request, BpmsWorkflow $workflow)
    {
        //
    }

    public function destroy(BpmsWorkflow $workflow)
    {
        $workflow->delete();
    }
}
