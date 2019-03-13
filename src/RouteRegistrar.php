<?php
namespace Niyam\Bpms;

use Illuminate\Contracts\Routing\Registrar as Router;

class RouteRegistrar
{
    protected $router;

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    public function all()
    {
        $this->forCase();
        $this->forWorkflow();
    }

    public function forCase()
    {
        $this->router->group(['prefix' => 'case'], function ($router) {

            $router->get('/next/{case}', ['uses' => 'CaseController@next']);

            $router->get('{case}/user/{state}', 'CaseController@getCaseUser');

            $router->get('{case}/status', 'CaseController@getStatus');

            $router->get('{case}/condition/{gate}', 'CaseController@getCaseCondition');

            $router->get('next/{case}', 'CaseController@next');

            $router->get('parts/{case}', 'CaseController@getParts');

            $router->get('{case}/pic', 'CaseController@pic');

            $router->get('{case}/test', 'CaseController@testMeDude');

            $router->get('{case}/subprocess/{state}', 'CaseController@getSubprocessMeta');

            $router->post('{case}/user/{state}', 'CaseController@postCaseUser');

            $router->post('{case}/condition/{gate}', 'CaseController@postCaseCondition');

            $router->post('back/{case}', 'CaseController@goBack');

            $router->post('{case}/testpost', 'CaseController@testpost');

            $router->post('backparts/{case}', 'CaseController@goBackParted');
        });
    }

    public function forWorkflow()
    {
        $this->router->prefix('workflows')->group(function ($router) {

            $router->get('all', 'WorkflowController@getWorkflows')->name('workflow.all');

            $router->get('parsed', 'WorkflowController@getWorkflowsParsed');

            $router->get('{workflow}/data', 'WorkflowController@getWorkflowdata');

            $router->get('next/{workflow}/{form}', 'WorkflowController@getNext');

            $router->get('{workflow}/status', 'WorkflowController@getStatus');

            $router->get('{workflow}/user/{state}', 'WorkflowController@getWorkflowUser');

            $router->get('{workflow}/condition/{gate}', 'WorkflowController@getWorkflowCondition');

            $router->get('{workflow}/subprocess/{state}', 'WorkflowController@getSubprocessMeta');

            $router->get('{workflow}/test/nextstep/{state}', 'WorkflowController@getNextStep');

            $router->post('{workflow}/data', 'WorkflowController@postWorkflowdata');

            $router->post('{workflow}/parse', 'WorkflowController@postWorkflowparse');

            $router->post('{workflow}/user/{state}', 'WorkflowController@postWorkflowUser');

            $router->post('{workflow}/condition/{gate}', 'WorkflowController@postWorkflowCondition');

            $router->post('{workflow}/subprocess', 'WorkflowController@postSubprocessMeta');
        });
    }
}