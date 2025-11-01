<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
session_destroy();
json_out(['success'=>true]);