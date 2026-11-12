<?php

namespace Alpha\Journal\Console\Commands;

use Alpha\Journal\Enums\EditorialDecisionType;
use Alpha\Journal\Enums\JournalRole;
use Alpha\Journal\Enums\SubmissionStage;
use Alpha\Journal\Mail\EmailTemplateRenderer;
use Alpha\Journal\Models\Scopes\JournalScope;
use Alpha\Journal\Models\Submission;
use Alpha\Journal\Notifications\TemplatedNotification;
use Alpha\Journal\Support\Activity;
use Alpha\Journal\Workflow\Exceptions\WorkflowAuthorizationException;
use Alpha\Journal\Workflow\Exceptions\WorkflowTransitionException;
use Alpha\Journal\Workflow\SubmissionWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * The author's half of the clock: one reminder, then withdrawal.
 *
 * Reviewers have had reminders and escalation for months; authors have had neither, which is why
 * a returned manuscript had no end — nothing measured the silence, so nothing could act on it.
 * A submission whose author has stopped replying becomes Withdrawn rather than Declined, because
 * it was never judged.
 *
 * Withdrawal goes through SubmissionWorkflow rather than writing the stage directly, so it
 * produces the same immutable decision row, event and activity entry as any editor's decision —
 * an automatic ending that left no trace would be worse than no ending at all.
 */
class SweepOverdueAuthorRevisions extends Command
{
    protected $signature = 'journal:sweep-overdue-author-revisions';

    protected $description = 'Remind authors whose revision deadline has passed, then withdraw submissions they abandon.';

    public function handle(EmailTemplateRenderer $renderer, SubmissionWorkflow $workflow): int
    {
        $reminded = 0;
        $withdrawn = 0;

        // Runs from the scheduler with no journal in context, so JournalScope would otherwise
        // deny every row (see bootstrap/app.php).
        Submission::withoutGlobalScope(JournalScope::class)
            ->whereNotNull('author_due_at')
            ->whereIn('stage', [
                SubmissionStage::Submission->value,
                SubmissionStage::Screening->value,
                SubmissionStage::Review->value,
            ])
            ->with(['journal', 'submittedBy', 'currentPublication'])
            ->chunkById(100, function ($submissions) use ($renderer, $workflow, &$reminded, &$withdrawn) {
                foreach ($submissions as $submission) {
                    $outcome = $this->sweep($submission, $renderer, $workflow);

                    if ($outcome === 'reminded') {
                        $reminded++;
                    } elseif ($outcome === 'withdrawn') {
                        $withdrawn++;
                    }
                }
            });

        $this->info("Reminded {$reminded} author(s); withdrew {$withdrawn} abandoned submission(s).");

        return self::SUCCESS;
    }

    private function sweep(Submission $submission, EmailTemplateRenderer $renderer, SubmissionWorkflow $workflow): ?string
    {
        $journal = $submission->journal;

        if ($journal === null || ! $submission->author_due_at->isPast()) {
            return null;
        }

        // Nothing is withdrawn on the deadline itself. The author gets a reminder and a grace
        // period first — losing a paper to a missed email is not a workflow, it is a trap.
        if ($submission->author_reminded_at === null) {
            $this->notify($submission, $renderer, 'submission.author_revision_overdue');
            $submission->update(['author_reminded_at' => now()]);

            return 'reminded';
        }

        $graceDays = (int) $journal->setting('author_grace_days', 7);

        if ($submission->author_reminded_at->addDays($graceDays)->isFuture()) {
            return null;
        }

        return $this->withdraw($submission, $workflow, $graceDays);
    }

    private function withdraw(Submission $submission, SubmissionWorkflow $workflow, int $graceDays): ?string
    {
        // Attributed to a journal-wide Editor, which is the role recordDecision() will accept.
        $actor = $submission->journal->usersWithRole(JournalRole::Editor)->first()
            ?? $submission->journal->usersWithRole(JournalRole::JournalManager)->first();

        // No editor to attribute it to means the journal has a staffing problem; silently
        // withdrawing an author's paper under nobody's name would be the wrong way to surface it.
        if ($actor === null) {
            $this->warn("Submission #{$submission->id}: no editor to attribute a withdrawal to; left open.");

            return null;
        }

        try {
            $workflow->recordDecision(
                $submission,
                $actor,
                EditorialDecisionType::Withdraw,
                "Withdrawn automatically: the revision deadline passed and there was no response within {$graceDays} days of the reminder.",
            );
        } catch (WorkflowTransitionException|WorkflowAuthorizationException $e) {
            $this->warn("Submission #{$submission->id}: {$e->getMessage()}");

            return null;
        }

        Activity::log('submission.withdrawn_automatically', $submission, 'No author response after the revision deadline.');

        return 'withdrawn';
    }

    private function notify(Submission $submission, EmailTemplateRenderer $renderer, string $key): void
    {
        $journal = $submission->journal;
        $locale = $journal->primary_locale;

        $rendered = $renderer->render($journal, $key, $locale, [
            'journal_title' => $journal->title->get($locale, 'en') ?? $journal->path,
            'author_name' => $submission->submittedBy?->name ?? 'Author',
            'submission_title' => $submission->currentPublication?->title?->get($locale, 'en')
                ?? "Submission #{$submission->id}",
            'due_date' => $submission->author_due_at->toFormattedDateString(),
        ]);

        if ($rendered === null || $submission->submittedBy === null) {
            return;
        }

        Notification::send($submission->submittedBy, new TemplatedNotification($rendered, $key));
    }
}
