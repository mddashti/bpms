<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBpmsElementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bpms_elements', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('form_id');
            $table->string('element_name');
            $table->string('element_type');
            $table->integer('variable_id')->nullable()->comment('این فیلد برای المانهای دارای تریگر باید خالی باشد');
            $table->text('options')->nullable();
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
        Schema::dropIfExists('bpms_elements');
    }
}
