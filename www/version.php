<?php
$verFile = __DIR__ . '/../VERSION';
if (is_file($verFile)) {
  echo trim(file_get_contents($verFile));
} else {
  echo "unknown";
}
