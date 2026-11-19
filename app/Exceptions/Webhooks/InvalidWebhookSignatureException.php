<?php

declare(strict_types=1);

namespace App\Exceptions\Webhooks;

use RuntimeException;

/**
 * A webhook request that we cannot prove came from Pixelforge, or that is too old to trust.
 */
final class InvalidWebhookSignatureException extends RuntimeException {}
