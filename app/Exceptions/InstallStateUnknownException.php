<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The installer could not tell whether Docket is installed (the database
 * exists but can't be read). Treated as "installed" for safety: the
 * installer refuses to run rather than risk a second owner account.
 */
class InstallStateUnknownException extends RuntimeException {}
