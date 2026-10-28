<?php
// The only project initialization entry point, for every SAPI and executable.
require_once __DIR__ . '/classes/Agent/Runtime.php';
\Agent\Runtime::boot(__DIR__);
