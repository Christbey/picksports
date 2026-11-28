<?php

use App\Support\Processes\InferenceProcessEnvironment;
use Symfony\Component\Process\Process;

it('removes ambient credentials module injection and request metadata from the actual child', function () {
    $names = ['PICKSPORTS_INFERENCE_ENV_SENTINEL', 'AWS_ACCESS_KEY_ID', 'DB_PASSWORD', 'PYTHONPATH', 'HTTP_AUTHORIZATION'];
    $original = [];
    foreach ($names as $name) {
        $original[$name] = [getenv($name), $_ENV[$name] ?? null, array_key_exists($name, $_ENV), $_SERVER[$name] ?? null, array_key_exists($name, $_SERVER)];
        putenv($name.'=synthetic-inference-sentinel');
        $_ENV[$name] = 'synthetic-inference-sentinel';
        $_SERVER[$name] = 'synthetic-inference-sentinel';
    }
    try {
        $script = <<<'CODE'
$names = ['PICKSPORTS_INFERENCE_ENV_SENTINEL','AWS_ACCESS_KEY_ID','DB_PASSWORD','HTTP_AUTHORIZATION'];
$present = array_values(array_filter($names, fn ($name) => getenv($name) !== false));
echo json_encode(['unexpected_names'=>$present,'python_path'=>getenv('PYTHONPATH'),'user_site'=>getenv('PYTHONNOUSERSITE'),'utf8'=>getenv('PYTHONUTF8'),'path_available'=>getenv('PATH') !== false], JSON_THROW_ON_ERROR);
CODE;
        $process = new Process([PHP_BINARY, '-r', $script]);
        $process->setEnv(InferenceProcessEnvironment::forPython('/synthetic/owned-package/src'));
        $process->setTimeout(5);
        $process->mustRun();
        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'unexpected_names' => [], 'python_path' => '/synthetic/owned-package/src', 'user_site' => '1', 'utf8' => '1', 'path_available' => true,
        ]);
    } finally {
        foreach ($original as $name => [$value, $envValue, $hadEnv, $serverValue, $hadServer]) {
            putenv($value === false ? $name : $name.'='.$value);
            if ($hadEnv) {
                $_ENV[$name] = $envValue;
            } else {
                unset($_ENV[$name]);
            }
            if ($hadServer) {
                $_SERVER[$name] = $serverValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }
});

it('preserves explicit numerical library thread controls without restoring the ambient environment', function () {
    $previous = getenv('OMP_NUM_THREADS');
    putenv('OMP_NUM_THREADS=2');
    try {
        $process = new Process([PHP_BINARY, '-r', "echo json_encode(['threads'=>getenv('OMP_NUM_THREADS'),'user_site'=>getenv('PYTHONNOUSERSITE')], JSON_THROW_ON_ERROR);"]);
        $process->setEnv(InferenceProcessEnvironment::forPython('/synthetic/owned-package/src'));
        $process->setTimeout(5);
        $process->mustRun();
        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['threads' => '2', 'user_site' => '1']);
    } finally {
        putenv($previous === false ? 'OMP_NUM_THREADS' : 'OMP_NUM_THREADS='.$previous);
    }
});
