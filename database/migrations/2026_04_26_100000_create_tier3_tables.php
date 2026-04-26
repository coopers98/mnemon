<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Item 11: Knowledge Graph — entity nodes
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type'); // person, project, decision, system, concept
            $table->string('source_page')->nullable(); // wiki page that first defined this entity
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Item 11: Knowledge Graph — typed edges between wiki pages
        Schema::create('entity_relationships', function (Blueprint $table) {
            $table->id();
            $table->string('from_page');
            $table->string('to_page');
            $table->string('edge_type'); // uses, depends-on, contradicts, caused, references
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('from_page')->references('name')->on('wiki_pages')->onDelete('cascade');
            $table->foreign('to_page')->references('name')->on('wiki_pages')->onDelete('cascade');
            $table->index(['from_page', 'edge_type']);
            $table->index(['to_page', 'edge_type']);
            $table->unique(['from_page', 'to_page', 'edge_type']);
        });

        // Item 13: Self-Healing Lint — action audit log
        Schema::create('wiki_lint_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('page_id')->nullable();
            $table->string('action'); // prune_related, archive_empty, queue_orphan
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->timestamp('performed_at');
            $table->timestamps();
        });

        // Item 15: Multi-Agent Mesh Sync — revision audit log
        Schema::create('wiki_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('page_name');
            $table->unsignedInteger('revision');
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->string('agent_id')->nullable();
            $table->timestamp('written_at');
            $table->timestamps();

            $table->unique(['page_name', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_page_revisions');
        Schema::dropIfExists('wiki_lint_actions');
        Schema::dropIfExists('entity_relationships');
        Schema::dropIfExists('entities');
    }
};
