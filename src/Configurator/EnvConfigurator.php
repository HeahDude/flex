<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Configurator;

use Symfony\Flex\Recipe;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class EnvConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $vars, array $options = [])
    {
        $this->write('Added environment variable defaults');

        $this->configureEnvDist($recipe, $vars);
        if (!file_exists(getcwd().'/.env.test')) {
            $this->configurePhpUnit($recipe, $vars);
        }
    }

    public function unconfigure(Recipe $recipe, $vars)
    {
        $this->unconfigureEnvFiles($recipe, $vars);
        $this->unconfigurePhpUnit($recipe, $vars);
    }

    private function configureEnvDist(Recipe $recipe, $vars)
    {
        foreach (['.env.dist', '.env'] as $file) {
            $env = getcwd().'/'.$file;
            if (!is_file($env)) {
                continue;
            }

            if ($this->isFileMarked($recipe, $env)) {
                continue;
            }

            $data = '';
            foreach ($vars as $key => $value) {
                $value = $this->evaluateValue($value);
                if ('#' === $key[0] && is_numeric(substr($key, 1))) {
                    $data .= '# '.$value."\n";

                    continue;
                }

                $value = $this->options->expandTargetDir($value);
                if (false !== strpbrk($value, " \t\n&!\"")) {
                    $value = '"'.str_replace(['\\', '"', "\t", "\n"], ['\\\\', '\\"', '\t', '\n'], $value).'"';
                }
                $data .= "$key=$value\n";
            }
            $data = $this->markData($recipe, $data);
            file_put_contents($env, $data, FILE_APPEND);
        }
    }

    private function configurePhpUnit(Recipe $recipe, $vars)
    {
        foreach (['phpunit.xml.dist', 'phpunit.xml'] as $file) {
            $phpunit = getcwd().'/'.$file;
            if (!is_file($phpunit)) {
                continue;
            }

            if ($this->isFileXmlMarked($recipe, $phpunit)) {
                continue;
            }

            $data = '';
            foreach ($vars as $key => $value) {
                $value = $this->evaluateValue($value);
                if ('#' === $key[0]) {
                    if (is_numeric(substr($key, 1))) {
                        $doc = new \DOMDocument();
                        $data .= '        '.$doc->saveXML($doc->createComment(' '.$value.' '))."\n";
                    } else {
                        $value = $this->options->expandTargetDir($value);
                        $doc = new \DOMDocument();
                        $fragment = $doc->createElement('env');
                        $fragment->setAttribute('name', substr($key, 1));
                        $fragment->setAttribute('value', $value);
                        $data .= '        '.str_replace(['<', '/>'], ['<!-- ', ' -->'], $doc->saveXML($fragment))."\n";
                    }
                } else {
                    $value = $this->options->expandTargetDir($value);
                    $doc = new \DOMDocument();
                    $fragment = $doc->createElement('env');
                    $fragment->setAttribute('name', $key);
                    $fragment->setAttribute('value', $value);
                    $data .= '        '.$doc->saveXML($fragment)."\n";
                }
            }
            $data = $this->markXmlData($recipe, $data);
            file_put_contents($phpunit, preg_replace('{^(\s+</php>)}m', $data.'$1', file_get_contents($phpunit)));
        }
    }

    private function unconfigureEnvFiles(Recipe $recipe, $vars)
    {
        foreach (['.env', '.env.dist'] as $file) {
            $env = getcwd().'/'.$file;
            if (!file_exists($env)) {
                continue;
            }

            $contents = preg_replace(sprintf('{%s*###> %s ###.*###< %s ###%s+}s', "\n", $recipe->getName(), $recipe->getName(), "\n"), "\n", file_get_contents($env), -1, $count);
            if (!$count) {
                continue;
            }

            $this->write(sprintf('Removing environment variables from %s', $file));
            file_put_contents($env, $contents);
        }
    }

    private function unconfigurePhpUnit(Recipe $recipe, $vars)
    {
        foreach (['phpunit.xml.dist', 'phpunit.xml'] as $file) {
            $phpunit = getcwd().'/'.$file;
            if (!is_file($phpunit)) {
                continue;
            }

            $contents = preg_replace(sprintf('{%s*\s+<!-- ###\+ %s ### -->.*<!-- ###- %s ### -->%s+}s', "\n", $recipe->getName(), $recipe->getName(), "\n"), "\n", file_get_contents($phpunit), -1, $count);
            if (!$count) {
                continue;
            }

            $this->write(sprintf('Removed environment variables from %s', $file));
            file_put_contents($phpunit, $contents);
        }
    }

    private function evaluateValue($value)
    {
        if ('%generate(secret)%' === $value) {
            return $this->generateRandomBytes();
        }
        if (preg_match('~^%generate\(secret,\s*([0-9]+)\)%$~', $value, $matches)) {
            return $this->generateRandomBytes($matches[1]);
        }

        return $value;
    }

    private function generateRandomBytes($length = 16)
    {
        return bin2hex(random_bytes($length));
    }
}
