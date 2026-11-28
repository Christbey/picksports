<?php

namespace App\Support\Processes;

/** Least-privilege child environment; this does not sandbox executable model artifacts. */
final class InferenceProcessEnvironment
{
    /** @return array<string, string|false> */
    public static function forPython(string $pythonPath): array
    {
        $environment = [];
        foreach (array_keys(array_merge(getenv(), $_ENV, $_SERVER)) as $name) {
            if (is_string($name)) {
                // Symfony Process merges its default environment at start; false removes a key.
                $environment[$name] = false;
            }
        }
        $environment['PATH'] = getenv('PATH') ?: '/usr/bin:/bin';
        $environment['TMPDIR'] = sys_get_temp_dir();
        $environment['LANG'] = 'C';
        $environment['PYTHONPATH'] = $pythonPath;
        $environment['PYTHONUTF8'] = '1';
        $environment['PYTHONNOUSERSITE'] = '1';
        $environment['PYTHONDONTWRITEBYTECODE'] = '1';

        // Retain only explicit numerical-library execution controls, never app/provider credentials.
        foreach (['OMP_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS', 'VECLIB_MAXIMUM_THREADS'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }
        if (PHP_OS_FAMILY === 'Windows' && ($systemRoot = getenv('SystemRoot')) !== false) {
            $environment['SystemRoot'] = $systemRoot;
        }

        return $environment;
    }
}
