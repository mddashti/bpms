<?php

namespace Niyam\Bpms\Data;

use Niyam\Bpms\Model\BpmsCase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class BaseService implements DataRepositoryInterface
{
    protected $wid;

    protected $cid;

    protected $case;

    protected $workflow;

    protected $test = true;

    protected $model;

    public function findWhere($predicate)
    {
        return $this->model->where($predicate)->get();
    }

    public function setWorkflowId($wid)
    {
        $this->wid = $wid;
    }

    public function setCaseId($cid)
    {
        $this->cid = $cid;
    }

    public function setWorkflow($workflow)
    {
        $this->workflow = $workflow;
        $this->wid = $workflow->id;
    }

    public function setCase($case)
    {
        $this->case = $case;
        $this->cid = $case->id;
    }

    public function getEntity($entity, $id)
    {
        return (new $entity())->find($id);
    }

    public function findEntity($entity, $predicate, $columns = '*', $with = null)
    {
        if ($with)
            return (new $entity())->with($with)->where($predicate)->get($columns)->first();
        return (new $entity())->where($predicate)->get($columns)->first();
    }

    public function findEntities($entity, $predicate, $columns = '*', $with = null)
    {
        if ($predicate && $with)
            return (new $entity())->with($with)->where($predicate)->get($columns);

        if ($predicate && !$with)
            return (new $entity())->where($predicate)->get($columns);

        if (!$predicate && $with)
            return (new $entity())->with($with)->get($columns);

        return (new $entity())->get($columns);
    }

    public function findEntityByOrder($entity, $predicate, $field, $order)
    {
        return (new $entity())->where($predicate)->orderby($field, $order)->first();
    }

    public function findEntitiesByOrder($entity, $predicate, $field, $order)
    {
        if ($predicate)
            return (new $entity())->where($predicate)->orderby($field, $order)->get();
        return (new $entity())->orderby($field, $order)->get();
    }

    public function findCasesByMixed($predicate, $columns, $field, $order, $skip, $limit)
    {
        return BpmsCase::join('bpms_workflows', 'bpms_workflows.id', '=', 'bpms_cases.ws_pro_id')->where($predicate)->skip($skip)->take($limit)->orderby($field, $order)->get($columns);
    }

    public function findEntityByRandom($entity, $predicate)
    {
        return (new $entity())->where($predicate)->inRandomOrder()->first();
    }

    public function countEntity($entity, $predicate)
    {
        return (new $entity())->where($predicate)->count();
    }

    public function createEntity($entity, $data)
    {
        return (new $entity())->create($data)->id;
    }

    public function updateEntity($entity, $predicate, $data, $create = false)
    {
        if ($create) {
            switch ($entity) {
                case DataRepositoryInterface::BPMS_TRANSITION:
                    return \Niyam\Bpms\Model\BpmsTransition::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_STATE:
                    return \Niyam\Bpms\Model\BpmsState::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_GATE:
                    return \Niyam\Bpms\Model\BpmsGate::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_META:
                    return \Niyam\Bpms\Model\BpmsMeta::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_STATE_CONFIG:
                    return \Niyam\Bpms\Model\BpmsStateConfig::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_FORM:
                    return \Niyam\Bpms\Model\BpmsForm::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_TRIGGER:
                    return \Niyam\Bpms\Model\BpmsTrigger::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_FETCH:
                    return \Niyam\Bpms\Model\BpmsFetch::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_VARIABLE:
                    return \Niyam\Bpms\Model\BpmsVariable::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_CASE:
                    return \Niyam\Bpms\Model\BpmsCase::updateOrCreate($predicate, $data)->id;
                    break;
                case DataRepositoryInterface::BPMS_ACTIVITY:
                    return \Niyam\Bpms\Model\BpmsActivity::updateOrCreate($predicate, $data)->id;
                    break;
                default:
                    return;
            }
        }
        if (isset($data['options'])) {
            $data['options'] = json_encode($data['options']);
        }

        if (isset($data['system_options'])) {
            $data['system_options'] = json_encode($data['system_options']);
        }

        return (new $entity())->where($predicate)->update($data);
    }

    public function deleteEntity($entity, $predicate)
    {
        return (new $entity())->where($predicate)->delete();
    }

    public function deleteNotIn($entity, $predicate, $to_keep)
    {
        return (new $entity())::where($predicate)->whereNotIn('id', $to_keep)->delete();
    }

    public function executeSelectQuery($query)
    {
        try {
            return DB::select($query);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function checkCondition($condition, $vars = null)
    {
        $language = new ExpressionLanguage();
        if (!$condition)
            return false;
        $vars = $vars ?: array();

        try {
            return $language->evaluate($condition, $vars);
        } catch (\Exception $e) {
            return -1;
        }
    }

    public function givePositionOfUser($userId)
    {
        return $userId + 1;
    }

    public function givePositionsOfUser($userId)
    {
        return [$userId + 1, $userId];
    }

    public function giveUsersOfPosition($position, $successor = false)
    {
        if ($position < 2)
            $position = 2;

        if ($successor)
            return [$position - 1, $position];
        return $position - 1;
    }

    public function giveParentPosition($position, $currentUser = null)
    {
    }

    public function isPositionBased($state)
    {
        return $state->meta_user == 1;
    }
}
