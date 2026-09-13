<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;

/**
 * The bundle used packages it only listed in require-dev: the serializer (API
 * controllers, normalizers), the mailer and Twig (newsletters) and YAML (its
 * own config/services.yaml). A host that did not happen to install them could
 * not build its container.
 *
 * This asks Composer rather than a hand-kept list: every package that owns a
 * namespace the bundle imports -- plus the two runtime needs imports do not
 * show -- must be installed without require-dev.
 */
final class RuntimeDependenciesTest extends TestCase
{
    /**
     * Needed at runtime without a matching `use` statement in src/.
     */
    private const IMPLICIT_RUNTIME_PACKAGES = [
        // EscalatedBundle loads config/services.yaml and config/routes.yaml.
        'symfony/yaml' => 'the bundle loads its YAML service and routing configuration',
        // NewsletterRenderer autowires Twig\Environment, which TwigBundle provides.
        'symfony/twig-bundle' => 'the Twig environment service the newsletter renderer injects',
    ];

    public function testEveryPackageTheBundleUsesIsARuntimeDependency(): void
    {
        $installed = $this->installed();
        $devOnly = array_flip($installed['dev-package-names'] ?? []);
        self::assertNotEmpty($devOnly, 'composer install must have run with dev dependencies');

        $prefixes = $this->namespacePrefixes($installed['packages'] ?? []);

        $needed = self::IMPLICIT_RUNTIME_PACKAGES;
        foreach ($this->importedClasses() as $class => $file) {
            $package = $this->owningPackage($class, $prefixes);
            if (null !== $package && !isset($needed[$package])) {
                $needed[$package] = 'imported by '.$file;
            }
        }

        $undeclared = array_intersect_key($needed, $devOnly);
        ksort($undeclared);

        self::assertSame([], $undeclared, "these packages are needed at runtime but only installed through require-dev:\n"
            .implode("\n", array_map(
                static fn (string $package, string $why): string => sprintf('  %s (%s)', $package, $why),
                array_keys($undeclared),
                $undeclared,
            )));
    }

    /**
     * @return array<string, mixed>
     */
    private function installed(): array
    {
        $path = \dirname(__DIR__, 2).'/vendor/composer/installed.json';
        self::assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $packages
     *
     * @return array<string, string> namespace prefix => package name, longest prefix first
     */
    private function namespacePrefixes(array $packages): array
    {
        $prefixes = [];
        foreach ($packages as $package) {
            foreach (array_keys($package['autoload']['psr-4'] ?? []) as $prefix) {
                if ('' !== $prefix) {
                    $prefixes[$prefix] = $package['name'];
                }
            }
        }
        uksort($prefixes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $prefixes;
    }

    /**
     * @param array<string, string> $prefixes
     */
    private function owningPackage(string $class, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix => $package) {
            if (str_starts_with($class, $prefix)) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> imported class => first file that imports it
     */
    private function importedClasses(): array
    {
        $root = \dirname(__DIR__, 2);
        $imports = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            preg_match_all('/^use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+\w+)?\s*;/m', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $class) {
                if (str_starts_with($class, 'Escalated\\Symfony\\')) {
                    continue;
                }
                $imports[$class] ??= substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        return $imports;
    }
}
