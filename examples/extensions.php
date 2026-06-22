<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\Environment;

$env = Environment::standard();

example_show('cel.bind', example_eval($env, 'cel.bind(x, 1, x + 2)'));
example_show('base64 encode', example_eval($env, 'base64.encode(b"hello")'));
example_show('base64 decode', example_eval($env, 'base64.decode("aGVsbG8=")'));
example_show('math ceil', example_eval($env, 'math.ceil(1.2)'));
example_show('math greatest', example_eval($env, 'math.greatest([5.4, 10, 3u, -5.0, 3.5])'));
example_show('strings.quote', example_eval($env, 'strings.quote("first\nsecond")'));
example_show('replace helper', example_eval($env, '"one fish".replace("fish", "bird")'));
example_show('reverse helper', example_eval($env, '"desserts".reverse()'));
example_show('net.IP', example_eval($env, "string(ip('192.168.0.1'))"));
example_show('net.IP predicate', example_eval($env, "ip('127.0.0.1').isLoopback()"));
example_show('net.CIDR contains', example_eval($env, "cidr('192.168.0.0/24').containsIP('192.168.0.1')"));
example_show('net.CIDR masked', example_eval($env, "string(cidr('192.168.0.1/24').masked())"));
