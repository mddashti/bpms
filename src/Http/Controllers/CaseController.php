<?php

namespace Niyam\Bpms\Http\Controllers;

use Illuminate\Http\Request;
use Niyam\Bpms\Model\BpmsCase;
use Niyam\Bpms\Model\BpmsWorkflow;
use Niyam\Bpms\ProcessLogic;
use App\Repository\ProcessCase\CaseRepository;
use Illuminate\Support\Facades\DB;


class CaseController

{
    private $logic;
    private $caseRepo;

    public function __construct(ProcessLogic $logic, CaseRepository $caseRepo, Request $request)
    {
        $this->logic = $logic;
        $this->logic->setCaseById($request->route('case'));
        $this->caseRepo = $caseRepo;
    }

    public function index()
    {
        return view('case.index');
    }

    public function checkForTimer()
    {
        return $this->logic->checkForTimer();
    }

    public function next(Request $request)
    {
        $input = ['metaReq' => 1, 'preview' => $request->preview, 'vars' => array('A' => 1, 'B' => 2, 'C' => 3), 'form' => 1];
        return $this->logic->goNext($input);
    }

    public function allCases(Request $request)
    {
        return BpmsCase::with('workflow')->get();
        $userId = $request->user()->id;
        return $this->caseRepo->getUserCases($userId);
    }

    public function getSubprocessMeta(BpmsCase $case, $state)
    {
        return $this->logic->getSubprocessMetaCase($case, $state);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required'
        ]);

        $title = $request->input('title');
        $workflowId = $request->input('workflow');
        $userId = $request->user()->id;

        return $this->logic->createCase(['ws_pro_id' => $workflowId, 'user' => $userId, 'title' => $title, 'vars' => ['A' => 1, 'B' => 2]]);
    }

    public function getCaseUser(BpmsCase $case, Request $request)
    {
        $state = $request->state;
        return $this->logic->getStateMeta($state);
    }

    public function getCaseCondition(BpmsCase $case, Request $request, $gate)
    {
        $workflow = BpmsWorkflow::findorFail($case->ws_pro_id);

        $res = DB::table('bpms_transitions')
            ->join('bpms_metas', 'bpms_transitions.id', '=', 'bpms_metas.element_id')->where(['gate_wid' => $gate, 'case_id' => $case->id, 'element_type' => 2])->get(['order_transition', 'bpms_metas.meta_value', 'to_state']);

        return $res;
    }

    public function postCaseCondition(BpmsCase $case, Request $request, $gate)
    {
        $workflow = BpmsWorkflow::findorFail($case->ws_pro_id);

        foreach ($request->data as $condition) {
            foreach ($condition['from'] as $from) {
                $to = $condition['to'];
                $meta = $condition['condition'];
                $order = $condition['order'];
                $workflow_id = $workflow->id;

                $data = ['order' => $order, 'from' => $from, 'to' => $to, 'meta' => $meta, 'gate' => $gate];
                $this->logic->setTransitionMeta($data);
            }
        }
    }

    public function postCaseUser(BpmsCase $case, Request $request)
    {
        $user = $request->user();
        $metaValue = $request->value;
        $metaType = $request->type;
        $metaName = $request->name;
        $metaBack = $request->back;
        $state = $request->state;

        $data = ['type' => $metaType, 'value' => $metaValue, 'name' => $metaName, 'back' => $metaBack, 'form' => 1];

        $this->logic->setStateMeta($state, $data);

        return ['type' => 'success', 'message' => 'Meta has been added.'];
    }


    public function testMeDude(BpmsCase $case, Request $request)
    {
        return $users = App\User::paginate(15);
        return $this->logic->getStatus(['user' => 1, 'state' => 'Task_1wguegx']);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(BpmsCase $case)
    {
        $workflow = BpmsWorkflow::find($case->ws_pro_id);
        return view('case.show')->with(['wid' => $workflow->wid, 'case' => $case->id]);
    }

    public function getStatus(BpmsCase $case, Request $request)
    {
        $userId = $request->user;
        return $this->logic->getStatus(['user' => $userId, 'state' => $case->state]);
        //return $this->logic->getStatus();
    }

    public function pic(BpmsCase $case)
    {
        return $this->logic->takePic();
    }

    public function edit(BpmsCase $case)
    {
    }

    public function update(Request $request, $id)
    {
        //
    }

    public function goBack(Request $request, BpmsCase $case)
    {
        $case->state = $request->id;
        $case->save();
    }

    public function goBackParted(Request $request, BpmsCase $case)
    {
        $part = $case->parts()->where('state', $request->src)->first();
        $part->state = $request->dest;
        $part->save();
    }

    public function getParts(BpmsCase $case)
    {
        $case->parts()->get(['id', 'state']);
    }

    public function destroy(BpmsCase $case)
    {
        $case->delete();
    }
}
