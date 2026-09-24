<?php

namespace App\Backup\Exceptions;

use RuntimeException;

/** Thrown by DumpValidator when a dump contains something that could act outside its intended tmp database. */
class InvalidDumpException extends RuntimeException {}
