<?php

namespace Tests\Feature;

use Alpha\Journal\Enums\EditorialDecisionType;
use Alpha\Journal\Enums\JournalRole;
use Alpha\Journal\Enums\SubmissionStage;
use Alpha\Journal\Models\Journal;
use Alpha\Journal\Models\JournalSetting;
use Alpha\Journal\Models\Publication;
use Alpha\Journal\Models\Site;
use Alpha\Journal\Models\Submission;
use Alpha\Journal\Models\User;
use Alpha\Journal\Workflow\Exceptions\WorkflowTransitionException;
use Alpha\Journal\Workflow\SubmissionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Every path has an end.
 *
 * A manuscript could previously revolve forever: revisions were an unbounded self-loop, and a
 * submission returned to an author who never replied had no clock and nowhere to go. These tests
 * pin down the three things that close those holes — a round cap, an author deadline, and a
 * Withdrawn terminal that is not a rejection.
 */
class LoopTerminationTest extends TestCase
{
    use RefreshDatabase;

    private Journal $journal;

    private User $editor;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::create(['title' => 'BIU Journal']);
        $this->journal = Journal::create([
            'site_id' => $site->id, 'path' => 'biu', 'title' => ['en' => 'BIU Journal of Research'],
            'primary_locale' => 'en', 'is_enabled' => true,
        ]);

        $this->editor = User::factory()->create();
        $this->editor->journalRoles()->create(['journal_id' => $this->journal->id, 'role' => JournalRole::Editor->value]);

        $this->author = User::factory()->create();
        $this->author->journalRoles()->create(['journal_id' => $this->journal->id, 'role' => JournalRole::Author->value]);
    }

    private function setting(string $name, string $value): void
    {
        JournalSetting::updateOrCreate(
            ['journal_id' => $this->journal->id, 'name' => $name, 'locale' => ''],
            ['value' => $value],
        );
    }

    private function submission(string $stage = 'review'): Submission
    {
        $submission = Submission::create([
            'journal_id' => $this->journal->id,
            'submitted_by' => $this->author->id,
            'stage' => $stage,
            'submitted_at' => now(),
        ]);

        $publication = Publication::create([
            'submission_id' => $submission->id,
            'version' => 1,
            'title' => ['en' => 'A paper about something'],
            'status' => 'draft',
        ]);
        $submission->update(['current_publication_id' => $publication->id]);

        $submission->stageAssignments()->create([
            'user_id' => $this->editor->id,
            'stage' => $stage,
            'role' => JournalRole::Editor->value,
        ]);

        return $submission;
    }

    private function decide(Submission $submission, EditorialDecisionType $decision): void
    {
        app(SubmissionWorkflow::class)->recordDecision($submission->refresh(), $this->editor, $decision);
    }

    // ---------- Round cap ----------

    public function test_revisions_are_unlimited_unless_the_journal_caps_them(): void
    {
        // The regression guard: journals running before the cap existed keep their behaviour.
        $submission = $this->submission();

        foreach (range(1, 5) as $i) {
            $submission->reviewRounds()->create(['round_number' => $i]);
            $this->decide($submission, EditorialDecisionType::RequestRevisions);
        }

        $this->assertSame(SubmissionStage::Review, $submission->refresh()->stage);
    }

    public function test_the_cap_stops_another_revision_once_reached(): void
    {
        $this->setting('max_revision_rounds', '3');
        $submission = $this->submission();

        foreach (range(1, 3) as $i) {
            $submission->reviewRounds()->create(['round_number' => $i]);
        }

        try {
            $this->decide($submission, EditorialDecisionType::RequestRevisions);
            $this->fail('A fourth revision was allowed past a cap of three.');
        } catch (WorkflowTransitionException $e) {
            $this->assertStringContainsString('Accept or decline', $e->getMessage());
        }
    }

    public function test_the_cap_still_leaves_accept_and_decline_available(): void
    {
        // The whole point of the cap: it forces a verdict, it does not trap the submission.
        $this->setting('max_revision_rounds', '2');
        $submission = $this->submission();
        $submission->reviewRounds()->create(['round_number' => 1]);
        $submission->reviewRounds()->create(['round_number' => 2]);

        $this->decide($submission, EditorialDecisionType::Accept);

        $this->assertSame(SubmissionStage::Copyediting, $submission->refresh()->stage);
    }

    public function test_below_the_cap_a_revision_is_still_allowed(): void
    {
        $this->setting('max_revision_rounds', '3');
        $submission = $this->submission();
        $submission->reviewRounds()->create(['round_number' => 1]);

        $this->decide($submission, EditorialDecisionType::RequestRevisions);

        $this->assertSame(SubmissionStage::Review, $submission->refresh()->stage);
    }

    // ---------- Author clock ----------

    public function test_returning_a_manuscript_starts_the_author_clock(): void
    {
        $this->setting('author_correction_days', '14');
        $submission = $this->submission('submission');

        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);

        $submission->refresh();
        $this->assertNotNull($submission->author_due_at);
        $this->assertEqualsWithDelta(14, now()->diffInDays($submission->author_due_at), 1);
    }

    public function test_minor_and_major_revisions_get_different_clocks(): void
    {
        $this->setting('author_minor_revision_days', '14');
        $this->setting('author_major_revision_days', '45');

        $minor = $this->submission();
        $this->decide($minor, EditorialDecisionType::RequestRevisions);
        $this->assertEqualsWithDelta(14, now()->diffInDays($minor->refresh()->author_due_at), 1);

        $major = $this->submission();
        $this->decide($major, EditorialDecisionType::Resubmit);
        $this->assertEqualsWithDelta(45, now()->diffInDays($major->refresh()->author_due_at), 1);
    }

    public function test_taking_the_manuscript_back_clears_the_clock(): void
    {
        $this->setting('author_correction_days', '14');
        $submission = $this->submission('submission');
        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);
        $this->assertNotNull($submission->refresh()->author_due_at);

        $this->decide($submission, EditorialDecisionType::Decline);

        $this->assertNull($submission->refresh()->author_due_at);
    }

    public function test_zero_days_means_no_clock_at_all(): void
    {
        $this->setting('author_correction_days', '0');
        $submission = $this->submission('submission');

        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);

        $this->assertNull($submission->refresh()->author_due_at);
    }

    // ---------- The sweeper ----------

    public function test_an_overdue_author_is_reminded_before_anything_else_happens(): void
    {
        Notification::fake();
        $this->setting('author_correction_days', '14');
        $submission = $this->submission('submission');
        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);

        $this->travel(20)->days();
        $this->artisan('journal:sweep-overdue-author-revisions')->assertSuccessful();

        $submission->refresh();
        // Reminded, not withdrawn — losing a paper to one missed email would be a trap.
        $this->assertNotNull($submission->author_reminded_at);
        $this->assertSame(SubmissionStage::Submission, $submission->stage);
    }

    public function test_the_reminder_is_sent_only_once(): void
    {
        Notification::fake();
        $this->setting('author_correction_days', '14');
        $submission = $this->submission('submission');
        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);

        $this->travel(20)->days();
        $this->artisan('journal:sweep-overdue-author-revisions');
        $firstReminder = $submission->refresh()->author_reminded_at;

        $this->travel(1)->days();
        $this->artisan('journal:sweep-overdue-author-revisions');

        $this->assertEquals($firstReminder, $submission->refresh()->author_reminded_at);
    }

    public function test_silence_after_the_grace_period_withdraws_it(): void
    {
        Notification::fake();
        $this->setting('author_correction_days', '14');
        $this->setting('author_grace_days', '7');
        $submission = $this->submission('submission');
        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);

        $this->travel(20)->days();
        $this->artisan('journal:sweep-overdue-author-revisions');

        $this->travel(8)->days();
        $this->artisan('journal:sweep-overdue-author-revisions');

        $submission->refresh();
        // Withdrawn, never Declined — nobody judged this work.
        $this->assertSame(SubmissionStage::Withdrawn, $submission->stage);
        $this->assertTrue($submission->stage->isTerminal());
    }

    public function test_withdrawal_leaves_the_same_audit_trail_as_any_decision(): void
    {
        Notification::fake();
        $this->setting('author_correction_days', '14');
        $this->setting('author_grace_days', '7');
        $submission = $this->submission('submission');
        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);

        $this->travel(20)->days();
        $this->artisan('journal:sweep-overdue-author-revisions');
        $this->travel(8)->days();
        $this->artisan('journal:sweep-overdue-author-revisions');

        $decision = $submission->editorialDecisions()->where('decision', 'withdraw')->first();

        $this->assertNotNull($decision, 'An automatic ending that left no trace would be worse than none.');
        $this->assertStringContainsString('deadline passed', $decision->reason);
    }

    public function test_an_author_who_responds_in_time_is_left_alone(): void
    {
        Notification::fake();
        $this->setting('author_correction_days', '14');
        $submission = $this->submission('submission');
        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);

        // The author uploads a replacement manuscript, which takes it back off them.
        $submission->update(['returned_to_author_at' => null, 'author_due_at' => null]);

        $this->travel(60)->days();
        $this->artisan('journal:sweep-overdue-author-revisions');

        $this->assertSame(SubmissionStage::Submission, $submission->refresh()->stage);
    }

    public function test_a_journal_with_no_clock_never_withdraws_anything(): void
    {
        Notification::fake();
        $submission = $this->submission('submission');
        $this->decide($submission, EditorialDecisionType::ReturnToAuthor);

        $this->travel(365)->days();
        $this->artisan('journal:sweep-overdue-author-revisions');

        $this->assertSame(SubmissionStage::Submission, $submission->refresh()->stage);
    }
}
