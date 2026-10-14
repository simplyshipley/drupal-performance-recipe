<?php
printf("date=%s | Drupal %s | PHP %s\n", gmdate("c"), \Drupal::VERSION, PHP_VERSION);
printf("REDIS_HOST=%s\n", getenv("REDIS_HOST") === FALSE ? "(unset)" : getenv("REDIS_HOST"));
foreach (["render","page","dynamic_page_cache","data","entity","menu"] as $b) { print $b . " => " . get_class(\Drupal::cache($b)) . PHP_EOL; }
