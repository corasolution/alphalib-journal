<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A clock on the author, so a returned manuscript cannot sit forever.
 *
 * Reviewers have had deadlines, reminders and escalation since early on; authors have had none.
 * The asymmetry is why a submission returned for corrections had no end state — nothing measured
 * how long it had been out, so nothing could act when the author stopped replying.
 *
 * `author_due_at` is set alongside returned_to_author_at and cleared with it. `author_reminded_at`
 * makes the sweeper idempotent the same way review_assignments.last_reminder_sent_at does: one
 * reminder, then withdrawal, however often the scheduler ticks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->timestamp('author_due_at')->nullable()->after('returned_to_author_at');
            $table->timestamp('author_reminded_at')->nullable()->after('author_due_at');

            // The sweeper's query: everything overdue, journal by journal.
            $table->index(['journal_id', 'author_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['journal_id', 'author_due_at']);
            $table->dropColumn(['author_due_at', 'author_reminded_at']);
        });
    }
};
