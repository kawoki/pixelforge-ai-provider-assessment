<?php

declare(strict_types=1);

namespace App\Exceptions\Pricing;

use InvalidArgumentException;

/**
 * The configured (or snapshotted) rate card is malformed or cannot price exactly.
 */
final class InvalidRateCardException extends InvalidArgumentException {}
