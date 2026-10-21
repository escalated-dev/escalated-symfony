<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every page name this bundle renders resolves to a component in
 * `@escalated-dev/escalated`.
 *
 * Inertia resolves a page name to a component, and a name with nothing behind
 * it is not an error: the response is a 200 and the panel comes up blank. It
 * reads as a permissions problem or an empty dataset, which is why four screens
 * shipped that way across this portfolio before anyone noticed.
 *
 * Neither repo's tests can see it on their own -- a controller test asserts a
 * status, and the frontend never hears the name. This is the comparison, run
 * against the manifest the frontend package publishes and this repo vendors at
 * tests/Fixtures/escalated-pages.json.
 *
 * Adding a screen goes: component into the frontend, frontend release, refresh
 * the fixture, then render the name here. In that order, or it ships blank.
 */
final class PageNameParityTest extends TestCase
{
    /** Where the manifest says the names came from. */
    private const MANIFEST = __DIR__.'/Fixtures/escalated-pages.json';

    /**
     * Pages the frontend ships that this bundle does not render yet: the
     * ledger of what is left. Rendering one means taking it off this list,
     * and the test below fails until that happens, so the list stays exact.
     */
    private const NOT_YET_RENDERED = [
        'Escalated/Admin/AuditLog/Index',
        'Escalated/Admin/Automations/Form',
        'Escalated/Admin/BusinessHours/Form',
        'Escalated/Admin/BusinessHours/Index',
        'Escalated/Admin/CustomFields/Form',
        'Escalated/Admin/CustomFields/Index',
        'Escalated/Admin/CustomObjects/Form',
        'Escalated/Admin/CustomObjects/Index',
        'Escalated/Admin/CustomObjects/Records',
        'Escalated/Admin/Import/Connect',
        'Escalated/Admin/Import/Index',
        'Escalated/Admin/Import/Mapping',
        'Escalated/Admin/Import/Progress',
        'Escalated/Admin/Import/Review',
        'Escalated/Admin/Plugins/Index',
        'Escalated/Admin/Reports/AgentDetail',
        'Escalated/Admin/Reports/AgentMetrics',
        'Escalated/Admin/Reports/AgentRanking',
        'Escalated/Admin/Reports/Cohorts',
        'Escalated/Admin/Reports/Comparison',
        'Escalated/Admin/Reports/CsatReport',
        'Escalated/Admin/Reports/Dashboard',
        'Escalated/Admin/Reports/ResolutionTimes',
        'Escalated/Admin/Reports/ResponseTimes',
        'Escalated/Admin/Reports/SlaReport',
        'Escalated/Admin/Reports/SlaTrends',
        'Escalated/Admin/Roles/Form',
        'Escalated/Admin/Roles/Index',
        'Escalated/Admin/Settings/CsatSettings',
        'Escalated/Admin/Settings/DataRetention',
        'Escalated/Admin/Settings/DatabaseConnection',
        'Escalated/Admin/Settings/EmailSettings',
        'Escalated/Admin/Settings/Sandbox',
        'Escalated/Admin/Settings/SsoSettings',
        'Escalated/Admin/Settings/TwoFactor',
        'Escalated/Admin/Statuses/Form',
        'Escalated/Admin/Statuses/Index',
        'Escalated/Admin/Workflows/Builder',
        'Escalated/Auth/TwoFactorChallenge',
        'Escalated/Error',
        'Escalated/Plugin/Page',
    ];

    public function testEveryRenderedPageNameResolvesToAComponent(): void
    {
        $shipped = $this->shippedPages();
        $rendered = $this->renderedPages();

        self::assertNotEmpty($rendered, 'found no page names at all, which means this test is not looking where it should');

        $missing = array_values(array_diff(array_keys($rendered), $shipped));
        sort($missing);

        self::assertSame([], $missing, $this->explain($missing, $rendered));
    }

    public function testTheNotYetRenderedListIsExact(): void
    {
        $unrendered = array_values(array_diff($this->shippedPages(), array_keys($this->renderedPages())));
        sort($unrendered);
        $listed = self::NOT_YET_RENDERED;
        sort($listed);

        self::assertSame($listed, $unrendered, 'NOT_YET_RENDERED must name exactly the shipped pages no controller renders');
    }

    public function testTheManifestIsPresentAndLooksLikeOne(): void
    {
        // A fixture that has gone missing or empty would make the test above
        // pass by comparing against nothing.
        self::assertFileExists(self::MANIFEST);
        self::assertGreaterThan(50, count($this->shippedPages()));
    }

    /**
     * @return list<string>
     */
    private function shippedPages(): array
    {
        $manifest = json_decode((string) file_get_contents(self::MANIFEST), true, flags: JSON_THROW_ON_ERROR);

        return $manifest['pages'];
    }

    /**
     * Page names rendered anywhere in src/, mapped to the files that render
     * them, so a failure can name the file rather than only the string.
     *
     * @return array<string, list<string>>
     */
    private function renderedPages(): array
    {
        $found = [];
        $root = dirname(__DIR__).'/src';

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (!preg_match_all("/'(Escalated\/[A-Za-z0-9\/_]+)'/", $source, $matches)) {
                continue;
            }

            foreach ($matches[1] as $page) {
                $found[$page][] = basename($file->getPathname());
            }
        }

        return $found;
    }

    /**
     * @param list<string>                $missing
     * @param array<string, list<string>> $rendered
     */
    private function explain(array $missing, array $rendered): string
    {
        if ([] === $missing) {
            return '';
        }

        $lines = ['these page names have no component in @escalated-dev/escalated, so they render a blank panel:'];

        foreach ($missing as $page) {
            $lines[] = sprintf('  %s  (%s)', $page, implode(', ', array_unique($rendered[$page])));
        }

        $lines[] = '';
        $lines[] = 'Either the name is wrong, or the component has not been released yet.';
        $lines[] = 'If it has: refresh tests/Fixtures/escalated-pages.json from the package.';

        return implode("\n", $lines);
    }
}
