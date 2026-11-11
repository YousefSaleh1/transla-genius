<?php

namespace CodingPartners\TranslaGenius\Console\Commands;

use CodingPartners\TranslaGenius\Review\ReviewManager;
use Illuminate\Console\Command;

/** Delete decided (approved, rejected, superseded) reviews older than N days. */
class ReviewPruneCommand extends Command
{
    protected $signature = 'translagenius:review:prune
        {--days=90 : Delete decided reviews last updated more than this many days ago}';

    protected $description = 'Delete old decided translation reviews (pending ones are never deleted)';

    public function handle(ReviewManager $reviews): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be a positive integer.');

            return self::FAILURE;
        }

        $deleted = $reviews->prune($days);
        $this->info("Deleted {$deleted} decided review(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
