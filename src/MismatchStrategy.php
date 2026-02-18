<?php

declare(strict_types=1);

namespace PhpDb\Migration;

enum MismatchStrategy: string
{
    /** Skip silently (original behavior). */
    case Ignore = 'ignore';

    /** Log mismatch in MigrationResult warnings, don't alter. */
    case Report = 'report';

    /** Auto-ALTER to match the desired definition. */
    case Alter = 'alter';
}
