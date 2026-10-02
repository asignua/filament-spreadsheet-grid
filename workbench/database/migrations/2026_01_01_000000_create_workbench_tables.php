<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->string('category')->nullable();
            $table->boolean('available')->default(true);
            $table->date('released_on')->nullable();
            $table->boolean('locked')->default(false);
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
    }
};
