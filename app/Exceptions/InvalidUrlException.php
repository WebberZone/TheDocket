<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A URL that cannot be saved: malformed, too long, or not http(s). The
 * message is written for the person who typed it.
 */
class InvalidUrlException extends InvalidArgumentException {}
