<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use RuntimeException;

/**
 * The user hit a per-user fairness limit. The message is written for users.
 */
final class GenerationLimitReachedException extends RuntimeException {}
