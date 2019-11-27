<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsTimersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_timers', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('case_id');
            $table->integer('state_id')->nullable();
            $table->string('state_wid')->nullable();
            $table->string('base_state_wid')->nullable();
            $table->timestamp('unsuspend_at')->nullable();
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
        Schema::dropIfExists('bpms_timers');
    }
}
