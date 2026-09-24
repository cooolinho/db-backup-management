<?php

namespace App\Backup\Exceptions;

use RuntimeException;

/** Thrown when a backup/restore/swap/drop is attempted while another one is already running. */
class OperationInProgressException extends RuntimeException {}
