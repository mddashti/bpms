<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsCasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_cases', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('ws_pro_id');
            $table->integer('user_creator')->default(0);
            $table->integer('user_from')->default(0);
            $table->integer('user_current')->default(0);
            $table->integer('form_id')->nullable();                        
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('state')->nullable();
            $table->string('status')->default('created');
            $table->integer('activity_id')->default(0);
            $table->json('options')->nullable();
            $table->json('system_options')->nullable();            
            $table->timestamps();
            $table->timestamp('finished_at')->nullable();
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
        Schema::dropIfExists('bpms_cases');
    }
}
