#!/usr/bin/env php
<?php
declare(strict_types=1);
$args = array_slice($argv, 1); $output = array_search('output', $args, true);
if ($output === false || !str_starts_with($args[$output + 1], 'file=')) { fwrite(STDERR, 'Missing output file'); exit(1); }
$file = substr($args[$output + 1], 5);
file_put_contents($file, "# method = sample\n# num_warmup = 0\n# save_warmup = 0\nlp__,theta,divergent__,treedepth__,energy__\n-1,0.3,0,2,1\n-2,0.4,0,3,2\n");
echo json_encode($args);
