<?php

namespace CodingPartners\TranslaGenius\Exceptions;

use RuntimeException;

/**
 * The base of every exception this package defines: catch it to handle any
 * of them in one place.
 *
 * TranslaGeniusException
 * ├── TranslationFailedException          a translation couldn't be made (isRetryable() says whether to try again)
 * │   ├── AllProvidersFailedException     every member of a fallback chain failed
 * │   ├── PairsFailedException            some pairs of a job failed; the others were saved
 * │   ├── RecordBusyException             the record stayed busy with another job through every re-queue
 * │   └── TranslationConfigurationException  the config is wrong; never retryable
 * ├── InvalidTranslationJobException      a job class doesn't implement Contracts\TranslationJob
 * ├── StaleReviewException                approving a review whose source changed
 * ├── ReviewConflictException             approving over a hand-edited translation
 * └── InvalidReviewStateException         deciding a review that isn't pending
 */
abstract class TranslaGeniusException extends RuntimeException {}
