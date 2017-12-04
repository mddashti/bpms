<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWorkflowsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_workflows', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('ws_id')->nullable();
            $table->string('name');
            $table->string('wid')->nullable();
            $table->text('wxml');
            $table->longText('wsvg')->nullable();
            $table->integer('user_id');
            $table->integer('type')->nullable();
            $table->string('status')->default('created');
            $table->string('state')->nullable();
            $table->boolean('is_parsed')->default(false);
            $table->text('description')->nullable();
            $table->text('options')->nullable();
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
        Schema::dropIfExists('bpms_workflows');
    }
}
