<?php

namespace App\Exceptions;

use RuntimeException;

class MissingTeamContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No team context is established. Team-scoped operations require Teams::initialize() first.');
    }
}
