<?php namespace Niyam\Bpms\Data;


class LaraDataRepository implements DataRepositoryInterface
{

    public function getEntity($entity, $id)
    {
        return (new $entity())->find($id);
    }

    public function findEntity($entity, $predicate, $columns = null, $with = null)
    {
        if ($with)
            return (new $entity())->with($with)->where($predicate)->get($columns)->first();
        return (new $entity())->where($predicate)->get($columns)->first();

    }

    public function findEntities($entity, $predicate, $columns = null, $with = null)
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
        return (new $entity())->where($predicate)->orderby($field, $order)->get();
    }

    public function findCasesByMixed($predicate, $columns, $field, $order, $skip, $limit)
    {
        return \Bpms\Model\BpmsCase::join('workflows', 'workflows.id', '=', 'workflow_cases.ws_pro_id')->where($predicate)->skip($skip)->take($limit)->orderby($field, $order)->get($columns);
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
        // if (isset($data['options'])) {
        //     $data['options'] = json_encode($data['options']);
        // }
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
}
