<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

if (empty($_SESSION['user'])) {
  json_out([]);
}
json_out($_SESSION['user']);