<?php

namespace Niyam\Bpms\Service;

use Niyam\Bpms\Data\BaseService;
use Niyam\Bpms\Model\BpmsForm;
use Niyam\Bpms\Model\BpmsMeta;
use Niyam\Bpms\Model\BpmsStateConfig;
use Niyam\Bpms\Model\BpmsVariable;
use Niyam\Bpms\Model\BpmsState;
use Niyam\Bpms\ProcessResponse;

class FormService extends BaseService
{
    protected $caseService;

    public function __construct(CaseService $caseService)
    {
        $this->caseService = $caseService;
    }
    #region Forms
    public function getForms($predicate = null, $columns = '*')
    {
        if (!$predicate)
            return BpmsForm::where('ws_pro_id', $this->wid)->get($columns);

        if ($this->wid && !isset($predicate['ws_pro_id'])) {
            $predicate['ws_pro_id'] = $this->wid;
        }
        return $this->findEntities(BaseService::BPMS_FORM, $predicate, $columns);
    }

    public function getStateForms($stateWID = null, $assigned = true, $columns = '*', $state = null, $with = null)
    {
        if (!$state)
            $state = $this->findEntity(static::BPMS_STATE, ['wid' => $stateWID]);
        $forms = isset($state->options['forms']) ? $state->options['forms'] : null;

        $res = [];
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

    public function updateFormOfState($predicate, $data)
    {
        $predicate['trigger_id'] = null;
        $this->updateEntity(static::BPMS_STATE_CONFIG, $predicate, $data, true);
    }

    public function getFirstFormOfState($stateWID = null, $state = null) //ProcessLogic
    {
        $state = $this->findEntity(static::BPMS_STATE, ['wid' => $stateWID]);

        if (!$this->isFormBased($state))
            return null;

        if (!$state)
            return new ProcessResponse(false, null, 'WORKFLOW_NO_STATE', 1);

        if ($state->type == "bpmn:ScriptTask") {
            return new ProcessResponse(true, null, 'WORKFLOW_SCRIPT_NO_FORM', 2);
        }

        $forms = $this->getStateForms(null, true, '*', $state, 'stateConfigs')['forms'];

        if (!$forms) {
            return new ProcessResponse(false, null, 'WORKFLOW_NO_FORM', 3);
        }


        $vars = $this->caseService->getCaseOption('vars', null, $this->cid);

        foreach ($forms as $form) {
            $config = $form->stateConfigs->where('state_id', $state->id)->first();
            if ($this->checkCondition($config ? $config->condition : null, $vars)) {
                $candidateForm = $form;
                break;
            }
        }

        if (!isset($candidateForm)) {
            return new ProcessResponse(false, null, 'WORKFLOW_NO_MATCH_FORM', 4);
        }

        $meta = BpmsMeta::where(['element_name' => $stateWID, 'case_id' => $this->cid])->first();
        if (!$meta || !isset($meta->options['forms']))
            return new ProcessResponse(true, $candidateForm, 5);
        else {
            $formMeta = collect($meta->options['forms'])->where('id', $candidateForm->id)->first();
            $candidateForm->formMeta = $formMeta;
            return new ProcessResponse(true, $candidateForm, 5);
        }
    }

    public function getStateFormCondition($inputArray)
    {
        $state_id = $inputArray['state_id'];
        $form_id = $inputArray['form_id'];
        $columns = $inputArray['columns'];
        $id = isset($inputArray['id']) ? $inputArray['id'] : null;

        if ($id)
            return BpmsStateConfig::firstOrFail($id);

        return BpmsStateConfig::where(['state_id' => $state_id, 'form_id' => $form_id, 'trigger_id' => null])->get($columns)->first();
    }

    public function getFormElements($predicate, $columns = '*')
    {
        $form = BpmsForm::where($predicate)->with('variables')->first();

        $elements = $form ? $form->variables : null;

        foreach ($elements as $element) {
            $vars[$element->name] = 0;
            $data['element_name'] = $element->pivot->element_name;
            $data['element_type'] = $element->pivot->element_type;
            $data['element_value'] = $element->fetch()->exists() ? $this->executeSelectQuery($element->fetch->query) : null;
            $data['default_value'] = isset($element->options['default_value']) ? $element->options['default_value'] : null;
            $res[] = $data;
        }

        return ['elements' => isset($res) ? $res : null, 'form' => $form, 'vars' => isset($vars) ? $vars : null];
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
    #endregion

    #region Variable 
    public function getVariables($predicate = null, $columns = '*')
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

        $res = null;
        foreach ($vars as $var) {
            $res[$var->name] = isset($var->options['default_value']) ? $var->options['default_value'] : 0;
        }
        return $res;
    }
    #endregion
}
