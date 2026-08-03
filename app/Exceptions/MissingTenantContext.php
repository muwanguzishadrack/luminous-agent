<?php

namespace App\Exceptions;

use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant context is established. Tenant-scoped operations require Tenancy::initialize() first.');
    }
}
