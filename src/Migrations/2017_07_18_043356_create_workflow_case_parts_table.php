<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWorkflowCasePartsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_case_parts', function (Blueprint $table) {
           $table->increments('id');
            $table->integer('case_id');
            $table->string('from');
            $table->string('to')->nullable();
            $table->string('state')->nullable();
            $table->string('status')->nullable();
            $table->integer('form_id')->nullable();                                    
            $table->integer('user_from');
            $table->integer('user_current');            
            $table->integer('transition_id');
            $table->string('gate_id')->nullable();
            $table->text('options')->nullable();
            $table->text('system_options')->nullable();   
            $table->timestamps();
            $table->softDeletes();		            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('bpms_case_parts');
    }
}
