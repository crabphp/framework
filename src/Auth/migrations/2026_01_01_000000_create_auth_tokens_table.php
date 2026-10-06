<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('auth_tokens', function (Table $table) {
            $table->id();
            $table->string('owner_id', 64)->index();
            $table->string('name')->default('');
            $table->string('token_hash', 64)->unique();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('auth_tokens');
    }
};
