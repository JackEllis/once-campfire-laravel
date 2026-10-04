<?php

namespace App\Support;

/** Rails stores timestamps with six fractional digits, including pagination cursors. */
final class SQLiteGrammar extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }
}
