<?php

// Prints the web installer's setup code, creating it if needed. Run by entrypoint.sh
// while the app isn't installed, so the code shows up in the container log.

require __DIR__ . '/../vendor/autoload.php';

$code = \App\Helpers\SetupCode::ensure();
if ($code === null) {
    fwrite(STDERR, "could not write the setup code file\n");
    exit(1);
}

echo $code;
