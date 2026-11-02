<?php
// Standard-install data for the TEST TEMPLATE only (run by TestDatabase after bin/phill-demo): the shipped
// engineering persona catalog and the shipped character personas, exactly as bin/phill-bootstrap installs them. Never run this against production.
require dirname(__DIR__, 2) . '/bootstrap.php';
if (strpos((string)getenv('DB_DSN'), 'dbname=phillip_test_template') === false) {
    fwrite(STDERR, "seed_template.php runs only against the test template database\n");
    exit(1);
}
Agent\Engineering::install();
Agent\PersonaSheet::install();
