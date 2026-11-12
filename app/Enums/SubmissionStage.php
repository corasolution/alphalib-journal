<?php

namespace Alpha\Journal\Enums;

/**
 * SUBMISSION -> [SCREENING] -> REVIEW -> COPYEDITING -> PRODUCTION -> PUBLISHED, DECLINED from
 * any stage (§3).
 *
 * Screening is optional and off unless a journal turns it on. Published journal workflows commonly
 * separate the editor-in-chief's first look and the technical/format check from peer review
 * itself; journals that do not want that distinction never see the stage.
 */
enum SubmissionStage: string
{
    case Submission = 'submission';
    case Screening = 'screening';
    case Review = 'review';
    case Copyediting = 'copyediting';
    case Production = 'production';
    case Published = 'published';
    case Declined = 'declined';

    /**
     * Abandoned rather than judged.
     *
     * Distinct from Declined on purpose: declining is an editorial verdict on the work, and
     * recording "the author stopped replying" as one misstates both the journal's view and the
     * author's record. Every published workflow that puts a clock on the author needs somewhere
     * for the clock running out to land.
     */
    case Withdrawn = 'withdrawn';

    /**
     * The stages a submission can end at, for queries that mean "still live".
     *
     * Derived from isTerminal() so adding a terminal stage cannot leave a hand-written
     * [Published, Declined] list behind — which is exactly what adding Withdrawn did.
     *
     * @return array<string>
     */
    public static function terminalValues(): array
    {
        return array_map(
            fn (self $stage) => $stage->value,
            array_filter(self::cases(), fn (self $stage) => $stage->isTerminal()),
        );
    }

    public function isTerminal(): bool
    {
        return $this === self::Published || $this === self::Declined || $this === self::Withdrawn;
    }

    public function label(): string
    {
        return match ($this) {
            self::Submission => 'Submission',
            self::Screening => 'Screening',
            self::Review => 'Review',
            self::Copyediting => 'Copyediting',
            self::Production => 'Production',
            self::Published => 'Published',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
