<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWorkflowActivitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_activities', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('case_id');
            $table->integer('user_id');                        
            $table->integer('transition_id');
            $table->integer('part_id');            
            $table->integer('type');
            $table->integer('pre');
            $table->string('comment')->nullable();
            $table->text('options')->nullable();
            $table->timestamp('finished_at')-> nullable();	
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('bpms_activities');
    }
}
