<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsActivitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms.bpms_activities', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('case_id');
            $table->integer('original_case_id')->default(0);
            $table->unsignedTinyInteger('meta_type')->nullable();                        
            $table->integer('user_id');  
            $table->integer('position_id')->nullable();                                              
            $table->integer('transition_id');
            $table->integer('part_id');            
            $table->integer('type');
            $table->integer('pre');
            $table->string('comment')->nullable();
            $table->json('options')->nullable();
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
        Schema::dropIfExists('bpms.bpms_activities');
    }
}
